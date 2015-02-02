<?php

namespace Reload\Prancer;

use Reload\Prancer\Serializer\JsonMapperSerializer;

class JsonMapperSerializerTest extends \PHPUnit_Framework_TestCase
{

    protected $jsonMapper;

    /**
     * @var JsonMapperSerializer
     */
    protected $serializer;

    public function setUp()
    {
        $this->jsonMapper = $this->getMock('JsonMapper');
        $this->serializer = new JsonMapperSerializer($this->jsonMapper);
    }

    public function testSerialization()
    {
        $object = new \stdClass();
        $object->property = 'value';

        $json = $this->serializer->serialize($object);
        $newObject = json_decode($json);

        $this->assertEquals($object, $newObject);
    }

    public function testUnserializeInvalidData()
    {
        $this->setExpectedException('RunTimeException');
        $this->serializer->unserialize('notjson', 'stdClass');
    }

    public function testUnserializeObject()
    {
        $object = new \stdClass();
        $object->property = 'value';

        $this->jsonMapper->method('map')->willReturn($object);

        $newObject = $this->serializer->unserialize(json_encode($object), 'stdClass');
        $this->assertEquals($object, $newObject);
    }

    public function testUnserializeArray()
    {
        $array = new \ArrayObject();
        foreach (range(1, 3) as $i) {
            $object = new \stdClass();
            $object->property = 'value' . $i;
            $array[] = $object;
        }

        $this->jsonMapper->method('mapArray')->willReturn($array);

        $newArray = $this->serializer->unserialize(json_encode($array), 'ArrayObject', 'stdClass');
        $this->assertEquals($array, $newArray);
    }
}
