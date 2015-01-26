<?php


class SwaggerApi {

    /**
     * @var HttpClient
     */
    protected $client;

    public function __construct(HttpClient $client) {
        $this->client = $client;
    }

    public function request($method, $url, $path = array(), $query = array(), $body = array()) {
        // Replace placeholders in url.
        foreach ($path as $name => $value) {
            $path['{' . $name . '}' ] = $value;
            unset($path[$name]);
        }
        $url = str_replace(array_keys($path), array_values($path), $url);

        $url = $url . '?'. http_build_query($query);
        
        $this->client->request($method, , json_encode($body));
    }

}
