<?php

namespace reload\phrancer;

use Swagger\ApiDeclaration;
use Swagger\ApiDeclaration\Api as ApiDeclarationApi;
use Swagger\ApiDeclaration\Model;
use Swagger\ApiDeclaration\Model\Property;
use Swagger\ResourceListing;
use Swagger\ResourceListing\Api as ResourceListingApi;
use Swagger\ApiDeclaration\Api\Operation;
use Swagger\ApiDeclaration\Api\Operation\Parameter;
use Zend\Code\Generator\ClassGenerator;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\PropertyTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\PropertyGenerator;
use Zend\Uri\UriFactory;

class Generator {

    public function __construct()
    {

    }

    public function generate($options) {
        $files = array();

        $inputUri = UriFactory::factory($options['inputFile']);
        $resource = new ResourceListing(file_get_contents($inputUri->toString()));

        foreach ($resource->getApis() as $resourceListing) {
            /** @var ResourceListingApi $resourceListing */
            $uri = UriFactory::factory($resourceListing->getPath());
            $uri->makeRelative($resource->getBasePath());
            if ($uri->getPath()[0] == '/') {
                $uri->setPath('.' . $uri->getPath());
            }
            $uri->resolve($inputUri->toString());

            $api = new ApiDeclaration(
                file_get_contents($uri->toString())
            );

            $classes = array();
            $classes[] = $this->generateService($resourceListing, $api);

            $models = $api->getModels();
            if (!empty($models)) {
                foreach ($api->getModels() as $model) {
                    $classes[] = $this->generateModel($model);
                }
            }

            foreach ($classes as $class) {
                $fileGenerator = new FileGenerator();
                $fileGenerator->setNamespace($options['namespace']);
                $fileGenerator->setFilename($options['outputDir'] . DIRECTORY_SEPARATOR . $class->getName() . '.php');
                $fileGenerator->setClass($class);
                $files[] = $fileGenerator;
            }

        }

        if (!is_dir($options['outputDir'])) {
            mkdir($options['outputDir']);
        }
        array_walk($files, function(FileGenerator $file) {
            $file->write();
        });
    }

    /**
     * Generate a class for a service.
     *
     * @param ResourceListing $resource
     * @param ApiDeclaration $api
     * @return ClassGenerator
     */
    protected function generateService(ResourceListingApi $resource, ApiDeclaration $api)
    {
        $name = preg_replace('/(\W*)/', '', $resource->getDescription()) . 'Api';

        $serviceGenerator = new ClassGenerator();
        $serviceGenerator->setName($name);
        $serviceGenerator->setExtendedClass('SwaggerApi');
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

    /**
     * Generate method for an operation.
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

            if (!$this->isPrimivite($parameter->getType())) {
                $paramGenerator->setType($parameter->getType());
            }

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
        $body = array();

        $parameterTypeNames = array(
            'path' => array(),
            'query' => array(),
            'body' => array(),
        );

        foreach ($operation->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $parameterTypeNames[$parameter->getParamType()][] = $parameter->getName();
        }

        // Serialize body parameters.
        foreach ($parameterTypeNames['body'] as $parameter) {
            $body[] = '$' . $parameter . ' = $this->serializer->serialize($' . $parameter . ');';
        }

        // Assemble parameters for making the request.
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
        $body[] = '$response = $this->request(' . implode($requestParams, ', ') . ');';
        $arrayType = (!empty($operation->getItems())) ? '"' . $operation->getItems() . '"' : 'null';
        $body[] = 'return $this->serializer->unserialize($response, "' . $operation->getType() . '", ' . $arrayType . ');';
        $methodGenerator->setBody(implode(PHP_EOL, $body));

        return $methodGenerator;
    }


    /**
     * Generate a model class used by a service.
     *
     * @param Model $model
     * @return ClassGenerator
     */
    protected function generateModel(Model $model)
    {
        $classGenerator = new ClassGenerator();
        $classGenerator->setName($model->getName());

        $docBlockGenerator = new DocBlockGenerator();
        $docBlockGenerator->setShortDescription($model->getDescription());

        foreach ($model->getProperties() as $property) {
            /** @var Property $property */
            $propertyGenerator = new PropertyGenerator();
            $propertyGenerator->setName($property->getName());

            $propertyTag = new PropertyTag();
            $propertyTag->setPropertyName($property->getName());
            $propertyTag->setTypes($property->getType());
            $propertyTag->setDescription($property->getDescription());
            $docBlockGenerator = new DocBlockGenerator();
            $docBlockGenerator->setTag($propertyTag);
            $propertyGenerator->setDocBlock($docBlockGenerator);

            $classGenerator->addPropertyFromGenerator($propertyGenerator);
        }

        return $classGenerator;
    }

    /**
     * Determine if a type is a primitive
     *
     * @param string $type The type name
     * @return bool true if the type is a primitive
     */
    protected function isPrimivite($type)
    {
        $primitives = array(
            'integer',
            'long',
            'float',
            'double',
            'string',
            'byte',
            'boolean',
            'date',
            'dateTime'
        );
        return in_array($type, $primitives);
    }
}
