<?php

declare(strict_types=1);

/**
 * @file
 * Override Symfony Console's TextDescriptor to customize the appearance of the
 * command list and each command's help.
 */

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Application as ConsoleApplication;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Descriptor\TextDescriptor;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Terminal;

class CustomTextDescriptor extends TextDescriptor
{
    public function __construct(private readonly string $cliExecutableName) {}

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

        $this->writeText("<comment>Command:</comment> " . $command->getName(), $options);

        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        if ($aliases) {
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


        $this->writeText("\n");
        $definition = clone $command->getDefinition();
        $definition->setOptions(array_filter($definition->getOptions(), fn(InputOption $opt): bool => !$opt instanceof HiddenInputOption));
        $this->describeInputDefinition($definition, $options);
        $this->writeText("\n");

        if ($help = $command->getProcessedHelp()) {
            $this->writeText("\n");
            $this->writeText('<comment>Help:</comment>', $options);
            $this->writeText("\n");
            $this->writeText(' ' . str_replace("\n", "\n ", $help), $options);
            $this->writeText("\n");
        }

        if ($command instanceof CommandBase && ($examples = $command->getExamples())) {
            $this->writeText("\n");
            $this->writeText('<comment>Examples:</comment>', $options);
            $name = $command->getPreferredName();
            foreach ($examples as $example) {
                $this->writeText(sprintf(
                    "\n %s:\n   <info>%s %s %s</info>\n",
                    $example['description'],
                    $this->cliExecutableName,
                    $name,
                    $example['commandline'],
                ));
            }
        }
    }

    /**
     * @inheritdoc
     */
    protected function describeApplication(ConsoleApplication $application, array $options = []): void
    {
        $describedNamespace = $options['namespace'] ?? null;
        $description = (new DescriptorUtils())->describeNamespaces($application, $describedNamespace, !empty($options['all']));
        $commands = $description['commands'];

        $width = $this->getColumnWidth($commands);
        if (isset($options['raw_text']) && $options['raw_text']) {
            foreach ($commands as $command) {
                $this->writeText(sprintf("%-{$width}s %s", $command->getName(), $command->getDescription()), $options);
                $this->writeText("\n");
            }
        } else {

            $this->writeText($application->getHelp(), $options);
            $this->writeText("\n\n");

            if ($describedNamespace) {
                $this->writeText(
                    sprintf("<comment>Available commands for the \"%s\" namespace:</comment>", $application->findNamespace($describedNamespace)),
                    $options,
                );
            } else {
                $this->writeText('<comment>Available commands:</comment>', $options);
            }

            // Display commands grouped by namespace.
            foreach ($description['namespaces'] as $namespace) {
                // Skip the namespace if it doesn't contain any commands.
                if (!count($namespace['commands'])) {
                    continue;
                }

                // Display the namespace name.
                if (!$describedNamespace && ApplicationDescription::GLOBAL_NAMESPACE !== $namespace['id']) {
                    $this->writeText("\n");
                    $this->writeText('<comment>' . $namespace['id'] . '</comment>', $options);
                }

                // Display each command.
                foreach ($namespace['commands'] as $command) {
                    $aliases = $command instanceof CommandBase
                        ? $command->getVisibleAliases()
                        : $command->getAliases();

                    $this->writeText("\n");
                    $this->writeText(
                        sprintf(
                            "  %-{$width}s %s",
                            '<info>' . $command->getName() . '</info>' . $this->formatAliases($aliases),
                            $command->getDescription(),
                        ),
                        $options,
                    );
                }
            }

            $this->writeText("\n");
        }
    }

    protected function writeText(string $content, array $options = []): void
    {
        $this->write(
            isset($options['raw_text']) && $options['raw_text'] ? strip_tags($content) : $content,
            !isset($options['raw_output']) || !$options['raw_output'],
        );
    }

    /**
     * @param array $aliases
     *
     * @return string
     */
    protected function formatAliases(array $aliases): string
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
    protected function formatDefaultValue(mixed $default): string
    {
        return json_encode($default, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    protected function getColumnWidth(array $commands): int|float
    {
        $width = 0;
        foreach ($commands as $command) {
            $aliasesString = $this->formatAliases($command->getAliases());
            $commandWidth = strlen((string) $command->getName()) + strlen($aliasesString);
            $width = max($commandWidth, $width);
        }

        // Limit to a maximum.
        $terminalWidth = (new Terminal())->getWidth();
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

    /**
     * {@inheritdoc}
     */
    protected function describeInputOption(InputOption $option, array $options = []): void
    {
        if ($option->acceptValue() && null !== $option->getDefault() && (!is_array($option->getDefault()) || count($option->getDefault()))) {
            $default = sprintf('<comment> [default: %s]</comment>', $this->formatDefaultValue($option->getDefault()));
        } else {
            $default = '';
        }

        $value = '';
        if ($option->acceptValue()) {
            $value = '=' . strtoupper($option->getName());

            if ($option->isValueOptional()) {
                $value = '[' . $value . ']';
            }
        }

        $totalWidth = $options['total_width'] ?? $this->calculateTotalWidthForOptions([$option]);
        $synopsis = sprintf(
            '%s%s',
            $option->getShortcut() ? sprintf('-%s, ', $option->getShortcut()) : '    ',
            sprintf('--%s%s', $option->getName(), $value),
        );

        $spacingWidth = $totalWidth - Helper::width($synopsis);

        // Ensure the description is indented and word-wrapped to fit the
        // terminal width.
        $descriptionWidth = (new Terminal())->getWidth() - $totalWidth - 4;
        $description = $option->getDescription();
        $description .= $default;
        if ($option->isArray()) {
            $description .= '<comment> (multiple values allowed)</comment>';
        }
        $description = preg_replace('/\s*[\r\n]\s*/', "\n" . str_repeat(' ', $totalWidth + 4), wordwrap($description, $descriptionWidth));

        $this->writeText(sprintf(
            '  <info>%s</info>  %s%s',
            $synopsis,
            str_repeat(' ', $spacingWidth),
            $description,
        ), $options);
    }

    /**
     * @param InputOption[] $options
     *
     * @return int
     */
    private function calculateTotalWidthForOptions(array $options): int
    {
        $totalWidth = 0;
        foreach ($options as $option) {
            // "-" + shortcut + ", --" + name
            $nameLength = 1 + max(Helper::width($option->getShortcut()), 1) + 4 + Helper::width($option->getName());

            if ($option->acceptValue()) {
                $valueLength = 1 + Helper::width($option->getName()); // = + value
                $valueLength += $option->isValueOptional() ? 2 : 0; // [ + ]

                $nameLength += $valueLength;
            }
            $totalWidth = max($totalWidth, $nameLength);
        }

        return $totalWidth;
    }
}
