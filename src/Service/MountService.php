<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Model\AppConfig;
use Platformsh\Client\Model\Environment;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MountService
{
    private $api;
    private $shell;
    private $stdErr;

    public function __construct(
        Api $api,
        OutputInterface $output,
        Shell $shell
    ) {
        $this->api = $api;
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->shell = $shell;
    }

    /**
     * Get the remote application config.
     *
     * @param Environment $environment
     * @param string      $appName
     * @param bool        $refresh
     *
     * @todo this should be in another service
     *
     * @return array
     */
    public function getAppConfig(Environment $environment, $appName, $refresh = true)
    {
        $webApp = $this->api
            ->getCurrentDeployment($environment, $refresh)
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
    public function getMountsAsOptions(array $mounts)
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
     *
     * @todo this should be in another service
     */
    public function validateDirectory(string $directory, bool $writable = false)
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
    public function runSync($sshUrl, $mountPath, $localPath, $up, array $options = [])
    {
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
        $this->shell->execute($params, null, true, false, [], null);

        $this->stdErr->writeln(sprintf('  time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_NORMAL);

        if ($up) {
            $this->stdErr->writeln('The upload completed successfully.');
        } else {
            $this->stdErr->writeln('The download completed successfully.');
        }
    }

    /**
     * Get a list of shared file mounts configured for an app.
     *
     * @param array $mounts The mounts.
     *
     * @return array
     *   An array of shared file paths, keyed by the mount path. Leading and
     *   trailing slashes are stripped. An empty shared path defaults to
     *   'files'.
     */
    public function getSharedFileMounts(array $mounts)
    {
        $sharedFileMounts = [];
        foreach ($this->normalizeMounts($mounts) as $path => $definition) {
            if (isset($definition['source_path'])) {
                $sharedFileMounts[$path] = $definition['source_path'] ?: 'files';
            }
        }

        return $sharedFileMounts;
    }

    /**
     * Normalize a list of mounts.
     *
     * @param array $mounts
     *
     * @return array
     */
    public static function normalizeMounts(array $mounts)
    {
        $normalized = [];
        foreach ($mounts as $path => $definition) {
            $normalized[self::normalizeRelativePath($path)] = self::normalizeDefinition($definition);
        }

        return $normalized;
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
    public function validateMountPath($inputPath, array $mounts)
    {
        $normalized = $this->normalizeRelativePath($inputPath);
        if (isset($mounts[$normalized])) {
            return $normalized;
        }

        throw new \InvalidArgumentException(sprintf('Mount not found: <error>%s</error>', $inputPath));
    }

    /**
     * Normalize a path to a mount.
     *
     * @param string $path
     *
     * @return string
     */
    private static function normalizeRelativePath($path)
    {
        return trim(trim($path), '/');
    }

    /**
     * Normalize a mount definition.
     *
     * @param array|string $definition
     *
     * @return array
     *   An array containing at least 'source', and probably 'source_path'.
     */
    private static function normalizeDefinition($definition)
    {
        if (!is_array($definition)) {
            if (!is_string($definition) || strpos($definition, 'shared:files') === false) {
                throw new \RuntimeException('Failed to parse mount definition: ' . json_encode($definition));
            }
            $definition = [
                'source' => 'local',
                'source_path' => str_replace('shared:files', '', $definition),
            ];
        } elseif (!isset($definition['source'])) {
            throw new \InvalidArgumentException('Invalid mount definition: ' . json_encode($definition));
        }
        if (isset($definition['source_path'])) {
            $definition['source_path'] = self::normalizeRelativePath($definition['source_path']);
        }

        return $definition;
    }
}
