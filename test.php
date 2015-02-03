<?php

require_once 'vendor/autoload.php';

$generator = new Reload\Prancer\Generator(array(
    'inputFile' => 'externalapidocs/service.json',
    'outputDir' => './output',
    'namespace' => 'FBS',
    'modelNamespace' => 'FBS\\Model',
));
$generator->generate();
