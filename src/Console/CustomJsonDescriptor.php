<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Descriptor\Descriptor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class CustomJsonDescriptor extends Descriptor
{
    /**
     * {@inheritdoc}
     */
    protected function describeInputArgument(InputArgument $argument, array $options = []): void
    {
        $this->writeData($this->getInputArgumentData($argument), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputOption(InputOption $option, array $options = []): void
    {
        $this->writeData($this->getInputOptionData($option), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputDefinition(InputDefinition $definition, array $options = []): void
    {
        $this->writeData($this->getInputDefinitionData($definition), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeCommand(Command $command, array $options = []): void
    {
        $this->writeData($this->getCommandData($command), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeApplication(Application $application, array $options = []): void
    {
        $describedNamespace = $options['namespace'] ?? null;
        $description = (new DescriptorUtils())->describeNamespaces($application, $describedNamespace, !empty($options['all']));

        $commands = array_map($this->getCommandData(...), $description['commands']);

        $data = [];
        if ('UNKNOWN' !== $application->getName()) {
            $data['application']['name'] = $application->getName();
            if ('UNKNOWN' !== $application->getVersion()) {
                $data['application']['version'] = $application->getVersion();
            }
        }

        $data['commands'] = $commands;

        if ($describedNamespace) {
            $data['namespace'] = $describedNamespace;
        } else {
            // Only show namespaces with at least one (non-hidden) command.
            $data['namespaces'] = array_values(array_filter($description['namespaces'], fn($n): bool => !empty($n['commands'])));
        }

        $this->writeData($data, $options);
    }

    /**
     * Writes data as json.
     */
    private function writeData(array $data, array $options): void
    {
        $flags = $options['json_encoding'] ?? 0;
        $flags |= JSON_UNESCAPED_SLASHES;

        $this->write(json_encode($data, $flags));
    }

    private function getInputArgumentData(InputArgument $argument): array
    {
        return [
            'name' => $argument->getName(),
            'is_required' => $argument->isRequired(),
            'is_array' => $argument->isArray(),
            'description' => preg_replace('/\s*[\r\n]\s*/', ' ', $argument->getDescription()),
            'default' => \INF === $argument->getDefault() ? 'INF' : $argument->getDefault(),
        ];
    }

    private function getInputOptionData(InputOption $option): array
    {
        return [
            'name' => '--' . $option->getName(),
            'shortcut' => $option->getShortcut() ? '-' . str_replace('|', '|-', $option->getShortcut()) : '',
            'accept_value' => $option->acceptValue(),
            'is_value_required' => $option->isValueRequired(),
            'is_multiple' => $option->isArray(),
            'description' => preg_replace('/\s*[\r\n]\s*/', ' ', $option->getDescription()),
            'default' => \INF === $option->getDefault() ? 'INF' : $option->getDefault(),
            'hidden' => $option instanceof HiddenInputOption,
        ];
    }

    private function getInputDefinitionData(InputDefinition $definition): array
    {
        $inputArguments = array_map($this->getInputArgumentData(...), $definition->getArguments());

        $inputOptions = [];
        foreach ($definition->getOptions() as $name => $option) {
            if ($option instanceof HiddenInputOption) {
                continue;
            }
            $inputOptions[$name] = $this->getInputOptionData($option);
        }

        return [
            'arguments' => $inputArguments ?: new \stdClass(),
            'options' => $inputOptions ?: new \stdClass(),
        ];
    }

    private function getCommandData(Command $command): array
    {
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }

        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);
        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        $examples = $command instanceof CommandBase ? $command->getExamples() : [];

        return [
            'name' => $command->getName(),
            'usage' => array_merge([$command->getSynopsis()], $command->getUsages()),
            'aliases' => $aliases,
            'description' => $command->getDescription(),
            'help' => $command->getProcessedHelp(),
            'examples' => $examples,
            'definition' => $this->getInputDefinitionData($command->getNativeDefinition()),
            'hidden' => $command->isHidden(),
        ];
    }
}
