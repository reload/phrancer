<?php


namespace reload\phrancer;


class GeneratorTest extends \PHPUnit_Framework_TestCase {

    public function testGenerator() {
        $generator = new Generator();
        $generator->generate(array(
            'inputFile' => __DIR__ . '/spec/fixtures/v1.2/helloworld/static/api-docs',
            'outputDir' => './tmp/',
            'namespace' => 'swagger\helloworld',
        ));

        $files = array(
            'swagger\helloworld\HttpClient' => __DIR__. '/../tmp/HttpClient.php',
            'swagger\helloworld\SwaggerApi' => __DIR__. '/../tmp/SwaggerApi.php',
            'swagger\helloworld\GeneratinggreetingsinourapplicationApi' => __DIR__. '/../tmp/GeneratinggreetingsinourapplicationApi.php',
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
