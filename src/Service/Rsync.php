<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Exception\ProcessFailedException;

/**
 * Helper class which runs rsync.
 */
readonly class Rsync
{
    /**
     * Constructor.
     *
     * @param Shell $shell
     * @param Ssh $ssh
     * @param SshDiagnostics $sshDiagnostics
     */
    public function __construct(private Shell $shell, private Ssh $ssh, private SshDiagnostics $sshDiagnostics) {}

    /**
     * Returns environment variables for configuring rsync.
     *
     * @return array<string, string>
     */
    private function env(string $sshUrl): array
    {
        return [
            'RSYNC_RSH' => $this->ssh->getSshCommand($sshUrl, omitUrl: true),
        ] + $this->ssh->getEnv();
    }

    /**
     * Finds whether the installed version of rsync supports the --iconv flag.
     *
     * @return bool|null
     */
    public function supportsConvertingFilenames(): ?bool
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
     * @param array{verbose?: bool, quiet?: bool, convert-mac-filenames?: bool, delete?: bool, include?: string[], exclude?: string[]} $options
     */
    public function syncUp(string $sshUrl, string $localDir, string $remoteDir, array $options = []): void
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
     * Syncs files from a remote to a local location
     *
     * @param array{verbose?: bool, quiet?: bool, convert-mac-filenames?: bool, delete?: bool, include?: string[], exclude?: string[]} $options
     */
    public function syncDown(string $sshUrl, string $remoteDir, string $localDir, array $options = []): void
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
     * @param array{verbose?: bool, quiet?: bool, convert-mac-filenames?: bool, delete?: bool, include?: string[], exclude?: string[]} $options
     */
    private function doSync(string $from, string $to, string $sshUrl, array $options = []): void
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

        $this->shell->mustExecute($params, quiet: false, env: $this->env($sshUrl), timeout: null);
    }
}
