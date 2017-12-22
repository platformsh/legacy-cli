<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Client;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class OAuth2Command extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $this->setName('auth:oauth2')
            ->setDescription('Log in to ' . $service . ' via OAuth2')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Log in again, even if already logged in');
        Url::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Disable the API token for this command.
        if ($this->api()->hasApiToken()) {
            throw new \Exception('Cannot log in: an API token is set');
        }
        // Login can only happen during interactive use.
        if (!$input->isInteractive()) {
            throw new RuntimeException('Non-interactive login not supported');
        }
        $connector = $this->api()->getClient(false)->getConnector();
        if (!$input->getOption('force') && $connector->isLoggedIn()) {
            $this->stdErr->writeln('You are already logged in.');
            return 0;
        }

        $port = PortUtil::getPort(5000, null, 5005);
        $localAddress = '127.0.0.1:' . $port;
        $localUrl = 'http://' . $localAddress;

        $listenerDir = $this->config()->getWritableUserDir() . '/oauth-listener';
        if (!is_dir($listenerDir) && !mkdir($listenerDir, 0700, true)) {
            throw new \RuntimeException('Failed to create temporary directory: ' . $listenerDir);
        }
        if (!file_put_contents($listenerDir . '/index.php', file_get_contents(CLI_ROOT . '/resources/oauth-listener/index.php'))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $listenerDir . '/index.php');
        }

        $tmpFile = tempnam($this->config()->getWritableUserDir(), 'oauth');
        @chmod($tmpFile, 0600);
        if (!file_exists($tmpFile) || !realpath($tmpFile)) {
            throw new \RuntimeException('Failed to create temporary file: ' . $tmpFile);
        }
        $tmpFile = realpath($tmpFile);

        $process = new Process([
            'php',
            '-dvariables_order=egps',
            '-S',
            $localAddress,
            '-t',
            $listenerDir
        ]);
        $process->setEnv([
            'CLI_OAUTH_STATE' => $this->getRandomState(),
            'CLI_OAUTH_ACCOUNTS_URL' => $this->config()->get('service.accounts_url'),
            'CLI_OAUTH_CLIENT_ID' => $this->config()->get('api.oauth2_client_id'),
            'CLI_OAUTH_FILE' => $tmpFile,
        ]);
        $process->setTimeout(300);

        $process->start();

        usleep(500000);

        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        if ($urlService->openUrl($localUrl, false)) {
            $this->stdErr->writeln(
                'Please use the browser to log in using your <info>' . $this->config()->get('service.name') . '</info> account.'
            );
        } else {
            $this->stdErr->writeln(
                'Open the following URL and log in using your <info>' . $this->config()->get('service.name') . '</info> account:'
            );
            $this->stdErr->writeln($localUrl);
        }

        $code = null;
        while ($process->isRunning() && empty($code)) {
            usleep(300000);
            if (!file_exists($tmpFile)) {
                throw new \RuntimeException('File not found while waiting for authorization code: ' . $tmpFile);
            }
            $contents = file_get_contents($tmpFile);
            if ($contents === false) {
                unlink($tmpFile);
                throw new \RuntimeException('Failed to read file while waiting for authorization code');
            }
            $code = trim($contents);
        }

        // Clean up.
        // @todo make this more reliable
        unlink($tmpFile);
        unlink($listenerDir . '/index.php');
        rmdir($listenerDir);

        if (empty($code)) {
            throw new \RuntimeException('Failed to get authorization code');
        }

        $this->stdErr->writeln('');
        $this->stdErr->writeln('Login information received. Verifying...');

        $this->saveAccessToken(
            $this->getAccessToken($code, $localUrl)
        );

        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        $info = $this->api()->getClient(false)->getAccountInfo();
        if (isset($info['username'], $info['mail'])) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'You are logged in as <info>%s</info> (%s).',
                $info['username'],
                $info['mail']
            ));
        }

        return 0;
    }

    /**
     * @param array $tokenData
     */
    private function saveAccessToken(array $tokenData)
    {
        $token = new AccessToken($tokenData['access_token'], $tokenData['token_type'], $tokenData);
        $session = $this->api()->getClient(false)->getConnector()->getSession();
        $session->setData([
            'accessToken' => $token->getToken(),
            'tokenType' => $token->getType(),
        ]);
        if ($token->getExpires()) {
            $session->set('expires', $token->getExpires()->getTimestamp());
        }
        if ($token->getRefreshToken()) {
            $session->set('refreshToken', $token->getRefreshToken()->getToken());
        }
        $session->save();
    }

    /**
     * @param string $authCode
     * @param string $redirectUri
     *
     * @return array
     */
    private function getAccessToken($authCode, $redirectUri)
    {
        return (new Client())->post(
            $this->config()->get('service.accounts_url') . '/oauth2/token',
            [
                'json' => [
                    'grant_type' => 'authorization_code',
                    'code' => $authCode,
                    'client_id' => $this->config()->get('api.oauth2_client_id'),
                    'redirect_uri' => $redirectUri,
                ],
                'auth' => false,
            ]
        )->json();
    }

    /**
     * @return string
     */
    private function getRandomState()
    {
        if (function_exists('random_bytes')) {
            $state = random_bytes(128);
        } else {
            $state = openssl_random_pseudo_bytes(128, $strong);
            if (!$strong) {
                throw new \RuntimeException('Failed to calculate strong random state');
            }
        }

        return bin2hex($state);
    }
}
