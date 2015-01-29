<?php


interface HttpClient {

    public function request($method, $url, $headers, $body);

}
