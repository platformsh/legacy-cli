<?php

namespace Platformsh\Cli\Console;

use Symfony\Component\Console\Input\ArgvInput as BaseArgvInput;
use Symfony\Component\Console\Input\InputDefinition;

class ArgvInput extends BaseArgvInput
{

    protected $shortcut;

    /**
     * {@inheritdoc}
     */
    public function __construct(array $argv = null, InputDefinition $definition = null)
    {
        if (null === $argv) {
            $argv = $_SERVER['argv'];
        }

        foreach ($argv as $key => $arg) {
            if (strlen($arg) && $arg[0] === '@') {
                if (isset($this->shortcut)) {
                    throw new \InvalidArgumentException("Shortcut already specified as @{$this->shortcut}");
                }
                $this->shortcut = substr($arg, 1);
                unset($argv[$key]);
            }
        }

        parent::__construct($argv, $definition);
    }

    /**
     * Get the passed shortcut.
     *
     * @return string|false
     */
    public function getShortcut()
    {
        return $this->shortcut ?: false;
    }
}
