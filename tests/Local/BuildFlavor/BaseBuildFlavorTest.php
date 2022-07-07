<?php
declare(strict_types=1);

namespace Platformsh\Cli\Tests\Local\BuildFlavor;

use PHPUnit\Framework\TestCase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Config as CliConfig;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Tests\Container;
use Platformsh\Cli\Tests\HasTempDirTrait;
use Symfony\Component\Console\Output\OutputInterface;
use Psr\Container\ContainerInterface;

abstract class BaseBuildFlavorTest extends TestCase
{
    use HasTempDirTrait;

    /** @var ContainerInterface */
    private static $container;

    /** @var OutputInterface */
    protected static $output;

    /** @var CliConfig */
    protected static $config;

    /** @var LocalBuild */
    protected $builder;

    protected $buildSettings = ['no-clean' => true];

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        $container = Container::instance();
        self::$container = $container;
        self::$config = $container->get(Config::class);
        self::$output = $container->get(OutputInterface::class);
    }

    /**
     * {@inheritdoc}
     */
    protected function setUp(): void
    {
        $this->builder = self::$container->get(LocalBuild::class);
        $this->tempDirSetUp();
    }

    /**
     * Test building a project from dummy source code.
     *
     * @param string $sourceDir
     *   A directory containing source code for the project or app. Files will
     *   be copied into a dummy project.
     * @param array  $buildSettings
     *   An array of custom build settings.
     * @param bool $expectedResult
     *   The expected build result.
     *
     * @return string
     *   The project root for the dummy project.
     */
    protected function assertBuildSucceeds(string $sourceDir, array $buildSettings = [], bool $expectedResult = true): string
    {
        $projectRoot = $this->createDummyProject($sourceDir);
        self::$output->writeln("\nTesting build for directory: " . $sourceDir);
        $success = $this->builder->build($buildSettings + $this->buildSettings, $projectRoot);
        $this->assertSame($expectedResult, $success, 'Build for dir: ' . $sourceDir);

        return $projectRoot;
    }

    /**
     * @param string $sourceDir
     *
     * @return string
     */
    protected function createDummyProject(string $sourceDir): string
    {
        if (!is_dir($sourceDir)) {
            throw new \InvalidArgumentException("Not a directory: $sourceDir");
        }

        $projectRoot = $this->createTempSubDir('project');

        // Set up the project.
        $fsHelper = new Filesystem();
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
