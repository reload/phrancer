<?php

require_once 'vendor/autoload.php';

$generator = new Reload\Prancer\Generator();
$generator->generate(array(
    'inputFile' => 'externalapidocs/service.json',
    'outputDir' => './tmp',
    'namespace' => 'fbs',
));
