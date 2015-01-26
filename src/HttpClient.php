<?php


interface HttpClient {

    public function request($method, $url, $body);

}
