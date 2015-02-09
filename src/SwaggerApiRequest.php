<?php

namespace Reload\Prancer;

use Phly\Http\Request;

class SwaggerApiRequest
{
    /**
     * Method of request.
     *
     * @var string
     */
    protected $method;

    /**
     * Endpoint of request, that is the path.
     *
     * @var string
     */
    protected $path;

    /**
     * Parameters.
     */
    protected $parameters = array(
        'path' => array(),
        'query' => array(),
        'body' => array(),
    );

    /**
     * Parameter definition.
     */
    protected $paramDef = array();

    /**
     * Response definitions.
     */
    protected $responseDef = array();

    /**
     * Serializer used.
     */
    protected $serializer;

    /**
     * Parameters.
     */
    protected $params = array();

    public function __construct(HttpClient $client, Serializer $serializer, $method, $path)
    {
        $this->httpClient = $client;
        $this->serializer = $serializer;
        $this->method = $method;
        $this->path = $path;
    }

    public function getMethod() {
        return $this->method;
    }

    public function getPath() {
        return $this->path;
    }

    public function defineResponse($code, $message, $model = null)
    {
        $this->responseDef[$code] = array(
            'message' => empty($message) ? '' : $message,
            'model' => empty($model) ? null : $model,
        );
    }


    public function getResponseMessage($code)
    {
        if (isset($this->responseDef[$code])) {
            return $this->responseDef[$code]['message'];
        }
        return '';
    }

    public function getResponseModel($code)
    {
        if (isset($this->responseDef[$code])) {
            return $this->responseDef[$code]['model'];
        }
        return null;
    }

    /**
     * Add a request parameter.
     *
     * @param string $type
     *   Type of parameter, one of 'path', 'query' or 'body'.
     * @param string $name
     *   Name of the parameter.
     * @param mixed $value
     *   The value of the parameter.
     */
    public function addParameter($type, $name, $value)
    {
        if (!in_array($type, array('path', 'query', 'body'))) {
            throw new \RuntimeException('Invalid parameter type "' . $type . '"');
        }

        $this->parameters[$type][$name] = $value;
    }

    /**
     * Do request.
     */
    public function execute()
    {
        $request = $this->getRequest();
        $response = $this->httpClient->request($request);

        $message = 'Unexpected status code from service.';
        $model = null;
        if (isset($this->responseDef[$response->getStatusCode()])) {
            $res = $this->responseDef[$response->getStatusCode()];
            if ($res['model']) {
                $model = $this->serializer->unserialize($response->getBody(), $res['model']);
            }
            $message = $res['message'];
        }
        if ($response->getStatusCode() == 200) {
            return $model ? $model : $message;
        }
        throw new \RuntimeException($message, $response->getStatusCode());
    }

    /**
     * Build PSR Request.
     *
     * @return Psr\Http\Message\RequestInterface;
     */
    public function getRequest()
    {
        // Replace placeholders in url.
        $path_args = array();
        foreach ($request->getParameters('path') as $name => $value) {
            $path_args['{' . $name . '}' ] = $value;
        }

        // Handle path parameters.
        foreach ($this->parameters['path'] as $name => $value) {
            // We could coerce the value into a string, but it's not
            // clearly defined how we should do it.
            if (!is_string($value)) {
                throw new \RuntimeException('Path parameter "' . $name . '" not string.');
            }
            $path_replacements['{' . $name . '}' ] = $value;
        }

        $url = strtr($url, $path_replacements);

        // Handle query parameters.
        $query = $this->buildQuery($this->parameters['query']);

        $url = $url . '?'. $query;

        // There can only be one body.
        $body = $this->serializer->serialize(reset($this->parameters['body']));


        $streamname = 'php://temp/' . uniqid('ReloadPrancer');
        $request = new Request(
            $url,
            $method,
            $streamname,
            array()
        );
        file_put_contents($streamname, $body);

        return $request;
    }

    /**
     * Encode query parameters.
     *
     * We cannot use http_build_query() as the service expects array
     * values as "val=a&val=b", rather than PHPs "val[]=a&val[]=b".
     *
     * To makes things simpler, we assume a flat array of
     * string(-able) values, until proven otherwise.
     *
     * @param array $query
     *   Query params.
     * @return string
     *   The query string.
     */
    protected function buildQuery($query)
    {
        $encoded = array();
        foreach ($query as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $encoded[] = urlencode($name) . '=' . urlencode($item);
                }
            }
            else {
                $encoded[] = urlencode($name) . '=' . urlencode($value);
            }
        }

        return implode('&', $encoded);
    }
}
