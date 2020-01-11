<?php

namespace Platformsh\Cli\Session;

use Platformsh\Cli\Service\Config;
use Platformsh\Client\Session\SessionInterface;
use Platformsh\Client\Session\Storage\SessionStorageInterface;

/**
 * Store sessions using the Linux secret-tool utility.
 *
 * Install it with "sudo apt-get install libsecret-tools", or similar.
 */
class SecretToolStorage implements SessionStorageInterface
{
    private $appName;
    private $appId;

    /**
     * SecretToolStorage constructor.
     *
     * @param string $appName The name of the application storing keys.
     * @param string $appId   The ID of the application storing keys.
     */
    public function __construct($appName, $appId = '')
    {
        $this->appName = $appName;
        $this->appId = $appId ?: strtolower(preg_replace('/\W+/', '-', $this->appName));
    }

    /**
     * Check if this storage type is supported.
     *
     * @return bool
     */
    public static function isSupported()
    {
        return stripos(PHP_OS, 'Linux') !== false && self::exec(['command', '-v', 'secret-tool']) !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function load(SessionInterface $session)
    {
        $data = $this->exec(array_merge([
            'secret-tool',
            'lookup',
        ], $this->getAttributeArgs($session)));

        if (is_string($data)) {
            $session->setData($this->deserialize($data));
        } else {
            // If the secret doesn't exist yet, load it from an old file for
            // backwards compatibility.
            $this->loadFromFile($session);
        }
    }

    /**
     * Load the session from an old file for backwards compatibility.
     *
     * @param \Platformsh\Client\Session\SessionInterface $session
     */
    private function loadFromFile(SessionInterface $session)
    {
        $id = preg_replace('/[^\w\-]+/', '-', $session->getId());
        $dir = (new Config())->getSessionDir();
        $filename = "$dir/sess-$id/sess-$id.json";
        if (is_readable($filename) && ($contents = file_get_contents($filename))) {
            $data = json_decode($contents, true) ?: [];
            $session->setData($data);
            $this->save($session);
            // Reload via secret-tool, and delete the file if successful.
            if (rename($filename, $filename . '.bak')) {
                $this->load($session);
                if ($session->getData()) {
                    unlink($filename . '.bak');
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(SessionInterface $session)
    {
        $result = $this->exec(array_merge(
            [
                'secret-tool',
                'store',
                '--label=' . $this->appName,
            ],
            $this->getAttributeArgs($session)
        ), $this->serialize($session->getData()));
        if ($result === false) {
            throw new \RuntimeException('Failed to save the session to the keychain');
        }
    }

    /**
     * Delete keys for all session IDs.
     */
    public function deleteAll()
    {
        $this->exec(array_merge([
            'secret-tool',
            'clear',
        ], $this->getAttributeArgs(null)));
    }

    /**
     * Get attributes identifying the secret.
     *
     * @param SessionInterface|null $session
     *
     * @return string[]
     */
    private function getAttributeArgs(SessionInterface $session = null)
    {
        $args = [
            'app', $this->appId,
            'user', getenv('USER'),
        ];
        if ($session !== null) {
            $args[] = 'session';
            $args[] = $session->getId();
        }

        return $args;
    }

    /**
     * Execute the secret-tool command without displaying it or its result.
     *
     * @param string[] $args
     * @param string   $stdin
     *
     * @return string|false The command's stdout output, or false on failure.
     */
    private static function exec(array $args, $stdin = '')
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args)) . ' 2>/dev/null';
        $process = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w']], $pipes);
        if ($stdin !== '') {
            if (!fputs($pipes[0], $stdin)) {
                throw new \RuntimeException('Failed to write command input');
            }
            fclose($pipes[0]);
        }
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (proc_close($process) !== 0) {
            return false;
        }

        return $output;
    }

    /**
     * Serialize session data.
     *
     * @param array $data
     *
     * @return string
     */
    private function serialize(array $data)
    {
        return base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Deserialize session data.
     *
     * @param string $data
     *
     * @return array
     */
    private function deserialize($data)
    {
        $result = json_decode(base64_decode($data, true), true);

        return is_array($result) ? $result : [];
    }
}
