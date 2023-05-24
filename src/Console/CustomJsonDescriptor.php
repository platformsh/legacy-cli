<?php

namespace Platformsh\Cli\Console;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Descriptor\ApplicationDescription;
use Symfony\Component\Console\Descriptor\Descriptor;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;

class CustomJsonDescriptor extends Descriptor
{
    /**
     * {@inheritdoc}
     */
    protected function describeInputArgument(InputArgument $argument, array $options = [])
    {
        $this->writeData($this->getInputArgumentData($argument), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputOption(InputOption $option, array $options = [])
    {
        $this->writeData($this->getInputOptionData($option), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeInputDefinition(InputDefinition $definition, array $options = [])
    {
        $this->writeData($this->getInputDefinitionData($definition), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeCommand(Command $command, array $options = [])
    {
        $this->writeData($this->getCommandData($command), $options);
    }

    /**
     * {@inheritdoc}
     */
    protected function describeApplication(Application $application, array $options = [])
    {
        $describedNamespace = isset($options['namespace']) ? $options['namespace'] : null;
        $description = new ApplicationDescription($application, $describedNamespace, !empty($options['all']));
        $commands = [];

        foreach ($description->getCommands() as $command) {
            $commands[] = $this->getCommandData($command);
        }

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
            $data['namespaces'] = array_values(array_filter($description->getNamespaces(), function ($n) {
                return !empty($n['commands']);
            }));
        }

        $this->writeData($data, $options);
    }

    /**
     * Writes data as json.
     */
    private function writeData(array $data, array $options)
    {
        $flags = isset($options['json_encoding']) ? $options['json_encoding'] : 0;
        $flags |= JSON_UNESCAPED_SLASHES;

        $this->write(json_encode($data, $flags));
    }

    /**
     * @return array
     */
    private function getInputArgumentData(InputArgument $argument)
    {
        return [
            'name' => $argument->getName(),
            'is_required' => $argument->isRequired(),
            'is_array' => $argument->isArray(),
            'description' => preg_replace('/\s*[\r\n]\s*/', ' ', $argument->getDescription()),
            'default' => \INF === $argument->getDefault() ? 'INF' : $argument->getDefault(),
        ];
    }

    /**
     * @return array
     */
    private function getInputOptionData(InputOption $option)
    {
        return [
            'name' => '--'.$option->getName(),
            'shortcut' => $option->getShortcut() ? '-'.str_replace('|', '|-', $option->getShortcut()) : '',
            'accept_value' => $option->acceptValue(),
            'is_value_required' => $option->isValueRequired(),
            'is_multiple' => $option->isArray(),
            'description' => preg_replace('/\s*[\r\n]\s*/', ' ', $option->getDescription()),
            'default' => \INF === $option->getDefault() ? 'INF' : $option->getDefault(),
            'hidden' => $option instanceof HiddenInputOption,
        ];
    }

    /**
     * @return array
     */
    private function getInputDefinitionData(InputDefinition $definition)
    {
        $inputArguments = [];
        foreach ($definition->getArguments() as $name => $argument) {
            $inputArguments[$name] = $this->getInputArgumentData($argument);
        }

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

    /**
     * @return array
     */
    private function getCommandData(Command $command)
    {
        $command->getSynopsis();
        $command->mergeApplicationDefinition(false);
        $aliases = $command instanceof CommandBase ? $command->getVisibleAliases() : $command->getAliases();
        $examples = [];
        if ($command instanceof CommandBase) {
            foreach ($command->getExamples() as $arguments => $description) {
                $examples[] = ['commandline' => $arguments, 'description' => $description];
            }
        }

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
