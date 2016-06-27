<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalBuild;

class DrupalTest extends BaseToolstackTest
{

    public function testBuildDrupalInProjectMode()
    {
        $sourceDir = 'tests/data/apps/drupal/project';
        $projectRoot = $this->createDummyProject($sourceDir);

        $webRoot = $projectRoot . '/' . self::$config->get('local.web_root');
        $shared = $projectRoot . '/' . self::$config->get('local.shared_dir');
        $buildDir = $projectRoot . '/' . self::$config->get('local.build_dir') . '/default';

        // Insert a dummy file into 'shared'.
        if (!file_exists($shared)) {
            mkdir($shared, 0755, true);
        }
        touch($shared . '/symlink_me');

        self::$output->writeln("\nTesting build for directory: " . $sourceDir);
        $buildSettings = ['absoluteLinks' => true];
        $builder = new LocalBuild($buildSettings + $this->buildSettings, null, self::$output);
        $success = $builder->build($projectRoot);
        $this->assertTrue($success, 'Build success for dir: ' . $sourceDir);

        // Test build results.
        $this->assertFileExists($webRoot . '/index.php');

        // Test installation results: firstly, the mounts.
        $this->assertFileExists($webRoot . '/sites/default/files');
        $this->assertFileExists($buildDir . '/tmp');
        $this->assertFileExists($buildDir . '/private');
        $this->assertFileExists($buildDir . '/drush-backups');
        $this->assertEquals($shared . '/files', readlink($webRoot . '/sites/default/files'));
        $this->assertEquals($shared . '/tmp', readlink($buildDir . '/tmp'));
        $this->assertEquals($shared . '/private', readlink($buildDir . '/private'));
        $this->assertEquals($shared . '/drush-backups', readlink($buildDir . '/drush-backups'));

        // Secondly, the special Drupal settings files.
        $this->assertFileExists($webRoot . '/sites/default/settings.php');
        $this->assertFileExists($webRoot . '/sites/default/settings.local.php');

        // Thirdly, the ability for any files in 'shared' to be symlinked into
        // sites/default (this is a legacy feature of the CLI's Drupal
        // toolstack).
        $this->assertFileExists($webRoot . '/sites/default/symlink_me');

        // Test custom build hooks' results.
        // Build hooks are not Drupal-specific, but they can only run if the
        // build process creates a build directory outside the repository -
        // Drupal is the only current example of this.
        $this->assertFileNotExists($webRoot . '/robots.txt');
        $this->assertFileExists($webRoot . '/test.txt');
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
        $sourceDir = 'tests/data/apps/drupal/yaml';
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
