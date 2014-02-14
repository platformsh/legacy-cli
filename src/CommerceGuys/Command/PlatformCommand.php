<?php

namespace CommerceGuys\Command;

use CommerceGuys\Guzzle\Plugin\Oauth2\Oauth2Plugin;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\PasswordCredentials;
use CommerceGuys\Guzzle\Plugin\Oauth2\GrantType\RefreshToken;
use Guzzle\Service\Client;
use Guzzle\Service\Description\ServiceDescription;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Dumper;

class PlatformCommand extends Command
{
    protected $config;
    protected $oauth2Plugin;
    protected $accountClient;
    protected $platformClient;

    protected function getOauth2Plugin()
    {
        if (!$this->oauth2Plugin) {
            // InitCommand populates $this->config instead of writing
            // the config file (since it will be written by __destruct()
            // anyway).
            if (!$this->config) {
                $homeDir = trim(shell_exec('cd ~ && pwd'));
                $yaml = new Parser();
                $this->config = $yaml->parse(file_get_contents($homeDir . '/.platform'));
            }

            $oauth2Client = new Client('https://marketplace.commerceguys.com/oauth2/token');
            $config = array(
                'username' => $this->config['email'],
                'password' => $this->config['password'],
                'client_id' => 'platform-cli',
            );
            $grantType = new PasswordCredentials($oauth2Client, $config);
            $refreshTokenGrantType = new RefreshToken($oauth2Client, $config);
            $this->oauth2Plugin = new Oauth2Plugin($grantType, $refreshTokenGrantType);
            if (!empty($this->config['access_token'])) {
                $this->oauth2Plugin->setAccessToken($this->config['access_token']);
            }
            if (!empty($this->config['refresh_token'])) {
                $this->oauth2Plugin->setRefreshToken($this->config['refresh_token']);
            }
        }

        return $this->oauth2Plugin;
    }

    protected function getAccountClient()
    {
        if (!$this->accountClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/accounts.json');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->accountClient = new Client();
            $this->accountClient->setDescription($description);
            $this->accountClient->addSubscriber($oauth2Plugin);
        }

        return $this->accountClient;
    }

    protected function getPlatformClient($baseUrl)
    {
        if (!$this->platformClient) {
            $description = ServiceDescription::factory(CLI_ROOT . '/services/platform.json');
            $oauth2Plugin = $this->getOauth2Plugin();
            $this->platformClient = new Client(array('base_url' => $baseUrl));
            $this->platformClient->setDescription($description);
            $this->platformClient->addSubscriber($oauth2Plugin);
        }

        return $this->platformClient;
    }

    public function __destruct()
    {
        if (is_array($this->config)) {
            if ($this->client) {
                // Save the refresh and access tokens for later.
                $this->config['access_token'] = $this->oauth2Plugin->getAccessToken();
                $this->config['refresh_token'] = $this->oauth2Plugin->getRefreshToken();
            }

            $dumper = new Dumper();
            $homeDir = trim(shell_exec('cd ~ && pwd'));
            file_put_contents($homeDir . '/.platform', $dumper->dump($this->config));
        }
    }

    protected function hasConfiguration()
    {
        $homeDir = trim(shell_exec('cd ~ && pwd'));
        return file_exists($homeDir . '/.platform');
    }
}
