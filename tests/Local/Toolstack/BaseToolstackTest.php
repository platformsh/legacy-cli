<?php

namespace Platformsh\Cli\Tests\Toolstack;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\StreamOutput;

abstract class BaseToolstackTest extends \PHPUnit_Framework_TestCase
{

    /** @var vfsStreamDirectory */
    protected $root;

    /** @var LocalBuild */
    protected $builder;

    protected $showBuildOutput = false;

    protected $buildSettings = array('noClean' => true);

    /**
     * @{inheritdoc}
     */
    public function setUp()
    {
        $this->root = vfsStream::setup(__CLASS__);
        $this->builder = new LocalBuild(
          $this->buildSettings,
          $this->showBuildOutput ? new StreamOutput(fopen("php://output", 'w')) : null
        );
    }

    /**
     * Test building a project from dummy source code.
     *
     * @param string $sourceDir
     *   A directory containing source code for the project or app. Files will
     *   be copied into a dummy project.
     *
     * @return string
     *   The project root for the dummy project.
     */
    protected function assertBuildSucceeds($sourceDir)
    {
        $projectRoot = $this->createDummyProject($sourceDir);
        $success = $this->builder->buildProject($projectRoot);
        $this->assertTrue($success, 'Build success for dir: ' . $sourceDir);

        return $projectRoot;
    }

    /**
     * @param string $sourceDir
     *
     * @return string
     */
    protected function createDummyProject($sourceDir)
    {
        if (!is_dir($sourceDir)) {
            throw new \InvalidArgumentException("Not a directory: $sourceDir");
        }

        $tempDir = $this->root->getName();
        $projectRoot = tempnam($tempDir, '');
        unlink($projectRoot);
        mkdir($projectRoot);

        // Set up the project files.
        $local = new LocalProject();
        $local->createProjectFiles($projectRoot, 'testProjectId');

        // Make a dummy repository.
        $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        mkdir($repositoryDir);
        $fsHelper = new FilesystemHelper();
        $fsHelper->copyAll($sourceDir, $repositoryDir);

        return $projectRoot;
    }
}
