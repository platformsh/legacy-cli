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
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

abstract class BuildFlavorTestBase extends TestCase
{
    use HasTempDirTrait;

    private static ContainerInterface $container;

    protected static OutputInterface $output;

    protected static CliConfig $config;

    protected LocalBuild $builder;

    /** @var array<string, mixed> */
    protected array $buildSettings = ['no-clean' => true];

    /**
     * {@inheritdoc}
     */
    public static function setUpBeforeClass(): void
    {
        $container = Container::instance();
        $container->set(InputInterface::class, new ArrayInput([]));

        self::$output = new ConsoleOutput(OutputInterface::VERBOSITY_NORMAL, false);
        $container->set(OutputInterface::class, self::$output);

        self::$config = (new CliConfig())->withOverrides([
            // We rename the app config file to avoid confusion when building the
            // CLI itself on platform.sh
            'service.app_config_file' => '_platform.app.yaml',
        ]);
        $container->set(Config::class, self::$config);

        self::$container = $container;
    }

    /**
     * {@inheritdoc}
     */
    public function setUp(): void
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
     * @param array<string, mixed> $buildSettings
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
        if ($cwd) {
            chdir($cwd);
        }
        $local->ensureGitRemote($projectRoot, 'testProjectId');
        $local->writeCurrentProjectConfig(['id' => 'testProjectId'], $projectRoot);

        return $projectRoot;
    }
}
