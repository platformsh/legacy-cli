<?php

namespace Platformsh\Cli\Console;

class ArrayArgument
{
    const SPLIT_HELP = 'If a single value is specified, it will be split by commas or whitespace.';

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
     * Splits the first value, by commas or whitespace, if there is only one.
     *
     * @param []string $args
     *
     * @return array
     */
    public static function split($args)
    {
        if (\count($args) !== 1) {
            return $args;
        }
        return \array_filter(\preg_split('/[,\s]+/', \reset($args)), '\\strlen');
    }
}
