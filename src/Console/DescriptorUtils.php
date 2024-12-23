<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Descriptor\ApplicationDescription;

class DescriptorUtils
{
    /**
     * Returns a description of an application's commands grouped by namespace.
     *
     * Resolves lazy-loaded commands to check if they are hidden or disabled.
     *
     * @return array{'namespaces': array<array{id: string, commands: array<string, Command>}>, 'commands': array<string, Command>}
     */
    public function describeNamespaces(Application $application, ?string $namespace = null, bool $showHidden = false): array
    {
        $description = new ApplicationDescription($application, $namespace, $showHidden);
        $commands = [];
        $namespaces = [];
        foreach ($description->getNamespaces() as $id => $namespace) {
            $namespaces[$id] = ['id' => $id, 'commands' => []];
            foreach ($namespace['commands'] as $name) {
                $command = $description->getCommand($name);
                if ($command instanceof LazyCommand) {
                    $command = $command->getCommand();
                }

                // Ensure the command is only included under its canonical name.
                if ($name !== $command->getName()) {
                    continue;
                }

                if (($showHidden || !$command->isHidden()) && $command->isEnabled()) {
                    $namespaces[$id]['commands'][$name] = $command;
                    $commands[$name] = $command;
                }
            }
        }
        return ['namespaces' => $namespaces, 'commands' => $commands];
    }
}
