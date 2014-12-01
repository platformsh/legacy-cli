<?php

namespace CommerceGuys\Platform\Cli\Helper;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;

class ArgvHelper extends Helper
{

    public function getName()
    {
        return 'argv';
    }

    /**
     * @param Command $command
     * @param ArgvInput $input
     * @param array $args
     *
     * @return string
     */
    public function getPassedCommand(Command $command, ArgvInput $input, array $args = null)
    {
        if ($args === null) {
            $args = $_SERVER['argv'];
        }
        $args = $this->stripArguments($args, $input);
        $args = $this->stripOptions($args, $command);
        // Escape arguments individually, if there is more than one. If there
        // was just one argument, it indicates that the user passed an entire
        // command inside quotes.
        if (count($args) > 1) {
            $args = array_map(array($this, 'escapeArg'), $args);
        }
        $command = implode(' ', $args);
        return $command;
    }

    /**
     * @param string $arg
     * @return string
     */
    protected function escapeArg($arg)
    {
        // Get a blank ArgvInput object so we can use the 'escapeToken' method.
        $argv = new ArgvInput();
        // If the string contains '=', expand it into the option and value.
        if (strpos($arg, '=')) {
            list($option, $value) = explode('=', $arg, 2);
            return $argv->escapeToken($option) . '=' . $argv->escapeToken($value);
        }
        return $argv->escapeToken($arg);
    }

    /**
     * @param string[] $args
     * @param InputInterface $input
     *
     * @return string[]
     */
    protected function stripArguments(array $args, InputInterface $input)
    {
        // Strip out the application name.
        array_shift($args);
        // Strip out the command name.
        foreach ($args as $key => $arg) {
            if ($input->getFirstArgument() === $arg) {
                unset($args[$key]);
            }
        }
        return $args;
    }

    /**
     * Strip a command's options from an argv array.
     *
     * @param string[] $args
     * @param Command $command
     *
     * @return string[]
     */
    protected function stripOptions(array $args, Command $command)
    {
        $definition = $command->getDefinition();
        foreach ($args as $key => $arg) {
            // Only consider options.
            if ($arg[0] !== '-') {
                continue;
            }
            // Look up the option. If it exists in the command definition,
            // remove it from the $args array.
            $argAsOption = preg_replace('/^\-+([^=]+).*/', '$1', $arg);
            if ($definition->hasOption($argAsOption)) {
                $option = $definition->getOption($argAsOption);
            }
            else {
                try {
                    $option = $definition->getOptionForShortcut($argAsOption);
                }
                catch (\InvalidArgumentException $e) {
                    continue;
                }
            }
            // Unset the option.
            unset($args[$key]);
            // Unset the option's value too.
            if ($option->acceptValue()
              && isset($args[$key + 1])
              && !strpos($arg, '=')
              && $args[$key + 1][0] !== '-') {
                unset($args[$key + 1]);
            }
        }
        return $args;
    }

}
