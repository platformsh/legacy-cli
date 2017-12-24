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

        // Set up the local PHP web server, which will serve an OAuth2 redirect
        // and wait for the response.
        // Firstly, find an address. The port needs to be within a known range,
        // for validation by the remote server.
        $port = PortUtil::getPort(5000, null, 5005);
        $localAddress = '127.0.0.1:' . $port;
        $localUrl = 'http://' . $localAddress;

        // Then create the document root for the local server. This needs to be
        // outside the CLI itself (since the CLI may be run as a Phar).
        $listenerDir = $this->config()->getWritableUserDir() . '/oauth-listener';
        if (!is_dir($listenerDir) && !mkdir($listenerDir, 0700, true)) {
            throw new \RuntimeException('Failed to create temporary directory: ' . $listenerDir);
        }
        if (!file_put_contents($listenerDir . '/index.php', file_get_contents(CLI_ROOT . '/resources/oauth-listener/index.php'))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $listenerDir . '/index.php');
        }

        // Create the file where an authorization code will be saved (by the
        // local server script).
        $codeFile = $listenerDir . '/.code';
        if (file_put_contents($codeFile, '', LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create temporary file: ' . $codeFile);
        }
        chmod($codeFile, 0600);

        // Start the local server.
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
            'CLI_OAUTH_FILE' => $codeFile,
        ]);
        $process->setTimeout(null);
        $process->start();

        // Give the local server some time to start (before checking its status
        // or opening the browser).
        usleep(500000);

        // Check the local server status.
        if (!$process->isRunning()) {
            $this->stdErr->writeln('Failed to start local web server.');
            $this->stdErr->writeln(trim($process->getErrorOutput()));

            return 1;
        }

        // Open the local server URL in a browser (or print the URL).
        /** @var \Platformsh\Cli\Service\Url $urlService */
        $urlService = $this->getService('url');
        if ($urlService->openUrl($localUrl, false)) {
            $this->stdErr->writeln('Please use the browser to log in.');
        } else {
            $this->stdErr->writeln('Open the following URL and log in:');
            $this->stdErr->writeln($localUrl);
        }

        // Wait for the file to be filled with an OAuth2 authorization code.
        $code = null;
        while ($process->isRunning() && empty($code)) {
            usleep(300000);
            if (!file_exists($codeFile)) {
                $this->stdErr->writeln('File not found: <error>' . $codeFile . '</error>');
                break;
            }
            $code = file_get_contents($codeFile);
            if ($code === false) {
                $this->stdErr->writeln('Failed to read file: <error>' . $codeFile . '</error>');
                break;
            }
        }

        // Clean up.
        unlink($codeFile);
        unlink($listenerDir . '/index.php');
        rmdir($listenerDir);

        $this->stdErr->writeln('');

        if (empty($code)) {
            $this->stdErr->writeln('Failed to get an authorization code. Please try again.');

            return 1;
        }

        // Clear the cache (as we are about to log in).
        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();

        // Using the authorization code, request an access token, and save it
        // to the session.
        $this->stdErr->writeln('Login information received. Verifying...');
        $this->saveAccessToken(
            $this->getAccessToken($code, $localUrl)
        );

        // Report success, with user account info.
        $info = $this->api()->getClient(false)->getAccountInfo();
        $this->stdErr->writeln('');
        $this->stdErr->writeln(sprintf(
            'You are logged in as <info>%s</info> (%s).',
            $info['username'],
            $info['mail']
        ));

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
     * Get a random state to use with the OAuth2 code request.
     *
     * @return string
     */
    private function getRandomState()
    {
        // This uses paragonie/random_compat as a polyfill for PHP < 7.0.
        return bin2hex(random_bytes(128));
    }
}
