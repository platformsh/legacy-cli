<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\ProcessFailedException;

/**
 * Helper class which runs rsync.
 */
class Rsync
{

    /**
     * Constructor.
     *
     * @param Shell $shell
     * @param Ssh $ssh
     * @param SshDiagnostics $sshDiagnostics
     */
    public function __construct(private readonly Shell $shell, private readonly Ssh $ssh, private readonly SshDiagnostics $sshDiagnostics)
    {
    }

    /**
     * Returns environment variables for configuring rsync.
     *
     * @param string $sshUrl
     *
     * @return array
     */
    private function env($sshUrl) {
        return [
            'RSYNC_RSH' => $this->ssh->getSshCommand($sshUrl, [], null, true),
        ] + $this->ssh->getEnv();
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
                $supportsIconv = str_contains($result, '--iconv');
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
    public function syncUp($sshUrl, $localDir, $remoteDir, array $options = []): void
    {
        // Ensure a trailing slash on the "from" path, to copy the directory's
        // contents rather than the directory itself.
        $from = rtrim($localDir, '/') . '/';
        $to = sprintf('%s:%s', $sshUrl, $remoteDir);
        try {
            $this->doSync($from, $to, $sshUrl, $options);
        } catch (ProcessFailedException $e) {
            $this->sshDiagnostics->diagnoseFailure($sshUrl, $e->getProcess());
            throw new ProcessFailedException($e->getProcess(), false);
        }
    }

    /**
     * Syncs files from a remote to a local location.
     *
     * @param string $sshUrl
     * @param string $remoteDir
     * @param string $localDir
     * @param array  $options
     */
    public function syncDown($sshUrl, $remoteDir, $localDir, array $options = []): void
    {
        $from = sprintf('%s:%s/', $sshUrl, $remoteDir);
        $to = $localDir;
        try {
            $this->doSync($from, $to, $sshUrl, $options);
        } catch (ProcessFailedException $e) {
            $this->sshDiagnostics->diagnoseFailure($sshUrl, $e->getProcess());
            throw new ProcessFailedException($e->getProcess(), false);
        }
    }

    /**
     * Runs rsync.
     *
     * @param string $from
     * @param string $to
     * @param string $sshUrl
     * @param array $options
     */
    private function doSync(string $from, $to, $sshUrl, array $options = []): void
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

        // Add include and exclude rules.
        //
        // The --include option should be placed before --exclude. From the
        // rsync manual:
        // "The order of the rules is important because the first rule that
        // matches is the one that takes effect.  Thus, if an early rule
        // excludes a file, no include rule that comes after it can have any
        // effect. This means that you must place any include overrides
        // somewhere prior to the exclude that it is intended to limit."
        foreach (['include', 'exclude'] as $option) {
            if (!empty($options[$option])) {
                foreach ($options[$option] as $value) {
                    $params[] = '--' . $option . '=' . $value;
                }
            }
        }

        $this->shell->execute($params, null, true, false, $this->env($sshUrl), null);
    }
}
