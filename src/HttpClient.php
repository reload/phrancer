<?php

namespace Reload\Prancer;

interface HttpClient
{
    public function request($method, $url, $headers, $body);
}
