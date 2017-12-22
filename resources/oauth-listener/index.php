<?php

ob_start();

$header = <<<EOF
<!DOCTYPE html>
<html lang="en">
<head>
<title>CLI login (temporary window/tab)</title>
</head>
<body>
<h1>CLI login (temporary window/tab)</h1>
EOF;
$footer = <<<EOF
</body>
</html>
EOF;

$state = getenv('CLI_OAUTH_STATE');
$accounts_url = getenv('CLI_OAUTH_ACCOUNTS_URL');
$client_id = getenv('CLI_OAUTH_CLIENT_ID');
$file = getenv('CLI_OAUTH_FILE');
if (!$state || !$accounts_url || !$client_id || !$file) {
    exit('Invalid environment: ' . print_r($_ENV, true));
}

if (array_key_exists('done', $_GET)) {
    ob_end_clean();
    echo $header;
    echo <<<EOF
        <p><strong>Successfully logged in.</strong></p>
        <p>You can return to the command line.</p>
        <p>You may also wish to <a href="{$accounts_url}/user/logout">log out</a> in this browser.</p>
EOF;
    echo $footer;
    exit;
}

$localUrl = 'http://127.0.0.1:' . $_SERVER['SERVER_PORT'];

$url = $accounts_url . '/oauth2/authorize?' . http_build_query([
    'redirect_uri' => $localUrl,
    'state' => $state,
    'client_id' => $client_id,
    'response_type' => 'code',
], null, '&', PHP_QUERY_RFC3986);

if (!isset($_GET['state'], $_GET['code'])) {
    header('Location: ' . $url);
    exit;
}

try {
    if ($_GET['state'] !== $state) {
        throw new \RuntimeException('Invalid state');
    }

    if (!file_exists($file) || !is_writeable($file)) {
        throw new \RuntimeException('Failed to find file to save code');
    }

    if (!file_put_contents($file, $_GET['code'])) {
        throw new \RuntimeException('Failed to write authorization code to file');
    }

    header('Location: ' . $localUrl . '?done');
} catch (\RuntimeException $e) {
    http_response_code(401);
    echo $header;
    $message = htmlspecialchars($e->getMessage());
    echo <<<EOF
    <p>An error occurred while trying to log in. Please try again.</p>
    <p>Error message: <code>{$message}</code></p>
EOF;
    echo $footer;
}
