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
use Zend\Code\Generator\DocBlock\Tag\GenericTag;
use Zend\Code\Generator\DocBlock\Tag\ParamTag;
use Zend\Code\Generator\DocBlock\Tag\PropertyTag;
use Zend\Code\Generator\DocBlock\Tag\ReturnTag;
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
            /** @var ApiDeclaration\Api $a */
            foreach ($a->getOperations() as $operation) {
                $methodGenerator = $this->generateMethod($operation, $a);

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
    protected function generateMethod(Operation $operation, ApiDeclaration\Api $api)
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
                $type = $this->relativeNamespace($this->clientNamespace, $this->modelNamespace) . $parameter->getType();
                $paramGenerator->setType($type);
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

        $returnTag = new ReturnTag();
        $type = $operation->getType();
        if ($type === 'array') {
            $type = $operation->getItems() . '[]';
        }
        $returnTag->setTypes($type);
        $docBlockGenerator->setTag($returnTag);

        $methodGenerator->setDocBlock($docBlockGenerator);

        // Generate the method body
        $body = array();

        // Create new request.
        $body[] = '$request = $this->newRequest("' . $operation->getMethod() . '", "' . $api->getPath() . '");';

        // Assemble parameters for making the request.
        foreach ($operation->getParameters() as $parameter) {
            /** @var Parameter $parameter */
            $type = $parameter->getParamType();
            $name = $parameter->getName();
            $body[] = '$request->addParameter("' . $type. '", "' . $name . '", $' . $name . ');';
        }

        // Map each response code to models.
        switch ($operation->getType()) {
            case 'array':
                $model = 'array(' . var_export($this->fullNamespace($this->modelNamespace) . $operation->getItems(), true) . ')';
                break;

            case 'void':
                $model = 'null';
                break;

            default:
                $model = var_export($this->fullNamespace($this->modelNamespace) . $operation->getType(), true);
        }

        $body[] = '';
        $body[] = '$request->defineResponse(200, "", ' . $model . ');';

        if ($repsonseMessages = $operation->getResponseMessages()) {
            foreach ($operation->getResponseMessages() as $responseMessage) {
                $message = var_export($responseMessage->getMessage(), true);
                $model = 'null';
                if ($responseMessage->getResponseModel()) {
                    $model = $this->fullNamespace($this->modelNamespace) . $responseMessage->getResponseModel();
                    $model = var_export($model, true);
                }
                $body[] = '$request->defineResponse("' . $responseMessage->getCode(). '", ' . $message . ', ' . $model . ');';
            }
        }

        // Create the request call.
        $body[] = '';
        $body[] = 'return $request->execute();';

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

        $required = $model->getRequired();
        foreach ($model->getProperties() as $property) {
            /** @var Property $property */
            $propertyGenerator = new PropertyGenerator();
            $propertyGenerator->setName($property->getName());

            // You'd think that, as Zend code supports the badly
            // named @property tag (used for documenting "magic" properties of
            // classes), it would support the much more common @var tag (used
            // to document vars and class properties), but no. We have to
            // create it by hand with GenericTag.
            $varTag = new GenericTag('var');
            $type = $property->getType();
            if ($type === 'array') {
                $type = $property->getItems() . '[]';
            }
            $varTag->setContent($type . ' ' . $property->getDescription());
            $docBlockGenerator = new DocBlockGenerator();
            $docBlockGenerator->setTag($varTag);

            if ($required && in_array($property->getName(), $required)) {
                $requiredTag = new GenericTag('required');
                $docBlockGenerator->setTag($requiredTag);
            }

            $propertyGenerator->setDocBlock($docBlockGenerator);

            $classGenerator->addPropertyFromGenerator($propertyGenerator);
        }

        return $classGenerator;
    }

    /**
     * Return a namespace relative to another.
     */
    protected function relativeNamespace($base, $namespace)
    {
        // Same namespace, return an empty string.
        if ($base == $namespace) {
            return '';
        }

        // Namespace lies below base, return the rest.
        $base_prefix = $base . '\\';
        if (substr($namespace, 0, strlen($base_prefix)) == $base_prefix) {
            return substr($namespace, strlen($base_prefix)) . '\\';
        }

        // Else return a full namespace.
        return '\\' . $namespace . '\\';
    }

    /**
     * Make a namespace fully qualified.
     */
    protected function fullNamespace($namespace)
    {
        return '\\' . $namespace . '\\';
    }

    /**
     * Return the filename for a class.
     *
     * Strips out the base namespace, and adds directories for the remainder, suitable for PSR4.
     *
     * @param ClassGenerator $class
     * @return string
     */
    protected function getFilenameFromClass(ClassGenerator $class)
    {
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
    protected function copyClasses()
    {
        $files = array(
            'HttpClient.php',
            'Serializer/JsonMapperSerializer.php',
            'Serializer.php',
            'SwaggerApi.php',
            'SwaggerApiRequest.php',
        );

        foreach ($files as $file) {
            $source = implode(DIRECTORY_SEPARATOR, array(__DIR__, $file));
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
    protected function generateComposorJson()
    {
        $data = array(
            'autoload' => array(
                'psr-4' => array(
                    $this->namespace . '\\' => 'src/',
                    'Reload\\Prancer\\' => 'prancer/',
                )
            ),
            'require' => array(
                'netresearch/jsonmapper' => "0.4.*",
                'psr/http-message' => '~0.9.0',
                'phly/http' => '~0.11',
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
            'array',
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
