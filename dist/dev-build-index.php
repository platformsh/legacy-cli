<?php /** @noinspection PhpLanguageLevelInspection */
declare(strict_types=1);
/**
 * @file
 * This is the index.php script for automated CLI builds on Platform.sh.
 */

use Platformsh\Cli\Service\Config;

require '../vendor/autoload.php';
$config = new Config();
$appName = $config->get('application.name');
$envPrefix = $config->get('service.env_prefix');
$branch = getenv($envPrefix . 'BRANCH', true);
$treeId = getenv($envPrefix . 'TREE_ID', true);

$pharUrl = getenv('CLI_URL_PATH', true) ?: 'platform.phar';
$pharHash = hash_file('sha256', __DIR__ . '/' . ltrim(getenv('CLI_URL_PATH', true), '/'));
if ($timestamp = getenv('CLI_BUILD_DATE', true)) {
    $pharDate = date('c', is_int($timestamp) ? $timestamp : strtotime($timestamp));
} else {
    $pharDate = false;
}

if ($config->has('application.github_repo')) {
    $sourceLink = 'https://github.com/' . $config->get('application.github_repo');
    $sourceLinkSpecific = $sourceLink;
    if ($branch) {
        if (strpos($branch, 'pr-') === 0 && is_numeric(substr($branch, 3))) {
            $sourceLinkSpecific .= '/pull/' . substr($branch, 3);
        } else {
            $sourceLinkSpecific .= '/tree/' . rawurlencode($branch);
        }
    }
} else {
    $sourceLink = false;
    $sourceLinkSpecific = false;
}

$baseUrl = 'https://' . $_SERVER['HTTP_HOST'];
$installScript = sprintf(
    'curl -sfS %s | php -- --dev --manifest %s',
    $baseUrl . '/installer',
    $baseUrl . '/manifest.json',
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($appName) ?> | dev build</title>
    <style>
        html {
            font-family: "Helvetica Neue", Helvetica, Arial, sans-serif;
            font-weight: 300;
            background-color: #eee;
        }

        h1 {
            font-weight: 100;
        }
        h2 {
            font-weight: 400;
        }
        h1, h2 {
            text-align: center;
        }
        h1 a {
            color: inherit !important;
            text-decoration: none !important;
        }

        body {
            margin: 3em;
        }

        p {
            max-width: 40em;
            margin: 1em auto;
            word-break: break-all;
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

    <?php if ($sourceLink): ?>
        <h1><a href="<?= htmlspecialchars($sourceLink) ?>"><?= htmlspecialchars($appName) ?></a></h1>
    <?php else: ?>
        <h1>><?= htmlspecialchars($appName) ?></h1>
    <?php endif; ?>
    <h2>Development build</h2>

    <?php if ($pharUrl): ?>
        <p>
            Download: <a href="<?= htmlspecialchars($pharUrl) ?>"><?= htmlspecialchars($pharUrl) ?></a>
        </p>
    <?php endif; ?>
    <?php if ($pharDate): ?>
        <p>
            Build date: <code><?= htmlspecialchars($pharDate) ?></code>
        </p>
    <?php endif; ?>
    <?php if ($pharHash): ?>
        <p>
            SHA-256 hash: <code><?= htmlspecialchars($pharHash) ?></code>
        </p>
    <?php endif; ?>
    <?php if ($treeId): ?>
        <p>
            Tree ID: <code><?= htmlspecialchars($treeId) ?></code>
        </p>
    <?php endif; ?>
    <?php if ($branch): ?>
        <p>
            Branch: <code><?= htmlspecialchars($branch) ?></code>
        </p>
    <?php endif; ?>
    <?php if ($sourceLinkSpecific): ?>
        <p>
            Source: <a href="<?= htmlspecialchars($sourceLinkSpecific) ?>"><?= htmlspecialchars($sourceLinkSpecific) ?></a>
        </p>
    <?php endif; ?>
    <?php if ($installScript): ?>
        <p>
            Install with:<br/>
            <code><?= htmlspecialchars($installScript) ?></code>
        </p>
    <?php endif; ?>

</body>
</html>
