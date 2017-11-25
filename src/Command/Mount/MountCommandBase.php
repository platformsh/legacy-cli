<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Output\OutputInterface;

abstract class MountCommandBase extends CommandBase
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
        foreach ($mounts as $path => $definition) {
            if ($definition['source'] === 'local' && isset($definition['source_path'])) {
                $options[$path] = sprintf('<question>%s</question> (shared:files/%s)', $path, $definition['source_path']);
            } else {
                $options[$path] = sprintf('<question>%s</question>: %s', $path, $definition['source']);
            }
        }

        return $options;
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
     * @param array  $options
     */
    protected function runSync($sshUrl, $mountPath, $localPath, $up, array $options = [])
    {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $mountPathAbsolute = $this->getAppDir($sshUrl) . '/' . $mountPath;

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
