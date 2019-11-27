<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Scp implements InputConfiguringInterface
{
    protected $input;
    protected $output;

    public function __construct(InputInterface $input, OutputInterface $output)
    {
        $this->input = $input;
        $this->output = $output;
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new InputOption('identity-file', 'i', InputOption::VALUE_REQUIRED, 'An SSH identity (private key) to use')
        );
    }

    /**
     * @param array $extraOptions
     *
     * @return array
     */
    public function getScpArgs(array $extraOptions = [])
    {
        $options = array_merge($this->getScpOptions(), $extraOptions);

        $args = [];
        foreach ($options as $name => $value) {
            $args[] = '-o';
            $args[] = $name . ' ' . $value;
        }

        return $args;
    }

    /**
     * Returns an array of SCP options, based on the input options.
     *
     * @return string[] An array of SCP options.
     */
    protected function getScpOptions()
    {
        $options = [];

        if ($this->input->hasOption('identity-file') && $this->input->getOption('identity-file')) {
            $file = $this->input->getOption('identity-file');
            if (!file_exists($file)) {
                throw new \InvalidArgumentException('Identity file not found: ' . $file);
            }
            $options['IdentitiesOnly'] = 'yes';
            $options['IdentityFile'] = $file;
        }

        if ($this->output->isDebug()) {
            $options['LogLevel'] = 'DEBUG';
        } elseif ($this->output->isVeryVerbose()) {
            $options['LogLevel'] = 'VERBOSE';
        } elseif ($this->output->isQuiet()) {
            $options['LogLevel'] = 'QUIET';
        }

        return $options;
    }

    /**
     * Returns an SCP command line.
     *
     * @param array $extraOptions
     *
     * @return string
     */
    public function getScpCommand(array $extraOptions = [])
    {
        $command = 'scp';
        $args = $this->getScpArgs($extraOptions);
        if (!empty($args)) {
            $command .= ' ' . implode(' ', array_map('escapeshellarg', $args));
        }

        return $command;
    }
}
