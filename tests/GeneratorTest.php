<?php

namespace Reload\Prancer;

require_once 'vendor/autoload.php';

use Reload\Prancer\Generator;

class GeneratorTest extends \PHPUnit_Framework_TestCase
{

    public function testGenerator()
    {
        $generator = new Generator(array(
            'inputFile' => __DIR__ . '/spec/fixtures/v1.2/helloworld/static/api-docs',
            'outputDir' => './tmp/',
            'namespace' => 'swagger\helloworld',
        ));
        $generator->generate();

        $files = array(
            'swagger\helloworld\GeneratinggreetingsinourapplicationApi' => __DIR__. '/../tmp/src/GeneratinggreetingsinourapplicationApi.php',
        );
        foreach ($files as $class => $file) {
            $this->assertFileExists($file);
            require_once $file;
            $this->assertTrue(class_exists($class));
        }

        $class = new \ReflectionClass('swagger\helloworld\GeneratinggreetingsinourapplicationApi');
        $method = $class->getMethod('helloSubject');
        $this->assertInstanceOf('ReflectionMethod', $method);
        $this->assertEquals(1, $method->getNumberOfRequiredParameters());
    }
}
