<?php

namespace CommerceGuys\Platform\Cli\Local;

use CommerceGuys\Platform\Cli\Helper\GitHelper;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Dumper;

class LocalProject
{

    /**
     * Create the default files for a project.
     *
     * @param string $projectRoot
     * @param string $projectId
     */
    public function createProjectFiles($projectRoot, $projectId)
    {
        mkdir($projectRoot . '/builds');
        mkdir($projectRoot . '/shared');

        // Create the .platform-project file.
        $projectConfig = array(
          'id' => $projectId,
        );
        $dumper = new Dumper();
        file_put_contents($projectRoot . '/.platform-project', $dumper->dump($projectConfig));
    }

    /**
     * Initialize a project in a directory.
     *
     * @param string $dir
     *
     * @throws \RuntimeException
     *
     * @return string The absolute path to the project.
     */
    public function initialize($dir) {
        $realPath = realpath($dir);
        if (!$realPath) {
            throw new \RuntimeException("Directory not readable: $dir");
        }

        $dir = $realPath;

        if (file_exists("$dir/../.platform-project")) {
            throw new \RuntimeException("The project is already initialized");
        }

        // Get the project ID from the Git repository.
        $projectId = $this->getProjectIdFromGit($dir);

        // Move the directory into a 'repository' subdirectory.
        $backupDir = $this->getBackupDir($dir);

        $fs = new Filesystem();
        $fs->rename($dir, $backupDir);
        $fs->mkdir($dir, 0755);
        $fs->rename($backupDir, "$dir/repository");

        $this->createProjectFiles($dir, $projectId);

        return $dir;
    }

    /**
     * @param string $dir
     *
     * @throws \RuntimeException
     *   If the directory is not a clone of a Platform.sh Git repository.
     *
     * @return string|false
     *   The project ID, or false if it cannot be determined.
     */
    protected function getProjectIdFromGit($dir)
    {
        if (!file_exists("$dir/.git")) {
            throw new \RuntimeException('The directory is not a Git repository');
        }
        $gitHelper = new GitHelper();
        $gitHelper->ensureInstalled();
        $originUrl = $gitHelper->getConfig("remote.origin.url", $dir);
        if (!$originUrl) {
            throw new \RuntimeException("Git remote 'origin' not found");
        }
        if (!preg_match('/^([a-z][a-z0-9]{12})@git\.[a-z\-]+\.platform\.sh:\1\.git$/', $originUrl, $matches)) {
            throw new \RuntimeException("The Git remote 'origin' is not a Platform.sh URL");
        }
        return $matches[1];
    }

    /**
     * Get a backup name for a directory.
     *
     * @param $dir
     * @param int $inc
     *
     * @return string
     */
    protected function getBackupDir($dir, $inc = 0)
    {
        $backupDir = $dir . '-backup';
        $backupDir .= $inc ?: '';
        if (file_exists($backupDir)) {
            return $this->getBackupDir($dir, ++$inc);
        }
        return $backupDir;
    }

}
