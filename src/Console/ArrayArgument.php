<?php

declare(strict_types=1);

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Input\InputInterface;

class ArrayArgument
{
    public const SPLIT_HELP = 'Values may be split by commas (e.g. "a,b,c") and/or whitespace.';

    /**
     * Gets the value of an array input argument.
     *
     * @param InputInterface $input
     * @param string $argName
     *
     * @return string[]
     */
    public static function getArgument(InputInterface $input, string $argName): array
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
     * @param InputInterface $input
     * @param string $optionName
     *
     * @return string[]
     */
    public static function getOption(InputInterface $input, string $optionName): array
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
     * @return string[]
     */
    public static function split(array $args): array
    {
        $split = [];
        foreach ($args as $arg) {
            $splitArg = \preg_split('/[,\s]+/', $arg);
            if (!$splitArg) {
                throw new \RuntimeException('Failed to split argument by commas/whitespace');
            }
            $split = \array_merge($split, $splitArg);
        }
        return \array_filter($split, fn(string $a): bool => \strlen($a) > 0);
    }
}
