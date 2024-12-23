<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Service\Login;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Utils;
use League\OAuth2\Client\Token\AccessToken;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Url;
use Platformsh\Cli\Util\PortUtil;
use Platformsh\Client\Exception\ApiResponseException;
use Platformsh\Client\Session\SessionInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'auth:browser-login', description: 'Log in via a browser', aliases: ['login'])]
class BrowserLoginCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Io $io, private readonly Login $login, private readonly QuestionHelper $questionHelper, private readonly Url $url)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $applicationName = $this->config->getStr('application.name');

        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Log in again, even if already logged in')
            ->addOption('method', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Require specific authentication method(s)')
            ->addOption('max-age', null, InputOption::VALUE_REQUIRED, 'The maximum age (in seconds) of the web authentication session');
        Url::configureInput($this->getDefinition());

        $executable = $this->config->getStr('application.executable');
        $help = 'Use this command to log in to the ' . $applicationName . ' using a web browser.'
            . "\n\nIt launches a temporary local website which redirects you to log in if necessary, and then captures the resulting authorization code."
            . "\n\nYour system's default browser will be used. You can override this using the <info>--browser</info> option."
            . "\n\nAlternatively, to log in using an API token (without a browser), run: <info>$executable auth:api-token-login</info>"
            . "\n\n" . $this->login->getNonInteractiveAuthHelp();
        $this->setHelp(\wordwrap($help, 80));
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->api->hasApiToken(false)) {
            $this->stdErr->writeln('Cannot log in via the browser, because an API token is set via config.');
            return 1;
        }
        if (!$input->isInteractive()) {
            $this->stdErr->writeln('Non-interactive use of this command is not supported.');
            $this->stdErr->writeln("\n" . $this->login->getNonInteractiveAuthHelp('comment'));
            return 1;
        }
        if ($this->config->getSessionId() !== 'default' || count($this->api->listSessionIds()) > 1) {
            $this->stdErr->writeln(sprintf('The current session ID is: <info>%s</info>', $this->config->getSessionId()));
            if (!$this->config->isSessionIdFromEnv()) {
                $this->stdErr->writeln(sprintf('Change this using: <info>%s session:switch</info>', $this->config->getStr('application.executable')));
            }
            $this->stdErr->writeln('');
        }
        $connector = $this->api->getClient(false)->getConnector();
        $force = $input->getOption('force');
        if (!$force && $input->getOption('method') === [] && $input->getOption('max-age') === null && $connector->isLoggedIn()) {
            // Get account information, simultaneously checking whether the API
            // login is still valid. If the request works, then do not log in
            // again (unless --force is used). If the request fails, proceed
            // with login.
            $api = $this->api;
            try {
                $api->inLoginCheck = true;

                $account = $api->getMyAccount();
                $this->stdErr->writeln(\sprintf(
                    'You are already logged in as <info>%s</info> (<info>%s</info>)',
                    $account['username'],
                    $account['email'],
                ));

                if (!$this->questionHelper->confirm('Log in anyway?', false)) {
                    return 1;
                }
                $force = true;
            } catch (BadResponseException $e) {
                if (in_array($e->getResponse()->getStatusCode(), [400, 401], true)) {
                    $this->io->debug('Already logged in, but a test request failed. Continuing with login.');
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
                $this->stdErr->writeln(sprintf('For more options, run: <info>%s help login</info>', $this->config->getStr('application.executable')));

                return 1;
            }
            throw $e;
        }
        $localAddress = '127.0.0.1:' . $port;
        $localUrl = 'http://' . $localAddress;

        // Then create the document root for the local server. This needs to be
        // outside the CLI itself (since the CLI may be run as a Phar).
        $listenerDir = $this->config->getWritableUserDir() . '/oauth-listener';
        $this->createDocumentRoot($listenerDir);

        // Create the file where a response will be saved (by the local server
        // script).
        $responseFile = $listenerDir . '/.response';
        if (file_put_contents($responseFile, '', LOCK_EX) === false) {
            throw new \RuntimeException('Failed to create temporary file: ' . $responseFile);
        }
        chmod($responseFile, 0o600);

        // Start the local server.
        $process = new Process([
            (new PhpExecutableFinder())->find() ?: PHP_BINARY,
            '-dvariables_order=egps',
            '-S',
            $localAddress,
            '-t',
            $listenerDir,
        ]);
        $codeVerifier = $this->generateCodeVerifier();
        $process->setEnv([
            'CLI_OAUTH_APP_NAME' => $this->config->getStr('application.name'),
            'CLI_OAUTH_STATE' => $this->generateCodeVerifier(), // the state can just be any random string
            'CLI_OAUTH_CODE_CHALLENGE' => $this->convertVerifierToChallenge($codeVerifier),
            'CLI_OAUTH_AUTH_URL' => $this->config->get('api.oauth2_auth_url'),
            'CLI_OAUTH_CLIENT_ID' => $this->config->get('api.oauth2_client_id'),
            'CLI_OAUTH_PROMPT' => $force ? 'consent select_account' : 'consent',
            'CLI_OAUTH_SCOPE' => 'offline_access',
            'CLI_OAUTH_FILE' => $responseFile,
            'CLI_OAUTH_METHODS' => implode(' ', ArrayArgument::getOption($input, 'method')),
            'CLI_OAUTH_MAX_AGE' => $input->getOption('max-age'),
        ] + getenv());
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
        if ($this->url->openUrl($localUrl, false)) {
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
        /** @var null|array{code: string, redirect_uri: string}|array{error: string, error_description: string, error_hint: string} $response */
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
        $token = $this->getAccessToken($code, $codeVerifier, $response['redirect_uri'] ?? $localUrl);

        // Finalize login: log out and save the new credentials.
        $this->api->logout();

        // Save the new tokens to the persistent session.
        $session = $this->api->getClient(false)->getConnector()->getSession();
        $this->saveAccessToken($token, $session);

        $this->login->finalize();

        if (empty($token['refresh_token'])) {
            $this->stdErr->writeln('');
            $clientId = $this->config->getStr('api.oauth2_client_id');
            $this->stdErr->writeln([
                '<options=bold;fg=yellow>Warning:</fg>',
                'No refresh token is available. This will cause frequent login errors.',
                'Please contact support.',
                "For internal use: the OAuth 2 client is probably misconfigured (client ID: <comment>$clientId</comment>).",
            ]);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $tokenData
     * @param SessionInterface $session
     */
    private function saveAccessToken(array $tokenData, SessionInterface $session): void
    {
        $token = new AccessToken($tokenData);
        $session->set('accessToken', $token->getToken());
        $session->set('tokenType', $tokenData['token_type'] ?: null);
        $session->set('expires', $token->getExpires());
        $session->set('refreshToken', $token->getRefreshToken());
        $session->save();
    }

    /**
     * @param string $dir
     */
    private function createDocumentRoot(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o700, true)) {
            throw new \RuntimeException('Failed to create temporary directory: ' . $dir);
        }
        if (!file_put_contents($dir . '/index.php', (string) file_get_contents(CLI_ROOT . '/resources/oauth-listener/index.php'))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $dir . '/index.php');
        }
        if (!file_put_contents($dir . '/config.json', (string) json_encode((array) $this->config->get('browser_login'), JSON_UNESCAPED_SLASHES))) {
            throw new \RuntimeException('Failed to write temporary file: ' . $dir . '/config.json');
        }
    }

    /**
     * Exchanges the authorization code for an access token.
     *
     * @return array<string, mixed>
     */
    private function getAccessToken(string $authCode, string $codeVerifier, string $redirectUri): array
    {
        $client = new Client(['verify' => !$this->config->getBool('api.skip_ssl')]);
        $request = new Request('POST', $this->config->getStr('api.oauth2_token_url'), body: http_build_query([
            'grant_type' => 'authorization_code',
            'code' => $authCode,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
        ]));

        try {
            $response = $client->send($request, [
                'headers' => [
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'auth' => [$this->config->get('api.oauth2_client_id'), ''],
            ]);

            return (array) Utils::jsonDecode((string) $response->getBody(), true);
        } catch (BadResponseException $e) {
            throw ApiResponseException::create($request, $e->getResponse(), $e);
        }
    }

    /**
     * Gets a PKCE code verifier to use with the OAuth2 code request.
     */
    private function generateCodeVerifier(): string
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
    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/'], ['-', '_'], rtrim(base64_encode($data), '='));
    }

    /**
     * Generates a PKCE code challenge using the S256 transformation on a verifier.
     */
    private function convertVerifierToChallenge(string $verifier): string
    {
        return $this->base64UrlEncode(hash('sha256', $verifier, true));
    }
}
