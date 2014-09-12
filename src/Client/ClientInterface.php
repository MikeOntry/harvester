<?php
namespace Erpk\Harvester\Client;

use Erpk\Harvester\Client\Proxy\ProxyInterface;

interface ClientInterface
{
    public function setEmail($email);
    public function getEmail();
    public function setPassword($pwd);
    public function getPassword();

    public function setSessionStorage($path);
    public function getSessionStorage();
    public function getSession();

    public function hasProxy();
    public function setProxy(ProxyInterface $proxy);
    public function getProxy();
    public function removeProxy();

    public function login();
    public function logout();
    public function checkLogin();
}
