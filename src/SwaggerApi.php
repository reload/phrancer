<?php

namespace reload\phrancer;

use reload\phrancer\Serializer;

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

    /**
     * @param string $method
     * @param string $url
     * @param array $path
     * @param array $query
     * @param array|null $body
     * @return mixed
     */
    public function request($method, $url, $path = array(), $query = array(), $body = null)
    {
        // Replace placeholders in url.
        foreach ($path as $name => $value) {
            $path['{' . $name . '}' ] = $value;
            unset($path[$name]);
        }
        $url = str_replace(array_keys($path), array_values($path), $url);

        $url = $url . '?'. http_build_query($query);

        $headers = array();

        return $this->client->request($method, $url, $headers, $body);
    }
}
