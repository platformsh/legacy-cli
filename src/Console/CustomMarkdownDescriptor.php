<?php
namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomMarkdownDescriptor extends MarkdownDescriptor
{
    protected $cliExecutableName;

    /**
     * @param string|null $cliExecutableName
     *   The name of the CLI command.
     */
    public function __construct($cliExecutableName = null)
    {
        $this->cliExecutableName = $cliExecutableName ?: basename($_SERVER['PHP_SELF']);
    }

    /**
     * @inheritDoc
     */
    protected function describeApplication(Application $application, array $options = array()): void
    {
        $describedNamespace = isset($options['namespace']) ? $options['namespace'] : null;
        $description = new ApplicationDescription($application, $describedNamespace, !empty($options['all']));
        $title = sprintf('%s %s', $application->getName(), $application->getVersion());

        $this->write($title."\n".str_repeat('=', Helper::width($title)));

        foreach ($description->getNamespaces() as $namespace) {
            if (empty($namespace['commands'])) {
                continue;
            }
            if (ApplicationDescription::GLOBAL_NAMESPACE !== $namespace['id']) {
                $this->write("\n\n");
                $this->write('**'.$namespace['id'].':**');
            }

            $this->write("\n\n");
            $this->write(implode("\n", array_map(function ($commandName) use ($description) {
                return sprintf('* [`%s`](#%s)', $commandName, str_replace(':', '', $description->getCommand($commandName)->getName()));
            }, $namespace['commands'])));
        }

        foreach ($description->getCommands() as $command) {
            $this->write("\n\n");
            $this->describeCommand($command);
        }
    }

    /**
     * @inheritdoc
     */
    protected function describeCommand(Command $command, array $options = []): void
    {
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);

        $this->write($command->getName() . "\n"
            . str_repeat('-', strlen($command->getName()))."\n");

        if ($description = $command->getDescription()) {
            $this->write("$description\n\n");
        }

        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        if ($aliases) {
            $this->write(
                'Aliases: ' . '`'.implode('`, `', $aliases).'`' . "\n\n"
            );
        }

        $this->write("## Usage:\n\n```\n" . $command->getSynopsis() . "\n```\n\n");

        if ($help = $command->getProcessedHelp()) {
            $this->write($help);
            $this->write("\n\n");
        }

        $this->describeInputDefinition($command->getNativeDefinition());
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
                    $example['commandline']
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
            $this->write(" (`-" . implode('|-', explode('|', $shortcut)). "`)");
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
