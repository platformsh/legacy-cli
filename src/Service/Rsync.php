<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Helper class which runs rsync.
 */
class Rsync
{

    private $shell;
    private $ssh;

    /**
     * Constructor.
     *
     * @param Shell|null $shellHelper
     * @param Ssh|null $ssh
     */
    public function __construct(Shell $shellHelper = null, Ssh $ssh = null)
    {
        $this->shell = $shellHelper ?: new Shell();
        $this->ssh = $ssh ?: new Ssh(new ArrayInput([]), new NullOutput());
    }

    /**
     * Returns environment variables for configuring rsync.
     *
     * @return array
     */
    private function env() {
        $env = [];
        if ($this->ssh->getSshArgs() !== []) {
            $env['RSYNC_RSH'] = $this->ssh->getSshCommand();
        }

        return $env;
    }

    /**
     * Finds whether the installed version of rsync supports the --iconv flag.
     *
     * @return bool|null
     */
    public function supportsConvertingFilenames()
    {
        static $supportsIconv;
        if (!isset($supportsIconv)) {
            $result = $this->shell->execute(['rsync', '-h']);
            if (is_string($result)) {
                $supportsIconv = strpos($result, '--iconv') !== false;
            }
        }

        return $supportsIconv;
    }

    /**
     * Syncs files from a local to a remote location.
     *
     * @param string $sshUrl
     * @param string $localDir
     * @param string $remoteDir
     * @param array  $options
     */
    public function syncUp($sshUrl, $localDir, $remoteDir, array $options = [])
    {
        // Ensure a trailing slash on the "from" path, to copy the directory's
        // contents rather than the directory itself.
        $from = rtrim($localDir, '/') . '/';
        $to = sprintf('%s:%s', $sshUrl, $remoteDir);
        $this->doSync($from, $to, $options);
    }

    /**
     * Syncs files from a remote to a local location.
     *
     * @param string $sshUrl
     * @param string $remoteDir
     * @param string $localDir
     * @param array  $options
     */
    public function syncDown($sshUrl, $remoteDir, $localDir, array $options = [])
    {
        $from = sprintf('%s:%s/', $sshUrl, $remoteDir);
        $to = $localDir;
        $this->doSync($from, $to, $options);
    }

    /**
     * Runs rsync.
     *
     * @param string $from
     * @param string $to
     * @param array  $options
     */
    private function doSync($from, $to, array $options = [])
    {
        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if (!empty($options['verbose'])) {
            $params[] = '-vv';
        } elseif (empty($options['quiet'])) {
            $params[] = '-v';
        }

        $params[] = $from;
        $params[] = $to;

        if (!empty($options['convert-mac-filenames'])) {
            $params[] = '--iconv=utf-8-mac,utf-8';
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

        $this->shell->execute($params, null, true, false, $this->env(), null);
    }
}
