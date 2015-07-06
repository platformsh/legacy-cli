<?php
/**
 * @file
 * Override Symfony Console's TextDescriptor to customize the appearance of the
 * command list and each command's help.
 */

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Descriptor\TextDescriptor as BaseTextDescriptor;

class TextDescriptor extends BaseTextDescriptor
{

    /**
     * @inheritdoc
     */
    protected function describeCommand(Command $command, array $options = array())
    {
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);

        $this->writeText("<comment>Command:</comment> " . $command->getName(), $options);
        if ($aliases = $command->getAliases()) {
            $this->writeText("\n");
            $this->writeText('<comment>Aliases:</comment> ' . implode(', ', $aliases), $options);
        }
        if ($description = $command->getDescription()) {
            $this->writeText("\n");
            $this->writeText("<comment>Description:</comment> $description", $options);
        }
        $this->writeText("\n\n");

        $this->writeText('<comment>Usage:</comment>', $options);
        $this->writeText("\n");
        $this->writeText(' ' . $command->getSynopsis(), $options);
        $this->writeText("\n");

        if ($definition = $command->getNativeDefinition()) {
            $this->writeText("\n");
            $this->describeInputDefinition($definition, $options);
        }

        if ($help = $command->getProcessedHelp()) {
            $this->writeText("\n");
            $this->writeText('<comment>Help:</comment>', $options);
            $this->writeText("\n");
            $this->writeText(' ' . str_replace("\n", "\n ", $help), $options);
            $this->writeText("\n");
        }

        if ($command instanceof PlatformCommand && ($examples = $command->getProcessedExamples())) {
            $this->writeText("\n");
            $this->writeText('<comment>Examples:</comment>', $options);
            $this->writeText("\n");
            $this->writeText(' ' . str_replace("\n", "\n ", $examples), $options);
            $this->writeText("\n");
        }
    }

    /**
     * @inheritdoc
     */
    protected function describeApplication(ConsoleApplication $application, array $options = array())
    {
        $describedNamespace = isset($options['namespace']) ? $options['namespace'] : null;
        $description = new ApplicationDescription($application, $describedNamespace);

        if (isset($options['raw_text']) && $options['raw_text']) {
            $width = $this->getColumnWidth($description->getCommands());

            foreach ($description->getCommands() as $command) {
                $this->writeText(sprintf("%-${width}s %s", $command->getName(), $command->getDescription()), $options);
                $this->writeText("\n");
            }
        } else {
            $width = $this->getColumnWidth($description->getCommands());

            $this->writeText($application->getHelp(), $options);
            $this->writeText("\n\n");

            if ($describedNamespace) {
                $this->writeText(
                  sprintf("<comment>Available commands for the \"%s\" namespace:</comment>", $describedNamespace),
                  $options
                );
            } else {
                $this->writeText('<comment>Available commands:</comment>', $options);
            }

            // add commands by namespace
            foreach ($description->getNamespaces() as $namespace) {
                if (!$describedNamespace && ApplicationDescription::GLOBAL_NAMESPACE !== $namespace['id']) {
                    $this->writeText("\n");
                    $this->writeText('<comment>' . $namespace['id'] . '</comment>', $options);
                }

                foreach ($namespace['commands'] as $name) {
                    $command = $description->getCommand($name);
                    $aliases = $command->getAliases();
                    if ($aliases && ApplicationDescription::GLOBAL_NAMESPACE === $namespace['id'] && in_array(
                        $name,
                        $aliases
                      )
                    ) {
                        // If the command has aliases, do not list it in the
                        // 'global' namespace. The aliases will be shown inline
                        // with the full command name.
                        continue;
                    }
                    // Colour local commands differently from remote ones.
                    $commandDescription = $command->getDescription();
                    if ($command instanceof PlatformCommand && !$command->isLocal()) {
                        $commandDescription = "<fg=cyan>$commandDescription</fg=cyan>";
                    }
                    $this->writeText("\n");
                    $this->writeText(
                      sprintf(
                        "  %-${width}s %s",
                        "<info>$name</info>" . $this->formatAliases($aliases),
                        $commandDescription
                      ),
                      $options
                    );
                }
            }

            $this->writeText("\n");
        }
    }

    /**
     * @param int $default
     *
     * @return int
     */
    protected function getTerminalWidth($default = 80)
    {
        static $dimensions;
        if (!$dimensions) {
            $application = new ConsoleApplication();
            $dimensions = $application->getTerminalDimensions();
        }

        return $dimensions[0] ?: $default;
    }

    /**
     * {@inheritdoc}
     */
    protected function writeText($content, array $options = array())
    {
        $this->write(
          isset($options['raw_text']) && $options['raw_text'] ? strip_tags($content) : $content,
          isset($options['raw_output']) ? !$options['raw_output'] : true
        );
    }

    /**
     * @param array $aliases
     *
     * @return string
     */
    protected function formatAliases(array $aliases)
    {
        return $aliases ? " (" . implode(', ', $aliases) . ")" : '';
    }

    /**
     * Formats input option/argument default value.
     *
     * @param mixed $default
     *
     * @return string
     */
    protected function formatDefaultValue($default)
    {
        if (PHP_VERSION_ID < 50400) {
            return str_replace('\/', '/', json_encode($default));
        }

        return json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param Command[] $commands
     *
     * @return int
     */
    protected function getColumnWidth(array $commands)
    {
        $width = 0;
        foreach ($commands as $command) {
            $aliasesString = $this->formatAliases($command->getAliases());
            $commandWidth = strlen($command->getName()) + strlen($aliasesString);
            $width = $commandWidth > $width ? $commandWidth : $width;
        }

        // Limit to a maximum.
        $terminalWidth = $this->getTerminalWidth();
        if ($width / $terminalWidth > 0.4) {
            $width = floor($terminalWidth * 0.4);
        }

        // Start at a minimum.
        if ($width < 20) {
            $width = 20;
        }

        // Add the indent.
        $width += 2;

        // Accommodate tags.
        $width += strlen('<info></info>');

        return $width;
    }

}
