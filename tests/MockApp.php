<?php

declare(strict_types=1);

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Application;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;

class MockApp
{
    private static ?Application $application = null;

    public static function instance(): Application
    {
        if (!self::$application) {
            $config = new Config([], __DIR__ . '/data/mock-cli-config.yaml');
            self::$application = new Application($config);
            self::$application->setIO(new ArrayInput([]), new NullOutput());
            self::$application->setAutoExit(false);
        }

        return self::$application;
    }

    /**
     * @param array<string, mixed> $otherArgs
     */
    public static function runAndReturnOutput(string $command, array $otherArgs = [], ?int $verbosity = null): string
    {
        $app = MockApp::instance();
        $input = new ArrayInput(array_merge([$command], $otherArgs));
        $input->setInteractive(false);
        $output = new BufferedOutput($verbosity);
        if (!chdir(sys_get_temp_dir())) {
            throw new \Exception('Cannot change directory');
        }
        $exitCode = $app->run($input, $output);
        if ($exitCode !== 0) {
            throw new \RuntimeException(sprintf("Running test command returned exit code %d and output:\n%s", $exitCode, $output->fetch()));
        }

        return $output->fetch();
    }
}
