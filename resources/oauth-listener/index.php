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

    public function __construct() {
        $required = [
            'CLI_OAUTH_STATE',
            'CLI_OAUTH_AUTH_URL',
            'CLI_OAUTH_CLIENT_ID',
            'CLI_OAUTH_FILE',
        ];
        if ($missing = array_diff($required, array_keys($_ENV))) {
            throw new \RuntimeException('Invalid environment, missing: ' . implode(', ', $missing));
        }
        $this->state = $_ENV['CLI_OAUTH_STATE'];
        $this->authUrl = $_ENV['CLI_OAUTH_AUTH_URL'];
        $this->clientId = $_ENV['CLI_OAUTH_CLIENT_ID'];
        $this->file = $_ENV['CLI_OAUTH_FILE'];
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
            'response_type' => 'code',
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
            if (!file_put_contents($this->file, $_GET['code'], LOCK_EX)) {
                $this->reportError('Failed to write authorization code to file');
                return;
            }
            $this->setRedirect($this->localUrl . '/?done');
            $this->response->content = '<p>Authentication response received, please wait...</p>';

            return;
        }

        // Show the final result page.
        if (array_key_exists('done', $_GET)) {
            $this->response->content = '<p><strong>Successfully logged in.</strong></p>'
                . '<p>You can return to the command line.</p>';

            return;
        }

        // Redirect to login.
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
     * @param string $message
     */
    private function reportError($message)
    {
        $this->response->headers['Status'] = 401;
        $this->response->content = '<p>An error occurred while trying to log in. Please try again.</p>'
            . '<p>Error message: <code>' . htmlspecialchars($message) . '</code></p>';
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
