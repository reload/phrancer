<?php

namespace Reload\Prancer;

use Reload\Prancer\Serializer;
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

    /**
     * @param string $method
     * @param string $url
     * @param array $responseMapping
     * @param array $path
     * @param array $query
     * @param array|null $body
     * @return mixed
     */
    public function request($method, $url, $responseMapping, $path = array(), $query = array(), $body = null)
    {
        // Replace placeholders in url.
        foreach ($path as $name => $value) {
            $path['{' . $name . '}' ] = $value;
            unset($path[$name]);
        }
        $url = str_replace(array_keys($path), array_values($path), $url);

        $url = $url . '?'. http_build_query($query);

        $streamname = 'php://temp/' . uniqeid('ReloadPrancer');
        $request = new Request(
            $url,
            $method,
            $streamname,
            $headers
        );
        file_put_contents($streamname, $body);
        
        $response = $this->client->request($method, $url, $headers, $body);

        $message = 'Unexpected status code from service.';
        if (isset($responseMapping[$response->getStatusCode()])) {
            $res = $responseMapping[$response->getStatusCode()];
            $model = null;
            if ($res['model']) {
                $model = $this->serializer->unserialize($response->getBody(), $res['model']);
            }
            if ($response->getStatusCode() == 200) {
                return $model;
            }
            $message = !emtpy($res['message']) ? $res['message'] : '';
        }
        throw new RuntimeException($message, $response->getStatusCode());
    }
}
