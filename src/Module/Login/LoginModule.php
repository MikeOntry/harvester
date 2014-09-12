<?php
namespace Erpk\Harvester\Module\Login;

use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Exception\ScrapeException;

class LoginModule extends Module
{
    public function login()
    {
        $client = $this->getClient();
        $login = $client->post('login');
        $login->addPostFields([
            '_token'            =>  md5(time()),
            'citizen_email'     =>  $client->getEmail(),
            'citizen_password'  =>  $client->getPassword(),
            'remember'          =>  1
        ]);
        
        $login->setHeader('Referer', $client->getBaseUrl());
        $login = $login->send();
        if ($login->isRedirect()) {
            $homepage = $client->get()->send();
            $this->parseSessionData($homepage->getBody(true));
        } else {
            throw new ScrapeException('Login failed.');
        }
    }

    public function logout()
    {
        $this->getClient()->post('logout')->send();
    }

    protected function parseSessionData($html)
    {
        $hxs = Selector\XPath::loadHTML($html);
        
        $token = null;
        $tokenInput = $hxs->select('//*[@id="_token"][1]/@value');
        if (!$tokenInput->hasResults()) {
            $scripts = $hxs->select('//script[@type="text/javascript"]');
            $tokenPattern = '@csrfToken\s*:\s*\'([a-z0-9]+)\'@';
            foreach ($scripts as $script) {
                if (preg_match($tokenPattern, $script->extract(), $matches)) {
                    $token = $matches[1];
                    break;
                }
            }
        } else {
            $token = $tokenInput->extract();
        }

        if ($token === null) {
            throw new Exception\ScrapeException('CSRF token not found');
        }

        $userAvatar = $hxs->select('//a[@class="user_avatar"][1]');
        $id   = (int)strtr($userAvatar->select('@href')->extract(), array('/en/citizen/profile/' => ''));
        $name = $userAvatar->select('@title')->extract();
        
        $this
            ->getClient()
            ->getSession()
            ->setToken($token)
            ->setCitizenId($id)
            ->setCitizenName($name)
            ->save();
    }
}
