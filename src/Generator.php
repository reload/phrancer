<?php

namespace reload\phrancer;

use Swagger\ApiDeclaration;
use Swagger\ApiDeclaration\Api as ApiDeclarationApi;
use Swagger\ResourceListing;
use Swagger\ResourceListing\Api as ResourceListingApi;
use Swagger\ApiDeclaration\Api\Operation;
use Swagger\ApiDeclaration\Api\Operation\Parameter;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Uri\UriFactory;

class Generator {

    public function __construct() {

    }

    public function generate($options) {
        $inputUri = UriFactory::factory($options['inputFile']);
        $resource = new ResourceListing(file_get_contents($inputUri->toString()));

        $apis = $resource->getApis();
        foreach ($apis as $api) {
            /** @var ResourceListingApi $api */
            $service = $this->generateService($api, $resource, $inputUri);

            $fileGenerator = new FileGenerator();
            $fileGenerator->setNamespace($options['namespace']);
            $fileGenerator->setFilename($options['outputDir'] . DIRECTORY_SEPARATOR . $service->getName() . '.php');
            $fileGenerator->setClass($service);
            $fileGenerator->write();
        }

        $this->generateSkeleton($options);

    }

    /**
     * Generate skeleton files needed to use the generated client.
     *
     * @param array $options
     */
    protected function generateSkeleton(array $options)
    {
        $files = array(
            'HttpClient.php',
            'SwaggerApi.php',
        );
        foreach ($files as $file) {
            $fileGenerator = FileGenerator::fromReflectedFileName(__DIR__ . DIRECTORY_SEPARATOR . $file);
            $fileGenerator->setNamespace($options['namespace']);
            $fileGenerator->setSourceDirty(true);
            $fileGenerator->setFilename($options['outputDir'] . DIRECTORY_SEPARATOR . $file);
            $fileGenerator->write();
        }
    }

    /**
     * Generate
     *
     * @param Operation $operation
     * @param ApiDeclaration $api
     * @return MethodGenerator
     */
    protected function generateMethod(Operation $operation, ApiDeclaration $api)
    {
        $methodGenerator = new MethodGenerator();
        $methodGenerator->setName($operation->getNickname());

        // Generate the DocBlock
        $docBlockGenerator = new DocBlockGenerator();
        $docBlockGenerator->setWordWrap(false);
        $docBlockGenerator->setShortDescription($operation->getSummary());
        $docBlockGenerator->setLongDescription(strip_tags($operation->getNotes()));

        foreach ($operation->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $paramGenerator = new ParameterGenerator($parameter->getName());
            $paramGenerator->setType($parameter->getType());

            if (!$parameter->getRequired()) {
                $paramGenerator->setDefaultValue(null);
            }

            $tag = new ParamTag(
                $parameter->getName(),
                $parameter->getType(),
                $parameter->getDescription()
            );
            $docBlockGenerator->setTag($tag);

            $methodGenerator->setParameter($paramGenerator);
        }
        $methodGenerator->setDocBlock($docBlockGenerator);

        // Generate the method body
        $parameterTypeNames = array(
            'path' => array(),
            'query' => array(),
            'body' => array(),
        );

        foreach ($operation->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $parameterTypeNames[$parameter->getParamType()][] = $parameter->getName();
        }

        foreach ($parameterTypeNames as $type => &$names) {
            $names = array_map(
                function ($name) {
                    return '$' . $name;
                },
                $names
            );
            $names = 'array(' . implode($names, ', ') . ')';
        }

        $requestParams = array(
            '"' . $operation->getMethod() . '"',
            '"' . $api->getResourcePath() . '"',
            $parameterTypeNames['path'],
            $parameterTypeNames['query'],
            $parameterTypeNames['body'],
        );
        $body = '$this->request(' . implode($requestParams, ', ') . ');';
        $methodGenerator->setBody($body);

        return $methodGenerator;
    }

    /**
     * @param $api
     * @param $resource
     * @param $inputUri
     * @return ClassGenerator
     */
    protected function generateService($api, $resource, $inputUri)
    {
        $serviceGenerator = new ClassGenerator();
        $name = preg_replace('/(\W*)/', '', $api->getDescription()) . 'Api';
        $serviceGenerator->setName($name);
        $serviceGenerator->setExtendedClass('SwaggerApi');

        $uri = UriFactory::factory($api->getPath());
        $uri->makeRelative($resource->getBasePath());
        if ($uri->getPath()[0] == '/') {
            $uri->setPath('.' . $uri->getPath());
        }
        $uri->resolve($inputUri->toString());

        $api = new ApiDeclaration(
            file_get_contents($uri->toString())
        );
        foreach ($api->getApis() as $a) {
            /** @var ApiDeclarationApi $a */
            foreach ($a->getOperations() as $operation) {
                $methodGenerator = $this->generateMethod($operation, $api);

                $serviceGenerator->addMethodFromGenerator($methodGenerator);
            }
        }

        if ($api->getDescription()) {
            $docBlockGenerator = new DocBlockGenerator();
            $docBlockGenerator->setLongDescription($api->getDescription());
            $fileGenerator->setDocBlock($docBlockGenerator);
        }

        return $serviceGenerator;
    }

}
