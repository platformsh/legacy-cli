<?php

namespace Platformsh\Cli\SiteAlias;

use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;

abstract class DrushAlias implements SiteAliasTypeInterface
{
    protected $drush;
    protected $config;

    public function __construct(Drush $drush, Config $config)
    {
        $this->drush = $drush;
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     */
    public function createAliases(Project $project, $aliasGroup, array $apps, array $environments, $previousGroup = null)
    {
        if (!count($apps)) {
            return false;
        }

        // Prepare the Drush directory and file.
        $drushDir = $this->getDrushDir();
        if (!is_dir($drushDir) && !mkdir($drushDir, 0755)) {
            throw new \RuntimeException('Drush aliases directory not found: ' . $drushDir);
        }
        if (!is_writable($drushDir)) {
            throw new \RuntimeException('Drush aliases directory not writable: ' . $drushDir);
        }
        $filename = $this->getFilename($aliasGroup, $drushDir);
        if (file_exists($filename) && !is_writable($filename)) {
            throw new \RuntimeException("Drush alias file not writable: $filename");
        }

        // Gather existing aliases from this file and from the previous file.
        $filesToCheck = [$filename];
        if ($previousGroup !== null) {
            $filesToCheck[] = $this->getFilename($previousGroup, $drushDir);
        }
        $existingAliases = $this->getExistingAliases($filesToCheck);

        // Generate the new aliases.
        $newAliases = $this->generateNewAliases($apps, $environments);

        // Merge new aliases with existing ones.
        foreach ($newAliases as $aliasName => &$newAlias) {
            // If the alias already exists, recursively replace existing
            // settings with new ones.
            if (isset($existingAliases[$aliasName])) {
                $newAlias['alias'] = array_replace_recursive($existingAliases[$aliasName], $newAlias['alias']);
                unset($existingAliases[$aliasName]);
            }
        }

        // Add any user-defined (pre-existing) aliases.
        $autoRemoveKey = $this->getAutoRemoveKey();
        $userDefinedAliases = [];
        foreach ($existingAliases as $name => $alias) {
            if (!empty($alias[$autoRemoveKey])) {
                // This is probably for a deleted environment.
                continue;
            }
            $userDefinedAliases[$name] = [
                'comment' => sprintf('User-defined alias "%s".', $name),
                'alias' => $alias
            ];
        }

        $aliases = $userDefinedAliases + $newAliases;

        // Format the aliases as a string (code and comments).
        $content = $this->getHeader($project) . $this->formatAliases($aliases);

        $this->writeAliasFile($filename, $content);

        return true;
    }

    /**
     * Get the filename for the aliases.
     *
     * @param string $groupName
     * @param string $drushDir
     *
     * @return string
     */
    abstract protected function getFilename($groupName, $drushDir);

    /**
     * Get the header at the top of the file.
     *
     * @param Project $project
     *
     * @return string
     */
    abstract protected function getHeader(Project $project);

    /**
     * Format a comment.
     *
     * @param string $comment
     *
     * @return string
     */
    abstract protected function formatComment($comment);

    /**
     * Find the existing defined aliases so they can be merged with new ones.
     *
     * @param string[] $filenames
     *
     * @return array
     */
    abstract protected function getExistingAliases(array $filenames);

    /**
     * Find the Drush directory, where site aliases should be stored.
     *
     * @return string
     */
    private function getDrushDir()
    {
        $homeDir = Filesystem::getHomeDirectory();
        $drushDir = $homeDir . '/.drush';
        if (file_exists($drushDir . '/site-aliases')) {
            $drushDir = $drushDir . '/site-aliases';
        }

        return $drushDir;
    }

    /**
     * Generate new aliases.
     *
     * @param array $apps
     * @param array $environments
     *
     * @return array
     */
    private function generateNewAliases(array $apps, array $environments)
    {
        $autoRemoveKey = $this->getAutoRemoveKey();
        $localWebRoot = $this->config->get('local.web_root');
        $aliases = [];

        foreach ($apps as $app) {
            $appId = $app->getId();

            // Generate aliases for the remote environments.
            foreach ($environments as $environment) {
                $alias = $this->generateRemoteAlias($environment, $app, !$app->isSingle());
                if (!$alias) {
                    continue;
                }

                $aliasName = $environment->id;
                if (count($apps) > 1) {
                    $aliasName .= '--' . $appId;
                }

                $aliases[$aliasName] = [
                    'alias' => $alias,
                    'comment' => sprintf(
                        'Automatically generated alias for the environment "%s", application "%s".',
                        $environment->title,
                        $appId
                    ),
                ];
            }

            // Generate an alias for the local environment.
            $localAliasName = '_local';
            $webRoot = $app->getSourceDir() . '/' . $localWebRoot;
            if (count($apps) > 1) {
                $localAliasName .= '--' . $appId;
            }
            if (!$app->isSingle()) {
                $webRoot .= '/' . $appId;
            }
            $aliases[$localAliasName] = [
                'alias' => [
                    'root' => $webRoot,
                    $autoRemoveKey => true,
                ],
                'comment' => sprintf(
                    'Automatically generated alias for the local environment, application "%s".',
                    $appId
                ),
            ];
        }

        return $aliases;
    }

    /**
     * Format a list of aliases as a string.
     *
     * @param array $aliases
     *   A list of aliases, each an element containing 'alias' and 'comment'.
     *
     * @return string
     */
    protected function formatAliases(array $aliases)
    {
        $formatted = [];
        foreach ($aliases as $aliasName => $newAlias) {
            $formatted[] = $this->formatAlias($newAlias['alias'], $aliasName, isset($newAlias['comment']) ? $newAlias['comment'] : '');
        }

        return implode("\n", $formatted);
    }

    /**
     * Format a single Drush site alias as a string.
     *
     * @param string $name    The alias name (the name of the environment).
     * @param array  $alias   The alias, as an array.
     * @param string $comment A comment to to describe the alias (optional).
     *
     * @return string
     */
    abstract protected function formatAlias(array $alias, $name, $comment = '');

    /**
     * Generate a remote Drush alias.
     *
     * @param Environment $environment
     * @param LocalApplication $app
     * @param bool $multiApp
     *
     * @return array|false
     */
    private function generateRemoteAlias($environment, $app, $multiApp = false)
    {
        if (!$environment->hasLink('ssh') || !$environment->hasLink('public-url')) {
            return false;
        }
        $sshUrl = parse_url($environment->getLink('ssh'));
        if (!$sshUrl) {
            return false;
        }
        $sshUser = $sshUrl['user'];
        if ($multiApp) {
            $sshUser .= '--' . $app->getName();
        }

        $uri = $environment->getLink('public-url');
        if ($multiApp) {
            $guess = str_replace('http://', 'http://' . $app->getName() . '---', $uri);
            if (in_array($guess, $environment->getRouteUrls())) {
                $uri = $guess;
            }
        }

        return [
            'uri' => $uri,
            'remote-host' => $sshUrl['host'],
            'remote-user' => $sshUser,
            'root' => '/app/' . $app->getDocumentRoot(),
            $this->getAutoRemoveKey() => true,
        ];
    }

    /**
     * @return string
     */
    private function getAutoRemoveKey()
    {
        return preg_replace(
                '/[^a-z-]+/',
                '-',
                str_replace('.', '', strtolower($this->config->get('application.name')))
            ) . '-auto-remove';
    }

    /**
     * Write a file and create a backup if the contents have changed.
     *
     * @param string $filename
     * @param string $contents
     */
    private function writeAliasFile($filename, $contents)
    {
        $fs = new SymfonyFilesystem();
        if (is_readable($filename) && $contents !== file_get_contents($filename)) {
            $backupName = dirname($filename) . '/' . basename($filename) . '.bak';
            $fs->rename($filename, $backupName, true);
        }
        $fs->dumpFile($filename, $contents);
    }
}
