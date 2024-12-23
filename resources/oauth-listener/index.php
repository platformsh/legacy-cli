<?php

namespace Platformsh\Cli\OAuth;

class Listener
{
    private string $state;
    private string $authUrl;
    private string $clientId;
    private string $file;
    private string $localUrl;
    private Response $response;
    private string $codeChallenge;
    private string $prompt;
    private string $scope;
    private string $authMethods;
    private ?string $maxAge;

    public function __construct()
    {
        $required = [
            'CLI_OAUTH_STATE',
            'CLI_OAUTH_AUTH_URL',
            'CLI_OAUTH_CLIENT_ID',
            'CLI_OAUTH_FILE',
            'CLI_OAUTH_CODE_CHALLENGE',
            'CLI_OAUTH_PROMPT',
        ];
        if ($missing = array_diff($required, array_keys($_ENV))) {
            throw new \RuntimeException('Invalid environment, missing: ' . implode(', ', $missing));
        }
        $this->state = $_ENV['CLI_OAUTH_STATE'];
        $this->authUrl = $_ENV['CLI_OAUTH_AUTH_URL'];
        $this->clientId = $_ENV['CLI_OAUTH_CLIENT_ID'];
        $this->file = $_ENV['CLI_OAUTH_FILE'];
        $this->prompt = $_ENV['CLI_OAUTH_PROMPT'];
        $this->codeChallenge = $_ENV['CLI_OAUTH_CODE_CHALLENGE'];
        $this->scope = $_ENV['CLI_OAUTH_SCOPE'] ?? '';
        $this->localUrl = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
        $this->response = new Response();
        $this->authMethods = $_ENV['CLI_OAUTH_METHODS'] ?? '';
        $this->maxAge = $_ENV['CLI_OAUTH_MAX_AGE'] ?? null;
    }

    /**
     * @return string
     */
    private function getOAuthUrl(): string
    {
        $params = [
            'redirect_uri' => $this->localUrl,
            'state' => $this->state,
            'client_id' => $this->clientId,
            'prompt' => $this->prompt,
            'response_type' => 'code',
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
            'scope' => $this->scope,
        ];

        if (!empty($this->authMethods)) {
            $params['amr'] = $this->authMethods;
        }
        if ($this->maxAge !== null && $this->maxAge !== '') {
            $params['max_age'] = $this->maxAge;
        }

        return $this->authUrl . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check state, run logic, set page content.
     */
    public function run(): void
    {
        // Respond after a successful OAuth2 redirect.
        if (isset($_GET['state'], $_GET['code'])) {
            if ($_GET['state'] !== $this->state) {
                $this->reportError('Invalid state parameter');
                return;
            }
            if (isset($_GET['code_challenge']) && $_GET['code_challenge'] !== $this->codeChallenge) {
                $this->reportError('Invalid returned code_challenge parameter');
                return;
            }
            if (!$this->sendToTerminal(['code' => $_GET['code'], 'redirect_uri' => $this->localUrl])) {
                $this->reportError('Failed to send authorization code back to terminal');
                return;
            }
            $this->setRedirect($this->localUrl . '/?done');
            $this->response->content = '<p>Authentication response received, please wait...</p>';

            return;
        }

        // Show the final result page.
        if (array_key_exists('done', $_GET)) {
            $this->response->title = 'Successfully logged in';
            $this->response->content = '<p>You can return to the command line</p>';

            return;
        }

        // Respond after an OAuth2 error.
        if (isset($_GET['error'])) {
            $message = $_GET['error_description'] ?? null;
            $hint = $_GET['error_hint'] ?? null;
            $this->reportError($message, $_GET['error'], $hint);
            return;
        }

        // In any other case: redirect to login.
        $url = $this->getOAuthUrl();
        $this->setRedirect($url);
        $this->response->content = '<p><a href="' . htmlspecialchars($url) . '">Log in</a>.</p>';
    }

    /**
     * @param string $url
     * @param int $code
     */
    private function setRedirect(string $url, int $code = 302): void
    {
        $this->response->code = $code;
        $this->response->headers['Location'] = $url;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    /**
     * @param array<string, mixed> $response
     *
     * @return bool
     */
    private function sendToTerminal(array $response): bool
    {
        return (bool) file_put_contents($this->file, json_encode($response), LOCK_EX);
    }

    /**
     * @param string|null $message The error message.
     * @param string|null $error An OAuth2 error type.
     * @param string|null $hint An OAuth2 error hint.
     */
    private function reportError(?string $message = null, ?string $error = null, ?string $hint = null): void
    {
        $this->response->headers['Status'] = '401';
        $this->response->title = 'Error';
        if (isset($error)) {
            $this->response->content .= '<p class="error"><code>' . htmlspecialchars($error) . '</code></p>';
        }
        if (isset($message)) {
            $this->response->content .= '<p class="error">' . htmlspecialchars($message) . '</p>';
        }
        if (isset($hint)) {
            $this->response->content .= '<p class="error error-hint">' . htmlspecialchars($hint) . '</p>';
        }
        if ($message || $error || $hint) {
            $response = ['error' => $error, 'error_description' => $message, 'error_hint' => $hint];
            if (!$this->sendToTerminal($response)) {
                $this->response->content .= '<p class="error">Additionally: failed to send error message back to terminal</p>';
            }
        }
        $this->response->content .= '<p>Please try again</p>';
    }
}

class Response
{
    /** @var array<string, string> */
    public array $headers = [];
    public int $code = 200;
    public string $headTitle = '';
    public string $title = '';
    public string $content = '';

    public function __construct()
    {
        // Set default title and headers.
        $appName = getenv('CLI_OAUTH_APP_NAME') ?: 'CLI';
        $this->headTitle = htmlspecialchars($appName) . ': Authentication (temporary URL)';
        $this->headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/html; charset=utf-8',
        ];
    }
}

$configJson = file_get_contents('config.json');
if ($configJson === false) {
    throw new \RuntimeException('Failed to load configuration file: config.json');
}
$config = (array) json_decode($configJson, true);

$listener = new Listener();
$listener->run();

$response = $listener->getResponse();

if (!empty($config['body'])) {
    $body = preg_replace_callback('/\{\{\s*(content|title)\s*}}/', function (array $matches) use ($response) {
        return ['content' => $response->content, 'title' => $response->title][$matches[1]];
    }, $config['body']);
} else {
    $body = '<h1>' . $response->title . '</h1>' . $response->content;
}

http_response_code($response->code);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo $response->headTitle; ?></title>
    <style>
        html {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-weight: 300;
            background-color: #eee;
        }

        body {
            text-align: center;
        }

        img.icon {
            display: block;
            margin: 3em auto 1em;
        }

        h1 {
            font-weight: 100;
            margin: 1em auto;
        }

        .error {
            color: darkred;
        }
        .error-hint {
            font-style: oblique;
        }
    </style>
    <?php if (!empty($config['css'])): ?>
    <style>
        <?php echo $config['css']; ?>
    </style>
    <?php endif; ?>
</head>
<body>
<?php echo $body; ?>
</body>
</html>
