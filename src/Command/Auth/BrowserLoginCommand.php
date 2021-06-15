<?php
namespace Platformsh\Cli\Command\Auth;

use CommerceGuys\Guzzle\Oauth2\AccessToken;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Session\SessionInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

class BrowserLoginCommand extends CommandBase
{
    protected function configure()
    {
        $service = $this->config()->get('service.name');
        $applicationName = $this->config()->get('application.name');

        $this->setName('auth:browser-login');
        if ($this->config()->get('application.login_method') === 'browser') {
            $this->setAliases(['login']);
        }

        $this->setDescription('Log in to ' . $service . ' via a browser')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Log in again, even if already logged in');
        Url::configureInput($this->getDefinition());

        $executable = $this->config()->get('application.executable');
        $help = 'Use this command to log in to the ' . $applicationName . ' using a web browser.'
            . "\n\nIt launches a temporary local website which redirects you to log in if necessary, and then captures the resulting authorization code."
            . "\n\nYour system's default browser will be used. You can override this using the <info>--browser</info> option."
            . "\n\nAlternatively, to log in using an API token (without a browser), run: <info>$executable auth:api-token-login</info>"
            . "\n\n" . $this->getNonInteractiveAuthHelp();
        $this->setHelp(\wordwrap($help, 80));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($this->api()->hasApiToken(false)) {
            $this->stdErr->writeln('Cannot log in via the browser, because an API token is set via config.');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            $this->stdErr->writeln("\n" . $this->getNonInteractiveAuthHelp('comment'));
            return 1;
        }
        if ($this->config()->getSessionId() !== 'default' || count($this->api()->listSessionIds()) > 1) {
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $this->config()->getSessionId()));
            if (!$this->config()->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $this->config()->get('application.executable')));
            }
            $this->stdErr->writeln('');
        }
        $connector = $this->api()->getClient(false)->getConnector();
        $force = $input->getOption('force');
        if (!$force && $connector->isLoggedIn()) {
            // Get account information, simultaneously checking whether the API
            // login is still valid. If the request works, then do not log in
            // again (unless --force is used). If the request fails, proceed
            // with login.
            $api = $this->api();
            try {
                $api->inLoginCheck = true;

                if ($api->authApiEnabled()) {
                    $user = $api->getUser(null, true);
                    $this->stdErr->writeln(\sprintf(
                        'You are already logged in as <info>%s</info> (<info>%s</info>)',
                        $user->username,
                        $user->email
                    ));
                } else {
                    $accountInfo = $api->getMyAccount(true);
                    $this->stdErr->writeln(\sprintf(
                        'You are already logged in as <info>%s</info> (<info>%s</info>).',
                        $accountInfo['username'],
                        $accountInfo['mail']
                    ));
                }

                if ($input->isInteractive()) {
                    /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
                    $questionHelper = $this->getService('question_helper');
                    if (!$questionHelper->confirm('Log in anyway?', false)) {
                        return 1;
                    }
                    $force = true;
                } else {
                    // USE THE FORCE
                    $this->stdErr->writeln('Use the <comment>--force</comment> (<comment>-f</comment>) option to log in again.');

                    return 0;
                }
            } catch (BadResponseException $e) {
                if ($e->getResponse() && in_array($e->getResponse()->getStatusCode(), [400, 401], true)) {
                    $this->debug('Already logged in, but a test request failed. Continuing with login.');
                } else {
                    throw $e;
                }
            } finally {
                $api->inLoginCheck = false;
            }
        }

        // Set up the local PHP web server, which will serve an OAuth2 redirect
        // and wait for the response.
        // Firstly, find an address. The port needs to be within a known range,
        // for validation by the remote server.
        try {
            $start = 5000;
            $end = 5010;
            $port = PortUtil::getPort($start, null, $end);
        } catch (\Exception $e) {
            if (stripos($e->getMessage(), 'failed to find') !== false) {
                $this->stdErr->writeln(sprintf('Failed to find an available port between <error>%d</error> and <error>%d</error>.', $start, $end));
                $this->stdErr->writeln('Check if you have unnecessary services running on these ports.');
                $this->stdErr->writeln(sprintf('For more options, run: <info>%s help login</info>', $this->config()->get('application.executable')));

                return 1;
            }
            throw $e;
        }
        $localAddress = '127.0.0.1:' . $port;
        $localUrl = 'http://' . $localAddress;

        // Then create the document root for the local server. This needs to be
        // outside the CLI itself (since the CLI may be run as a Phar).
        $listenerDir = $this->config()->getWritableUserDir() . '/oauth-listener';
        $this->createDocumentRoot($listenerDir);

        // Create the file where a response will be saved (by the local server
        // script).
        $responseFile = $listenerDir . '/.response';
        if (file_put_contents($responseFile, '', LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create temporary file: ' . $responseFile);
        }
        chmod($responseFile, 0600);

        // Start the local server.
        $process = new Process([
            (new PhpExecutableFinder())->find() ?: PHP_BINARY,
            '-dvariables_order=egps',
            '-S',
            $localAddress,
            '-t',
            $listenerDir
        ]);
        $codeVerifier = $this->generateCodeVerifier();
        $process->setEnv([
            'CLI_OAUTH_APP_NAME' => $this->config()->get('application.name'),
            'CLI_OAUTH_STATE' => $this->generateCodeVerifier(), // the state can just be any random string
            'CLI_OAUTH_CODE_CHALLENGE' => $this->convertVerifierToChallenge($codeVerifier),
            'CLI_OAUTH_AUTH_URL' => $this->config()->get('api.oauth2_auth_url'),
            'CLI_OAUTH_CLIENT_ID' => $this->config()->get('api.oauth2_client_id'),
            'CLI_OAUTH_PROMPT' => $force ? 'consent select_account' : 'consent',
            'CLI_OAUTH_SCOPE' => 'offline_access',
            'CLI_OAUTH_FILE' => $responseFile,
        ] + $this->getParentEnv());
        $process->setTimeout(null);
        $this->stdErr->writeln('Starting local web server with command: <info>' . $process->getCommandLine() . '</info>', OutputInterface::VERBOSITY_VERY_VERBOSE);
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
        $this->stdErr->writeln('  Leave this command running during login.');
        $this->stdErr->writeln('  If you need to quit, use Ctrl+C.');
        $this->stdErr->writeln('');

        // Wait for the file to be filled with an OAuth2 authorization code.
        /** @var array|null $response */
        $response = null;
        $start = time();
        while ($process->isRunning()) {
            usleep(300000);
            if (!file_exists($responseFile)) {
                $this->stdErr->writeln('File not found: <error>' . $responseFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
            $responseRaw = file_get_contents($responseFile);
            if ($responseRaw === false) {
                $this->stdErr->writeln('Failed to read file: <error>' . $responseFile . '</error>');
                $this->stdErr->writeln('');
                break;
            }
            if ($responseRaw !== '') {
                $response = json_decode($responseRaw, true);
                break;
            }
            if (time() - $start >= 1800) {
                $this->stdErr->writeln('Login timed out after 30 minutes');
                $this->stdErr->writeln('');
                break;
            }
        }

        // Allow a little time for the final page to be displayed in the
        // browser.
        usleep(100000);

        // Clean up.
        $process->stop();
        (new Filesystem())->remove([$listenerDir]);

        if (empty($response) || empty($response['code'])) {
            $this->stdErr->writeln('Failed to get an authorization code.');
            $this->stdErr->writeln('');
            if (!empty($response['error']) && !empty($response['error_description'])) {
                $this->stdErr->writeln('  OAuth 2.0 error: <error>' . $response['error'] . '</error>');
                $this->stdErr->writeln('  Description: ' . $response['error_description']);
                if (!empty($response['error_hint'])) {
                    $this->stdErr->writeln('  Hint: ' . $response['error_hint']);
                }
                $this->stdErr->writeln('');
            } elseif (!empty($response['error_description'])) {
                $this->stdErr->writeln($response['error_description']);
                $this->stdErr->writeln('');
            }
            $this->stdErr->writeln('Please try again.');

            return 1;
        }

        $code = $response['code'];

        // Using the authorization code, request an access token.
        $this->stdErr->writeln('Login information received. Verifying...');
        $token = $this->getAccessToken($code, $codeVerifier, $localUrl);

        // Finalize login: log out and save the new credentials.
        $this->api()->logout();

        // Save the new tokens to the persistent session.
        $session = $this->api()->getClient(false)->getConnector()->getSession();
        $this->saveAccessToken($token, $session);

        $this->finalizeLogin();

        return 0;
    }

    /**
     * Attempts to find parent environment variables for the local server.
     *
     * @return array
     */
    private function getParentEnv()
    {
        if (PHP_VERSION_ID >= 70100) {
            return getenv();
        }
        if (!empty($_ENV) && stripos(ini_get('variables_order'), 'e') !== false) {
            return $_ENV;
        }

        return [];
    }

    /**
     * @param array            $tokenData
     * @param SessionInterface $session
     */
    private function saveAccessToken(array $tokenData, SessionInterface $session)
    {
        $token = new AccessToken($tokenData['access_token'], $tokenData['token_type'], $tokenData);
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
     * @param string $codeVerifier
     * @param string $redirectUri
     *
     * @return array
     */
    private function getAccessToken($authCode, $codeVerifier, $redirectUri)
    {
        $client = new Client();
        $request = $client->createRequest('post', $this->config()->get('api.oauth2_token_url'), [
            'body' => [
                'grant_type' => 'authorization_code',
                'code' => $authCode,
                'client_id' => $this->config()->get('api.oauth2_client_id'),
                'redirect_uri' => $redirectUri,
                'code_verifier' => $codeVerifier,
            ],
            'auth' => false,
            'verify' => !$this->config()->get('api.skip_ssl'),
        ]);

        try {
            $response = $client->send($request);

            return $response->json();
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($request, $e->getResponse(), $e);
        }
    }

    /**
     * Get a PKCE code verifier to use with the OAuth2 code request.
     *
     * @return string
     */
    private function generateCodeVerifier()
    {
        // This uses paragonie/random_compat as a polyfill for PHP < 7.0.
        return $this->base64UrlEncode(random_bytes(32));
    }

    /**
     * Base64URL-encodes a string according to the PKCE spec.
     *
     * @see https://tools.ietf.org/html/rfc7636
     *
     * @param string $data
     *
     * @return string
     */
    private function base64UrlEncode($data)
    {
        return str_replace(['+', '/'], ['-', '_'], rtrim(base64_encode($data), '='));
    }

    /**
     * Generates a PKCE code challenge using the S256 transformation on a verifier.
     *
     * @param string $verifier
     *
     * @return string
     */
    private function convertVerifierToChallenge($verifier)
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }
}
