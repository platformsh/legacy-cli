<?php
require '../vendor/autoload.php';
$config = new \Platformsh\Cli\Service\Config();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($config->get('application.name')) ?> | dev build</title>
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

    <h1><?= htmlspecialchars($config->get('application.name')) ?></h1>
    <h2>Development build</h2>

    <p>
        Download:
        <a href="<?= getenv('CLI_URL_PATH') ?: 'platform.phar' ?>"><?= getenv('CLI_URL_PATH') ?: 'platform.phar' ?></a>
    </p>
    <p>
        Build date:
        <code><?php
            $date = getenv('CLI_BUILD_DATE');
            if ($date !== false) {
                echo date('c', is_int($date) ? $date : strtotime($date));
            } else {
                echo 'unknown';
            }
            ?></code>
    </p>
    <p>
        SHA-256 hash:
        <code><?= hash_file('sha256', __DIR__ . '/' . ltrim(getenv('CLI_URL_PATH'), '/')) ?></code>
    </p>
    <p>
        Tree ID:
        <code><?= getenv('PLATFORM_TREE_ID') ?: 'unknown' ?></code>
    </p>

</body>
</html>
