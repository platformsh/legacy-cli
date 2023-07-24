<?php

namespace Platformsh\Cli\Console;

class ArrayArgument
{
    const SPLIT_HELP = 'Values may be split by commas (e.g. "a,b,c") and/or whitespace.';

    /**
     * Gets the value of an array input argument.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string $argName
     *
     * @return string[]
     */
    public static function getArgument(\Symfony\Component\Console\Input\InputInterface $input, $argName)
    {
        $value = $input->getArgument($argName);
        if (!\is_array($value)) {
            throw new \BadMethodCallException(\sprintf('The value of argument %s is not an array', $argName));
        }
        return self::split($value);
    }

    /**
     * Gets the value of an array input option.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string $optionName
     *
     * @return string[]
     */
    public static function getOption(\Symfony\Component\Console\Input\InputInterface $input, $optionName)
    {
        $value = $input->getOption($optionName);
        if (!\is_array($value)) {
            throw new \BadMethodCallException(\sprintf('The value of option --%s is not an array', $optionName));
        }
        return self::split($value);
    }

    /**
     * Splits the provided arguments by commas or whitespace.
     *
     * @param string[] $args
     *
     * @return array
     */
    public static function split($args)
    {
        $split = [];
        foreach ($args as $arg) {
            $split = \array_merge($split, \preg_split('/[,\s]+/', $arg));
        }
        return \array_filter($split, '\\strlen');
    }
}
