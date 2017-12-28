<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Symfony\Component\Console\Output\OutputInterface;

abstract class MountCommandBase extends CommandBase
{
    /**
     * Get the remote application config.
     *
     * @param string $appName
     * @param bool   $refresh
     *
     * @return array
     */
    protected function getAppConfig($appName, $refresh = true)
    {
        $webApp = $this->api()
            ->getCurrentDeployment($this->getSelectedEnvironment(), $refresh)
            ->getWebApp($appName);

        return AppConfig::fromWebApp($webApp)->getNormalized();
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
     * @param string $appName
     * @param string $mountPath
     * @param string $localPath
     * @param bool   $up
     * @param array  $options
     */
    protected function runSync($appName, $mountPath, $localPath, $up, array $options = [])
    {
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($appName);

        $params = ['rsync', '--archive', '--compress', '--human-readable'];

        if ($this->stdErr->isVeryVerbose()) {
            $params[] = '-vv';
        } elseif (!$this->stdErr->isQuiet()) {
            $params[] = '-v';
        }

        if ($up) {
            $params[] = rtrim($localPath, '/') . '/';
            $params[] = sprintf('%s:%s', $sshUrl, $mountPath);
        } else {
            $params[] = sprintf('%s:%s/', $sshUrl, $mountPath);
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
}
