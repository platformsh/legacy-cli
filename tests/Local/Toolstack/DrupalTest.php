<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Helper\FilesystemHelper;

class DrupalTest extends BaseToolstackTest
{

    public function testBuildDrupalInProjectMode()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/drupal/project');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $shared = $projectRoot . '/' . self::$config->get('local.shared_dir');

        // Test build results.
        $this->assertFileExists($webRoot . '/index.php');

        // Test installation results.
        $this->assertFileExists($webRoot . '/sites/default/settings.php');
        $this->assertFileExists($shared . '/settings.local.php');

        // Test custom build hooks' results.
        // Build hooks are not Drupal-specific, but they can only run if the
        // build process creates a build directory outside the repository -
        // Drupal is the only current example of this.
        $this->assertFileNotExists($webRoot . '/robots.txt');
        $this->assertFileExists($webRoot . '/test.txt');
    }

    public function testBuildDrupal8()
    {
        $this->assertBuildSucceeds('tests/data/apps/drupal/8');
    }

    public function testBuildDrupalInProfileMode()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/drupal/profile');
        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $this->assertFileExists($webRoot . '/index.php');
        $this->assertFileExists($webRoot . '/sites/default/settings.php');
        $this->assertFileExists($webRoot . '/profiles/test/test.profile');
        $this->assertFileExists($webRoot . '/profiles/test/modules/platform/platform.module');
        $this->assertFileExists($webRoot . '/profiles/test/modules/test_module/test_module_file.php');
    }

    public function testBuildUpdateLock()
    {
        $sourceDir = 'tests/data/apps/drupal/8';
        self::$output->writeln("\nTesting build (with --lock) for directory: " . $sourceDir);
        $projectRoot = $this->assertBuildSucceeds($sourceDir, ['drushUpdateLock' => true]);
        $this->assertFileExists($projectRoot . '/project.make.yml.lock');
    }

    /**
     * Test the process of creating an archive of the build.
     *
     * This is not Drupal-specific, but this is the simplest example.
     */
    public function testArchiveAndExtract()
    {
        $projectRoot = $this->createDummyProject('tests/data/apps/drupal/project');

        // Archiving only works if the repository is a genuine Git directory.
        chdir($projectRoot);
        exec('git init');

        $treeId = $this->builder->getTreeId($projectRoot);
        $this->assertNotEmpty($treeId);

        // Build. This should create an archive.
        $this->builder->build($projectRoot);
        $archive = $projectRoot . '/' . self::$config->get('local.archive_dir')  .'/' . $treeId . '.tar.gz';
        $this->assertFileExists($archive);

        // Build again. This will extract the archive.
        $success = $this->builder->build($projectRoot);
        $this->assertTrue($success);
    }

    public function testDoNotSymlinkBuildsIntoSitesDefault()
    {
        $tempDir = self::$root->getName();
        $repository = tempnam($tempDir, '');
        unlink($repository);
        mkdir($repository);
        $fsHelper = new FilesystemHelper();
        $sourceDir = 'tests/data/apps/drupal/project';
        $fsHelper->copyAll($sourceDir, $repository);
        $wwwDir = $repository . '/www';

        // Run these tests twice to check that a previous build does not affect
        // the next one.
        for ($i = 1; $i <= 2; $i++) {
            $this->assertTrue($this->builder->build($repository, $wwwDir));
            $this->assertFileExists($wwwDir . '/sites/default/settings.php');
            $this->assertFileNotExists($wwwDir . '/sites/default/builds');
            $this->assertFileNotExists($wwwDir . '/sites/default/www');
        }
    }
}
