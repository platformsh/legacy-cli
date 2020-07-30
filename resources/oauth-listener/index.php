<?php

namespace Platformsh\Cli\OAuth;

class Listener
{
    private $state;
    private $authUrl;
    private $clientId;
    private $file;
    private $localUrl;
    private $response;
    private $codeChallenge;
    private $prompt;

    public function __construct() {
        $required = [
            'CLI_OAUTH_STATE',
            'CLI_OAUTH_AUTH_URL',
            'CLI_OAUTH_CLIENT_ID',
            'CLI_OAUTH_FILE',
            'CLI_OAUTH_CODE_CHALLENGE',
            'CLI_OAUTH_PROMPT'
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
        $this->localUrl = $localUrl = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
        $this->response = new Response();
    }

    /**
     * @return string
     */
    private function getOAuthUrl()
    {
        return $this->authUrl . '?' . http_build_query([
            'redirect_uri' => $this->localUrl,
            'state' => $this->state,
            'client_id' => $this->clientId,
            'prompt' => $this->prompt,
            'response_type' => 'code',
            'code_challenge' => $this->codeChallenge,
            'code_challenge_method' => 'S256',
        ], null, '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check state, run logic, set page content.
     */
    public function run()
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
            if (!$this->sendToTerminal(['code' => $_GET['code']])) {
                $this->reportError('Failed to send authorization code back to terminal');
                return;
            }
            $this->setRedirect($this->localUrl . '/?done');
            $this->response->content = '<p>Authentication response received, please wait...</p>';

            return;
        }

        // Show the final result page.
        if (array_key_exists('done', $_GET)) {
            $this->response->content = '<h1>Successfully logged in</h1>'
                . '<p>You can return to the command line</p>';

            return;
        }

        // Respond after an OAuth2 error.
        if (isset($_GET['error'])) {
            $message = isset($_GET['error_description']) ? $_GET['error_description'] : null;
            $hint = isset($_GET['error_hint']) ? $_GET['error_hint'] : null;
            $this->reportError($message, $_GET['error'], $hint);
            return;
        }

        // In any other case: redirect to login.
        $url = $this->getOAuthUrl();
        $this->setRedirect($url);
        $this->response->content = '<p><a href="' . htmlspecialchars($url) .'">Log in</a>.</p>';
        return;
    }

    /**
     * @param string $url
     * @param int    $code
     */
    private function setRedirect($url, $code = 302)
    {
        $this->response->code = $code;
        $this->response->headers['Location'] = $url;
    }

    /**
     * @return \Platformsh\Cli\OAuth\Response
     */
    public function getResponse()
    {
        return $this->response;
    }

    /**
     * @param array $response
     *
     * @return bool
     */
    private function sendToTerminal(array $response)
    {
        return (bool) file_put_contents($this->file, json_encode($response), LOCK_EX);
    }

    /**
     * @param string $message The error message.
     * @param string|null $error An OAuth2 error type.
     * @param string|null $hint An OAuth2 error hint.
     */
    private function reportError($message = null, $error = null, $hint = null)
    {
        $this->response->headers['Status'] = 401;
        $this->response->content = '<h1 class="error">Error</h1>';
        if (isset($error)) {
            $this->response->content .= '<p class="error"><code>' . htmlspecialchars($error) . '</code></p>';
        }
        if (isset($message)) {
            $this->response->content .= '<p class="error">' . htmlspecialchars($message) . '</p>';
        }
        if (isset($hint)) {
            $this->response->content .= '<p class="error">' . htmlspecialchars($hint) . '</p>';
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
    public $headers = [];
    public $code = 200;
    public $title = '';
    public $content = '';

    public function __construct()
    {
        // Set default title and headers.
        $appName = getenv('CLI_OAUTH_APP_NAME') ?: 'CLI';
        $this->title = htmlspecialchars($appName) . ': Authentication (temporary URL)';
        $this->headers = [
            'Cache-Control' => 'no-cache',
            'Content-Type' => 'text/html; charset=utf-8',
        ];
    }
}

$listener = new Listener();
$listener->run();

$response = $listener->getResponse();

http_response_code($response->code);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title><?php echo $response->title; ?></title>
    <style>
        html {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-weight: 300;
            background-color: #eee;
        }

        h1 {
            font-weight: 100;
        }

        body {
            margin: 3em;
            text-align: center;
        }

        img {
            display: block;
            margin: 10px auto;
        }

        .error {
            color: darkred;
        }
        .error-hint {
            font-style: oblique;
        }
    </style>
</head>
<body>
    <img
        src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGQAAABkAQMAAABKLAcXAAAABlBMVEUAAADg4ODy8Xj7AAAAAXRSTlMAQObYZgAAAB5JREFUOMtj+I8EPozyRnlU4w1NMJhCcDT+hm2MAQAJBMb6YxK/8wAAAABJRU5ErkJggg=="
        alt=""
        width="100"
        height="100">

    <?php echo $response->content; ?>

</body>
</html>
