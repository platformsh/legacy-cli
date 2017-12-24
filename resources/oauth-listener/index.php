<?php

namespace Platformsh\Cli\OAuth;

class Listener
{
    private $state;
    private $accountsUrl;
    private $clientId;
    private $file;
    private $localUrl;

    public function __construct() {
        $required = [
            'CLI_OAUTH_STATE',
            'CLI_OAUTH_ACCOUNTS_URL',
            'CLI_OAUTH_CLIENT_ID',
            'CLI_OAUTH_FILE',
        ];
        if ($missing = array_diff($required, array_keys($_ENV))) {
            throw new \RuntimeException('Invalid environment, missing: ' . implode(', ', $missing));
        }
        $this->state = $_ENV['CLI_OAUTH_STATE'];
        $this->accountsUrl = $_ENV['CLI_OAUTH_ACCOUNTS_URL'];
        $this->clientId = $_ENV['CLI_OAUTH_CLIENT_ID'];
        $this->file = $_ENV['CLI_OAUTH_FILE'];
        $this->localUrl = $localUrl = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];
    }

    /**
     * @return string
     */
    private function getOAuthUrl()
    {
        return $this->accountsUrl . '/oauth2/authorize?' . http_build_query([
            'redirect_uri' => $this->localUrl,
            'state' => $this->state,
            'client_id' => $this->clientId,
            'response_type' => 'code',
        ], null, '&', PHP_QUERY_RFC3986);
    }

    /**
     * Check state, run logic, return page content.
     *
     * @return string
     */
    public function run()
    {
        if (array_key_exists('done', $_GET)) {
            $content = '<p><strong>Successfully logged in.</strong></p>';
            $content .= '<p>You can return to the command line.</p>';

            // @todo find a way to avoid this
            $logoutUrl = htmlspecialchars($this->accountsUrl . '/user/logout');
            $content .= '<p>You may also wish to <a href="' . $logoutUrl . '">log out</a> in this browser.</p>';

            return $content;
        }
        if (!isset($_GET['state'], $_GET['code'])) {
            return $this->redirectToLogin();
        }
        if ($_GET['state'] !== $this->state) {
            return $this->reportError('Invalid state parameter');
        }
        if (!file_put_contents($this->file, $_GET['code'], LOCK_EX)) {
            return $this->reportError('Failed to write authorization code to file');
        }

        header('Location: ' . $this->localUrl . '/?done');

        return '<p>Logging in, please wait...</p>';
    }

    /**
     * @return string
     */
    private function redirectToLogin()
    {
        $url = $this->getOAuthUrl();
        header('Location: ' . $url);

        return '<p><a href="' . htmlspecialchars($url) .'">Log in</a>.</p>';
    }

    /**
     * @return string
     */
    private function reportError($message)
    {
        http_response_code(401);
        $message = htmlspecialchars($message);

        return <<<EOF
    <p>An error occurred while trying to log in. Please try again.</p>
    <p>Error message: <code>{$message}</code></p>
EOF;
    }
}

$content = (new Listener())->run();
?>
<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta charset="utf-8">
    <title>CLI Login</title>
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
        }

        img {
            display: block;
            width: 100px;
            height: 100px;
            margin: 0 auto;
        }

        div {
            max-width: 20em;
            margin: 0 auto;
            text-align: center;
        }
    </style>
</head>
<body>
<div>
    <img
        src="data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAGQAAABkAQMAAABKLAcXAAAABlBMVEUAAADg4ODy8Xj7AAAAAXRSTlMAQObYZgAAAB5JREFUOMtj+I8EPozyRnlU4w1NMJhCcDT+hm2MAQAJBMb6YxK/8wAAAABJRU5ErkJggg=="
        alt="">
    <?php echo $content; ?>
</div>
</body>
</html>
