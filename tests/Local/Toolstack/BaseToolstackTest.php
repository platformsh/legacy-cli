<?php

namespace Platformsh\Cli\Tests\Toolstack;

use Platformsh\Cli\CliConfig;
use Platformsh\Cli\Helper\FilesystemHelper;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Tests\HasTempDirTrait;
use Symfony\Component\Console\Output\ConsoleOutput;

abstract class BaseToolstackTest extends \PHPUnit_Framework_TestCase
{
    use HasTempDirTrait;

    /** @var \Symfony\Component\Console\Output\OutputInterface */
    protected static $output;

    /** @var CliConfig */
    protected static $config;

    /** @var LocalBuild */
    protected $builder;

    protected $buildSettings = ['no-clean' => true];

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass()
    {
        self::$output = new ConsoleOutput(ConsoleOutput::VERBOSITY_NORMAL, false);
        self::$config = new CliConfig();
    }

    /**
     * {@inheritdoc}
     */
    public function setUp()
    {
        $this->builder = new LocalBuild(
            $this->buildSettings,
            null,
            self::$output
        );
        $this->tempDirSetUp();
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
            ? new LocalBuild($buildSettings + $this->buildSettings, null, self::$output)
            : $this->builder;
        $success = $builder->build($projectRoot);
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

        $projectRoot = $this->createTempSubDir('project');

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
        $local->writeCurrentProjectConfig(['id' => 'testProjectId'], $projectRoot);

        return $projectRoot;
    }
}
