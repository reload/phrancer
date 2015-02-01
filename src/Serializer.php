<?php


namespace reload\phrancer;


interface Serializer {

    public function serialize($object);

    public function unserialize($string, $type, $arrayType = null);

}
