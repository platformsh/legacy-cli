<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Output\OutputInterface;

abstract class MountSyncCommandBase extends CommandBase
{

    /**
     * Get the remote application config.
     *
     * @param string $sshUrl
     * @param bool   $refresh
     *
     * @return array
     */
    protected function getAppConfig($sshUrl, $refresh = true)
    {
        /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVarService */
        $envVarService = $this->getService('remote_env_vars');

        $result = $envVarService->getEnvVar('APPLICATION', $sshUrl, $refresh);

        return (array) json_decode(base64_decode($result), true);
    }

    /**
     * Format the mounts as an array of options for a ChoiceQuestion.
     *
     * @param array $mounts
     *
     * @return array
     */
    protected function getMountsAsOptions(array $mounts)
    {
        $options = [];
        foreach ($mounts as $path => $id) {
            $normalized = $this->normalizeMountPath($path);
            $options[$normalized] = sprintf('<question>%s</question>: %s', $normalized, trim($id, '/'));
        }

        return $options;
    }

    /**
     * Get the path under '.platform/local/shared' for a mount.
     *
     * @param string $path
     * @param array  $mounts
     *
     * @return string|false
     */
    protected function getSharedPath($path, array $mounts)
    {
        $normalized = $this->normalizeMountPath($path);
        foreach ($mounts as $path => $uri) {
            if ($this->normalizeMountPath($path) === $normalized
                && preg_match('#^shared:files/(.+)$#', $uri, $matches)) {
                return trim($matches[1], '/');
            }
        }

        return false;
    }

    /**
     * Normalize a path to a mount.
     *
     * @param string $path
     *
     * @return string
     */
    protected function normalizeMountPath($path)
    {
        return trim(trim($path), '/');
    }

    /**
     * Validate and normalize a path to a mount.
     *
     * @param string $inputPath
     * @param array  $mounts
     *
     * @return string
     *   The normalized mount path.
     */
    protected function validateMountPath($inputPath, array $mounts)
    {
        $normalized = trim(trim($inputPath), '/');
        foreach (array_keys($mounts) as $path) {
            if (trim(trim($path), '/') === $normalized) {
                return $normalized;
            }
        }

        throw new \InvalidArgumentException(sprintf('Mount not found: <error>%s</error>', $inputPath));
    }

    /**
     * Validate a directory argument.
     *
     * @param string $directory
     * @param bool   $writable
     */
    protected function validateDirectory($directory, $writable = false)
    {
        if (!is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not found: %s', $directory));
        } elseif (!is_readable($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not readable: %s', $directory));
        } elseif ($writable && !is_writable($directory)) {
            throw new \InvalidArgumentException(sprintf('Directory not writable: %s', $directory));
        }
    }

    /**
     * Push the local contents to the chosen mount.
     *
     * @param string $sshUrl
     * @param string $mountPath
     * @param string $localPath
     * @param bool   $up
     * @param bool   $delete
     */
    protected function runSync($sshUrl, $mountPath, $localPath, $up, $delete = false)
    {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $mountPathAbsolute = $this->getAppDir($sshUrl) . '/' . $mountPath;

        if ($up) {
            $this->stdErr->writeln(sprintf('Uploading files from <info>%s</info> to the remote mount <info>%s</info>', $localPath, $mountPathAbsolute));
        } else {
            $this->stdErr->writeln(sprintf('Downloading files from the remote mount <info>%s</info> to <info>%s</info>', $mountPathAbsolute, $localPath));
        }

        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if ($this->stdErr->isVeryVerbose()) {
            $params[] = '-vv';
        } elseif (!$this->stdErr->isQuiet()) {
            $params[] = '-v';
        }

        if ($up) {
            $params[] = rtrim($localPath, '/') . '/';
            $params[] = sprintf('%s:%s', $sshUrl, $mountPathAbsolute);
        } else {
            $params[] = sprintf('%s:%s/', $sshUrl, $mountPathAbsolute);
            $params[] = $localPath;
        }

        if ($delete) {
            $params[] = '--delete';
        }

        $start = microtime(true);
        $shell->execute($params, null, true, false, [], null);

        $this->stdErr->writeln(sprintf('  time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_NORMAL);

        if ($up) {
            $this->stdErr->writeln('The upload completed successfully.');
        } else {
            $this->stdErr->writeln('The download completed successfully.');
        }
    }

    /**
     * @param string $sshUrl
     *
     * @return string
     */
    private function getAppDir($sshUrl)
    {
        /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVarService */
        $envVarService = $this->getService('remote_env_vars');

        return $envVarService->getEnvVar('APP_DIR', $sshUrl, false, 86400) ?: '/app';
    }
}
