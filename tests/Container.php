<?php

namespace Platformsh\Cli\Tests;

use Platformsh\Cli\Application;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class Container
{
    private static $container;

    public static function instance()
    {
        if (isset(self::$container)) {
            return self::$container;
        }

        $container = new ContainerBuilder();
        $loader = new YamlFileLoader($container, new FileLocator());
        $loader->load(__DIR__ . '/services.yaml');

        $container->compile();

        // Handle synthetic services.
        $container->set(OutputInterface::class, new ConsoleOutput());
        $container->set(InputInterface::class, new ArrayInput([]));

        // Override config for tests.
        $config = (new Config())->withOverrides([
            'api.ssh_domain_wildcards' => ['*.ssh.example.com'],
            'detection.use_site_headers' => false,
            // We rename the app config file to avoid confusion when building the
            // CLI itself on platform.sh
            'service.app_config_file' => '_platform.app.yaml',
        ]);

        $container->set(Config::class, $config);
        $container->set(Application::class, new Application($config));

        return self::$container = $container;
    }
}
