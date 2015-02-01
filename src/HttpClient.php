<?php

namespace reload\phrancer;

interface HttpClient
{

    public function request($method, $url, $headers, $body);
}
