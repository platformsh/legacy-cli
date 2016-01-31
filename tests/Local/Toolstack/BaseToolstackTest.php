<?php

namespace Platformsh\Cli\Tests\Toolstack;

use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\ConsoleOutput;

abstract class BaseToolstackTest extends \PHPUnit_Framework_TestCase
{

    /** @var vfsStreamDirectory */
    protected static $root;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    protected static $output;

    /** @var LocalBuild */
    protected $builder;

    protected $buildSettings = ['noClean' => true];

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass()
    {
        self::$root = vfsStream::setup(__CLASS__);
        self::$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, false);
    }

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $this->builder = new LocalBuild(
            $this->buildSettings,
            self::$output
        );
    }

    /**
     * {@inheritdoc}
     */
    public static function tearDownAfterClass()
    {
        exec('rm -Rf ' . escapeshellarg(self::$root->getName()));
    }

    /**
     * Test building a project from dummy source code.
     *
     * @param string $sourceDir
     *   A directory containing source code for the project or app. Files will
     *   be copied into a dummy project.
     * @param array $buildSettings
     *   An array of custom build settings.
     *
     * @return string
     *   The project root for the dummy project.
     */
    protected function assertBuildSucceeds($sourceDir, array $buildSettings = [])
    {
        $projectRoot = $this->createDummyProject($sourceDir);
        self::$output->writeln("\nTesting build for directory: " . $sourceDir);
        $builder = $buildSettings
            ? new LocalBuild($buildSettings + $this->buildSettings, self::$output)
            : $this->builder;
        $success = $builder->buildProject($projectRoot);
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

        $tempDir = self::$root->getName();
        $projectRoot = tempnam($tempDir, '');
        unlink($projectRoot);
        mkdir($projectRoot);

        // Set up the project.
        $fsHelper = new FilesystemHelper();
        $fsHelper->copyAll($sourceDir, $projectRoot);

        // @todo perhaps make some of these steps unnecessary
        $local = new LocalProject();
        $cwd = getcwd();
        chdir($projectRoot);
        exec('git init');
        chdir($cwd);
        $local->ensureGitRemote($projectRoot, 'testProjectId');
        $local->writeGitExclude($projectRoot);
        $local->writeCurrentProjectConfig(['id' => 'testProjectId'], $projectRoot);

        return $projectRoot;
    }
}
