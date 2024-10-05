<?php

namespace App\Services;

class EWebConnectionService {

    private $webServiceUrl;
    private $options;

    public function __construct()
    {
        $this->webServiceUrl = config('marketplace.eweb.wsdl_url');
        $this->options = [
            'location' => config('marketplace.eweb.location_url'),
            'soap_version' => 'SOAP_1_1',
            'trace' => 1
        ];
    }

    public function getEwebSoapClient(): \SoapClient {
        return new \SoapClient($this->webServiceUrl, $this->options);
    }

    public function getEwebAuthenticationInfo() {
        return [
            "AuthenticationInfo" => [
                "ClientNum" => config('marketplace.eweb.client_num'),
                "Password" => config('marketplace.eweb.password'),
                "SecurityCode" => config('marketplace.eweb.security_code'),
            ]
        ];
    }

    public function call($method, $params = [], $auth = true) {
        ini_set("default_socket_timeout", 600);
        $client = $this->getEwebSoapClient();
        $resp = $client->__soapCall($method, [$this->formatParams($params, $auth)]);
        $request = $client->__getLastRequest();
        // var_dump($request);
        return $resp;
    }

    public function formatParams($params, $auth) {
        return $auth ? array_merge($this->getEwebAuthenticationInfo(), $params) : $params;
    }

}