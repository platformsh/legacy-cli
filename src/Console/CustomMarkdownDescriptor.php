<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomMarkdownDescriptor extends MarkdownDescriptor
{
    public function __construct(private readonly string $cliExecutableName) {}

    /**
     * @inheritDoc
     */
    protected function describeApplication(Application $application, array $options = []): void
    {
        $describedNamespace = $options['namespace'] ?? null;
        $description = (new DescriptorUtils())->describeNamespaces($application, $describedNamespace, !empty($options['all']));
        $title = sprintf('%s %s', $application->getName(), $application->getVersion());
        $this->write($title . "\n" . str_repeat('=', Helper::width($title)));

        foreach ($description['namespaces'] as $namespace) {
            if (empty($namespace['commands'])) {
                continue;
            }
            if (ApplicationDescription::GLOBAL_NAMESPACE !== $namespace['id']) {
                $this->write("\n\n");
                $this->write('**' . $namespace['id'] . ':**');
            }

            $this->write("\n\n");
            $this->write(implode("\n", array_map(function (Command $command): string {
                return sprintf('* [`%s`](#%s)', $command->getName(), str_replace(':', '', $command->getName()));
            }, $namespace['commands'])));
        }

        foreach ($description['commands'] as $command) {
            $this->write("\n\n");
            $this->describeCommand($command);
        }
    }

    /**
     * @inheritdoc
     */
    protected function describeCommand(Command $command, array $options = []): void
    {
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);

        $this->write($command->getName() . "\n"
            . str_repeat('-', strlen((string) $command->getName())) . "\n");

        if ($description = $command->getDescription()) {
            $this->write("$description\n\n");
        }

        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        if ($aliases) {
            $this->write(
                'Aliases: ' . '`' . implode('`, `', $aliases) . '`' . "\n\n",
            );
        }

        $this->write("## Usage:\n\n```\n" . $command->getSynopsis() . "\n```\n\n");

        if ($help = $command->getProcessedHelp()) {
            $this->write($help);
            $this->write("\n\n");
        }

        $this->describeInputDefinition($command->getDefinition());
        $this->write("\n\n");

        if ($command instanceof CommandBase && ($examples = $command->getExamples())) {
            $this->write('## Examples');
            $this->write("\n");
            $name = $command->getPreferredName();
            foreach ($examples as $example) {
                $this->write(sprintf(
                    "\n* %s:  \n  ```\n  %s %s %s\n  ```\n",
                    $example['description'],
                    $this->cliExecutableName,
                    $name,
                    $example['commandline'],
                ));
            }
            $this->write("\n");
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputArgument(InputArgument $argument, array $options = []): void
    {
        $this->write('* `' . $argument->getName() . '`');
        $notes = [
            $argument->isRequired() ? "required" : "optional",
        ];
        if ($argument->isArray()) {
            $notes[] = "multiple values allowed";
        }
        $this->write(' (' . implode('; ', $notes) . ')');
        $this->write("  \n  " . $argument->getDescription());
        if (!$argument->isRequired() && $argument->getDefault()) {
            $default = var_export($argument->getDefault(), true);
            $this->write("  \n  Default: `$default``");
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputOption(InputOption $option, array $options = []): void
    {
        $this->write('* `--' . $option->getName() . '`');
        if ($shortcut = $option->getShortcut()) {
            $this->write(" (`-" . implode('|-', explode('|', $shortcut)) . "`)");
        }
        $notes = [];
        if ($option->isArray()) {
            $notes[] = 'multiple values allowed';
        } elseif ($option->acceptValue()) {
            $notes[] = 'expects a value';
        }
        if ($notes) {
            $this->write(' (' . implode('; ', $notes) . ')');
        }
        if ($description = $option->getDescription()) {
            $this->write("  \n  " . $description);
        }
        if ($option->acceptValue() && $option->getDefault()) {
            $default = var_export($option->getDefault(), true);
            $this->write("  \n  Default: `$default`");
        }
    }
}
