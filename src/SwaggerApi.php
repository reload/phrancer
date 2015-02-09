<?php

namespace Reload\Prancer;

use Reload\Prancer\Serializer;
use Reload\Prancer\SwaggerApiRequest;
use Phly\Http\Request;
use Psr\Http\Message\ResponseInterface;

class SwaggerApi
{

    /**
     * @var HttpClient
     */
    protected $client;

    /**
     * @var Serializer
     */
    protected $serializer;

    public function __construct(HttpClient $client, Serializer $serializer)
    {
        $this->client = $client;
        $this->serializer = $serializer;
    }

    protected function newRequest($method, $path)
    {
        return new SwaggerApiRequest($this->client, $this->serializer, $method, $path);
    }
}
