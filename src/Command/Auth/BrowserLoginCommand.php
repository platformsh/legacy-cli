<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Client;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class BrowserLoginCommand extends CommandBase
{
    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $applicationName = $this->config()->get('application.name');
        $executable = $this->config()->get('application.executable');

        $this->setName('auth:browser-login');
        if ($this->config()->get('application.login_method') === 'browser') {
            $this->setAliases(['login']);
        }

        $this->setDescription('Log in to ' . $service . ' via a browser')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Log in again, even if already logged in');
        Url::configureInput($this->getDefinition());

        $help = 'Use this command to log in to the ' . $applicationName . ' using a browser.'
            . "\n\nAlternatively, to log in with a username and password in the terminal, use:\n    <info>"
            . $executable . ' auth:password-login</info>';
        if ($aHelp = $this->getApiTokenHelp()) {
            $help .= "\n\n" . $aHelp;
        }
        $this->setHelp($help);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken()) {
            $this->stdErr->writeln('Cannot log in: an API token is set');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive login is not supported.');
            if ($aHelp = $this->getApiTokenHelp('comment')) {
                $this->stdErr->writeln("\n" . $aHelp);
            }
            return 1;
        }
        $connector = $this->api()->getClient(false)->getConnector();
        if (!$input->getOption('force') && $connector->isLoggedIn()) {
            $this->stdErr->writeln('You are already logged in.');
            // USE THE FORCE
            $this->stdErr->writeln('Use the <comment>--force</comment> (<comment>-f</comment>) option to log in again.');
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
        $this->createDocumentRoot($listenerDir);

        // Create the file where an authorization code will be saved (by the
        // local server script).
        $codeFile = $listenerDir . '/.code';
        if (file_put_contents($codeFile, '', LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create temporary file: ' . $codeFile);
        }
        chmod($codeFile, 0600);

        // Find the authorization and token URLs.
        $apiUrl = $this->config()->get('api.accounts_api_url');
        $authHost = parse_url($apiUrl, PHP_URL_HOST);
        $authScheme = parse_url($apiUrl, PHP_URL_SCHEME) ?: 'https';
        if (!$authHost) {
            throw new \RuntimeException('Failed to get API host.');
        }
        $authUrl = $authScheme . '://' . $authHost . '/oauth2/authorize';
        $tokenUrl = $authScheme . '://' . $authHost . '/oauth2/token';

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
            'CLI_OAUTH_APP_NAME' => $this->config()->get('application.name'),
            'CLI_OAUTH_STATE' => $this->getRandomState(),
            'CLI_OAUTH_AUTH_URL' => $authUrl,
            'CLI_OAUTH_CLIENT_ID' => $this->config()->get('api.oauth2_client_id'),
            'CLI_OAUTH_FILE' => $codeFile,
        ]);
        $process->setTimeout(null);
        $process->start();

        // Give the local server some time to start before checking its status
        // or opening the browser (0.5 seconds).
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
            $this->stdErr->writeln(sprintf('Opened URL: <info>%s</info>', $localUrl));
            $this->stdErr->writeln('Please use the browser to log in.');
        } else {
            $this->stdErr->writeln('Please open the following URL in a browser and log in:');
            $this->stdErr->writeln('<info>' . $localUrl . '</info>');
        }

        // Show some help.
        $this->stdErr->writeln('');
        $this->stdErr->writeln('<options=bold>Help:</>');
        $this->stdErr->writeln('  Use Ctrl+C to quit this process.');
        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln(sprintf('  To log in within the terminal instead, quit and run: <info>%s auth:password-login</info>', $executable));
        $this->stdErr->writeln(sprintf('  For more info, quit and run: <info>%s help login</info>', $executable));
        $this->stdErr->writeln('');

        // Wait for the file to be filled with an OAuth2 authorization code.
        $code = null;
        while ($process->isRunning() && empty($code)) {
            usleep(300000);
            if (!file_exists($codeFile)) {
                $this->stdErr->writeln('File not found: <error>' . $codeFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
            $code = file_get_contents($codeFile);
            if ($code === false) {
                $this->stdErr->writeln('Failed to read file: <error>' . $codeFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
        }

        // Clean up.
        $process->stop();
        (new Filesystem())->remove([$listenerDir]);

        if (empty($code)) {
            $this->stdErr->writeln('Failed to get an authorization code. Please try again.');

            return 1;
        }

        // Using the authorization code, request an access token.
        $this->stdErr->writeln('Login information received. Verifying...');
        $token = $this->getAccessToken($code, $localUrl, $tokenUrl);

        // Finalize login: clear the cache and save the new credentials.
        /** @var \Doctrine\Common\Cache\CacheProvider $cache */
        $cache = $this->getService('cache');
        $cache->flushAll();
        $this->saveAccessToken($token);
        $this->stdErr->writeln('You are logged in.');

        // Show user account info.
        $info = $this->api()->getClient(false)->getAccountInfo();
        $this->stdErr->writeln(sprintf(
            "\nUsername: <info>%s</info>\nEmail address: <info>%s</info>",
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
     * @param string $dir
     */
    private function createDocumentRoot($dir)
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            throw new \RuntimeException('Failed to create temporary directory: ' . $dir);
        }
        if (!file_put_contents($dir . '/index.php', file_get_contents(CLI_ROOT . '/resources/oauth-listener/index.php'))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $dir . '/index.php');
        }
    }

    /**
     * Exchange the authorization code for an access token.
     *
     * @param string $authCode
     * @param string $redirectUri
     * @param string $tokenUrl
     *
     * @return array
     */
    private function getAccessToken($authCode, $redirectUri, $tokenUrl)
    {
        return (new Client())->post(
            $tokenUrl,
            [
                'json' => [
                    'grant_type' => 'authorization_code',
                    'code' => $authCode,
                    'client_id' => $this->config()->get('api.oauth2_client_id'),
                    'redirect_uri' => $redirectUri,
                ],
                'auth' => false,
                'verify' => !$this->config()->get('api.skip_ssl'),
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
