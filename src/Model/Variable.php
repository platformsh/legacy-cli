<?php

declare(strict_types=1);

namespace Platformsh\Cli\Model;

/**
 * A class to help parsing and validating Platform.sh variables.
 */
class Variable
{
    /**
     * Parses a variable definition that might be used on the command line.
     *
     * @param string $variable
     *   The variable definition in the form type:name=value.
     *
     * @return string[]
     *   An array containing: [ type, name, value ].
     */
    public function parse(string $variable): array
    {
        if (!preg_match('#^([^:=]+) ?: ?([^=]+) ?= ?([^=]*)$#', $variable, $matches)) {
            throw new \InvalidArgumentException('Variables must be defined as type:name=value.');
        }
        [, $type, $name, $value] = $matches;

        return [$this->validateType($type), $this->validateName($name), $value];
    }

    /**
     * Validates the variable type (AKA namespace).
     *
     * @param string $type
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    public function validateType(string $type): string
    {
        if (!preg_match('#^[a-zA-Z0-9._\-]+$#', $type)) {
            throw new \InvalidArgumentException(sprintf('Invalid variable type: %s', $type));
        }

        return $type;
    }

    /**
     * Validates the variable name.
     *
     * @param string $name
     *
     * @throws \InvalidArgumentException
     *
     * @return string
     */
    public function validateName(string $name): string
    {
        if (!preg_match('#^[a-zA-Z0-9._:\-|/]+$#', $name)) {
            throw new \InvalidArgumentException(sprintf('Invalid variable name: %s', $name));
        }

        return $name;
    }
}
