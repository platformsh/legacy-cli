<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\Local\LocalProject;

class DrupalTest extends BaseToolstackTest
{

    public function testBuildDrupalInProjectMode()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/drupal/project');
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $shared = $projectRoot . '/' . LocalProject::SHARED_DIR;

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

    public function testBuildDrupalInProfileMode()
    {
        $projectRoot = $this->assertBuildSucceeds('tests/data/apps/drupal/profile');
        $webRoot = $projectRoot . '/' . LocalProject::WEB_ROOT;
        $this->assertFileExists($webRoot . '/index.php');
        $this->assertFileExists($webRoot . '/sites/default/settings.php');
        $this->assertFileExists($webRoot . '/profiles/test/test.profile');
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
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        chdir($repositoryDir);
        exec('git init');

        $treeId = $this->builder->getTreeId($repositoryDir);
        $this->assertNotEmpty($treeId);

        // Build. This should create an archive.
        $this->builder->buildProject($projectRoot);
        $archive = $projectRoot . '/' . LocalProject::ARCHIVE_DIR  .'/' . $treeId . '.tar.gz';
        $this->assertFileExists($archive);

        // Build again. This will extract the archive.
        $success = $this->builder->buildProject($projectRoot);
        $this->assertTrue($success);
    }
}
