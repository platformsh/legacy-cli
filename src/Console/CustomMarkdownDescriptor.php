<?php
namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\MarkdownDescriptor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class CustomMarkdownDescriptor extends MarkdownDescriptor
{

    /**
     * @inheritdoc
     */
    protected function describeCommand(Command $command, array $options = array())
    {
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);

        $this->write($command->getName() . "\n"
          . str_repeat('-', strlen($command->getName()))."\n");

        if ($description = $command->getDescription()) {
            $this->write("$description\n\n");
        }

        if ($command->getAliases()) {
            $this->write(
              'Aliases: ' . '`'.implode('`, `', $command->getAliases()).'`' . "\n\n"
            );
        }

        $this->write("## Usage:\n\n```\n" . $command->getSynopsis() . "\n```\n\n");

        if ($help = $command->getProcessedHelp()) {
            $this->write($help);
            $this->write("\n\n");
        }

        if ($command->getNativeDefinition()) {
            $this->describeInputDefinition($command->getNativeDefinition());
            $this->write("\n\n");
        }

        if ($command instanceof PlatformCommand && ($examples = $command->getExamples())) {
            $this->write('## Examples');
            $this->write("\n");
            $name = $command->getName();
            foreach ($examples as $arguments => $description) {
                $this->write("\n* $description:  \n  ```\n  platform $name $arguments\n  ```\n");
            }
            $this->write("\n");
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputArgument(InputArgument $argument, array $options = array())
    {
        $this->write('* **`' . $argument->getName() . "`**");
        $notes = array(
          $argument->isRequired() ? "required" : "optional",
        );
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
    protected function describeInputOption(InputOption $option, array $options = array())
    {
        $this->write('* **`--' . $option->getName() . "`**");
        if ($shortcut = $option->getShortcut()) {
            $this->write(" (`-" . implode('|-', explode('|', $shortcut)). "`)");
        }
        if ($option->acceptValue() && $option->isArray()) {
            $this->write(" (multiple values allowed)");
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
