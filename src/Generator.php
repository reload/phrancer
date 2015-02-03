<?php

namespace Reload\Prancer;

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

class Generator
{
    protected $inputFile;
    protected $outputDir;
    protected $namespace;
    protected $clientNamespace;
    protected $modelNamespace;

    public function __construct($options)
    {
        $this->inputFile = !empty($options['inputFile']) ?
                         $options['inputFile'] : '';
        $this->outputDir = !empty($options['outputDir']) ?
                         $options['outputDir'] : '';
        $this->namespace = !empty($options['namespace']) ?
                         $options['namespace'] : '';
        $this->clientNamespace = !empty($options['clientNamespace']) ?
                               $options['clientNamespace'] :
                               $this->namespace;
        $this->modelNamespace = !empty($options['modelNamespace']) ?
                               $options['modelNamespace'] :
                               $this->namespace;
        if (empty($this->inputFile) ||
            empty($this->outputDir) ||
            empty($this->namespace) ||
            empty($this->clientNamespace) ||
            empty($this->modelNamespace)) {
            throw new RuntimeException('Bad arguments for generator.');
        }
    }

    public function generate()
    {
        $files = array();

        $inputUri = UriFactory::factory($this->inputFile);
        $resource = new ResourceListing(file_get_contents($inputUri->toString()));

        foreach ($resource->getApis() as $resourceListing) {
            /** @var ResourceListingApi $resourceListing */
            // Fix up the oddity of having the {format} placeholder in the filename.
            $resourcePath = str_replace('{format}', 'json', $resourceListing->getPath());
            $uri = UriFactory::factory($resourcePath);
            $uri->makeRelative($resource->getBasePath());
            if ($uri->getPath()[0] == '/') {
                $uri->setPath('.' . $uri->getPath());
            }
            $uri->resolve($inputUri->toString());

            $api = new ApiDeclaration(
                file_get_contents($uri->toString())
            );

            $classes = array();
            $generator = $this->generateService($resourceListing, $api);
            $generator->setNamespaceName($this->clientNamespace);
            if ($this->clientNamespace != $this->modelNamespace) {
                $generator->addUse($this->modelNamespace);
            }
            $classes[] = $generator;

            $models = $api->getModels();
            if (!empty($models)) {
                foreach ($api->getModels() as $model) {
                    $generator = $this->generateModel($model);
                    $generator->setNamespaceName($this->modelNamespace);
                    $classes[] = $generator;
                }
            }

            foreach ($classes as $class) {
                $fileGenerator = new FileGenerator();
                $fileGenerator->setFilename($this->outputDir . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . $this->getFilenameFromClass($class));
                $fileGenerator->setClass($class);
                $files[] = $fileGenerator;
            }

        }

        // Create generated classes.
        array_walk($files, function(FileGenerator $file) {
            $dir = dirname($file->getFilename());
            if (!is_dir($dir)) {
                // Create parent directories.
                mkdir($dir, 0777, true);
            }
            $file->write();
        });

        // Copy Prancer base classes over.
        $this->copyClasses();

        // Generate a composer.json to create autoloader and pull in
        // dependencies.
        $this->generateComposorJson();
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
        $serviceGenerator->addUse('Reload\\Prancer\\SwaggerApi');
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

            if (!$this->isPrimitive($parameter->getType())) {
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
            // If body is only one item, don't make it an array. The
            // REST service wouldn't expect it to be.
            if ($type == 'body' && count($names) == 1) {
                $names = implode('', $names);
            } else {
                $names = 'array(' . implode(', ', $names) . ')';
            }
        }

        // Map each response code to models.
        switch ($operation->getType()) {
        case 'array':
            $model = 'array(' . var_export($operation->getItems(), true) . ')';
            break;

        case 'void':
            $model = 'null';
            break;

        default:
            $model = var_export($operation->getType(), true);
        }

        $responseMapping = array(
            '200' => array('message' => 'null', 'model' => $model),
        );
        if ($repsonseMessages = $operation->getResponseMessages()) {
            foreach ($operation->getResponseMessages() as $responseMessage) {
                $responseMapping[$responseMessage->getCode()] = array(
                    'message' => var_export($responseMessage->getMessage(), true),
                    'model' => var_export($responseMessage->getResponseModel(), true),
                );
            }
        }

        $body[] = '$responseMapping = array(';
        foreach ($responseMapping as $code => $mapping) {
            $body[] = '  ' . $code . ' => array("message" => ' . $mapping['message'] . ', "model" => ' . $mapping['model'] . '),';
        }
        $body[] = ');';
        
        // Parameters for the request call.
        $requestParams = array(
            '"' . $operation->getMethod() . '"',
            '"' . $api->getResourcePath() . '"',
            '$responseMapping',
            $parameterTypeNames['path'],
            $parameterTypeNames['query'],
            $parameterTypeNames['body'],
        );
        // Create the request call.
        $body[] = 'return $this->request(' . implode($requestParams, ', ') . ');';

        // Handle the $response;
        // $arrayType = (!empty($operation->getItems())) ? '"' . $operation->getItems() . '"' : 'null';
        // $body[] = 'return $this->serializer->unserialize($response, "' . $operation->getType() . '", ' . $arrayType . ');';
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
     * Return the filename for a class.
     *
     * Strips out the base namespace, and adds directories for the remainder, suitable for PSR4.
     *
     * @param ClassGenerator $class
     * @return string
     */
    protected function getFilenameFromClass(ClassGenerator $class) {
        $namespace = $class->getNamespaceName();
        // Strip the base namespace.
        if (substr($namespace, 0, strlen($this->namespace)) == $this->namespace) {
            $namespace = substr($namespace, strlen($this->namespace));
        }

        $path_parts = explode('\\', $namespace);
        $path_parts[] = $class->getName() . '.php';
        return implode(DIRECTORY_SEPARATOR, $path_parts);
    }

    /**
     * Copy Prancer classes into the generated code.
     *
     * We'd rather just add a require to the composer.json, but due to
     * our own requirements, this gets messy.
     */
    protected function copyClasses() {
        $files = array(
            'HttpClient.php',
            'Serializer/JsonMapperSerializer.php',
            'Serializer.php',
            'SwaggerApi.php',
        );

        foreach ($files as $file) {
            $source = implode(DIRECTORY_SEPARATOR, array(dirname(__DIR__), 'src'));
            $destination = implode(DIRECTORY_SEPARATOR, array($this->outputDir, 'prancer', $file));
            $dir = dirname($destination);

            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            file_put_contents($destination, file_get_contents($source));
        }
    }

    /**
     * Generate a composer.json for the generated code.
     */
    protected function generateComposorJson() {
        $data = array(
            'autoload' => array(
                'psr-4' => array(
                    $this->namespace . '\\' => 'src/',
                    'Reload\\Prancer\\' => 'prancer/',
                )
            ),
            'require' => array(
                'netresearch/jsonmapper' => "0.4.*",
                'psr/http-message' => '~0.8.0',
                'phly/http' => '~1.0-dev@dev',
            ),
        );

        file_put_contents($this->outputDir . DIRECTORY_SEPARATOR . 'composer.json', json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Determine if a type is a primitive
     *
     * @param string $type The type name
     * @return bool true if the type is a primitive
     */
    protected function isPrimitive($type)
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
