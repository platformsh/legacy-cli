<?php

namespace Platformsh\Cli\Service;

/**
 * Helper class which runs rsync.
 */
class Rsync
{

    private $shell;

    /**
     * Constructor.
     *
     * @param Shell|null $shellHelper
     */
    public function __construct(Shell $shellHelper = null)
    {
        $this->shell = $shellHelper ?: new Shell();
    }

    /**
     * Syncs files from a local to a remote location.
     *
     * @param string $sshUrl
     * @param string $localPath
     * @param string $remotePath
     * @param array  $options
     */
    public function syncUp($sshUrl, $localPath, $remotePath, array $options = [])
    {
        $this->doSync($sshUrl, $remotePath, $localPath, true, $options);
    }

    /**
     * Syncs files from a remote to a local location.
     *
     * @param string $sshUrl
     * @param string $remotePath
     * @param string $localPath
     * @param array  $options
     */
    public function syncDown($sshUrl, $remotePath, $localPath, array $options = [])
    {
        $this->doSync($sshUrl, $remotePath, $localPath, false, $options);
    }

    /**
     * Runs rsync.
     *
     * @param string $sshUrl
     * @param string $remotePath
     * @param string $localPath
     * @param bool   $up
     * @param array  $options
     */
    private function doSync($sshUrl, $remotePath, $localPath, $up, array $options = [])
    {
        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if (!empty($options['verbose'])) {
            $params[] = '-vv';
        } elseif (empty($options['quiet'])) {
            $params[] = '-v';
        }

        if ($up) {
            $params[] = rtrim($localPath, '/') . '/';
            $params[] = sprintf('%s:%s', $sshUrl, $remotePath);
        } else {
            $params[] = sprintf('%s:%s/', $sshUrl, $remotePath);
            $params[] = $localPath;
        }

        if (!empty($options['delete'])) {
            $params[] = '--delete';
        }
        foreach (['exclude', 'include'] as $option) {
            if (!empty($options[$option])) {
                foreach ($options[$option] as $value) {
                    $params[] = '--' . $option . '=' . $value;
                }
            }
        }

        $this->shell->execute($params, null, true, false, [], null);
    }
}
