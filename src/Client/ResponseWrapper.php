<?php
namespace Erpk\Harvester\Client;

use Guzzle\Http\Message\Response;
use XPathSelector\Selector;

class ResponseWrapper extends Response
{
    public function __construct(Response $response)
    {
        $this->setStatus($response->getStatusCode());
        $this->setBody($response->getBody());
        $this->setHeaders($response->getHeaders()->toArray());
    }

    public function xpath()
    {
        return Selector::loadHTML($this->getBody(true));
    }
}
