<?php

namespace Platformsh\Cli\Service;

use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class Ssh implements InputConfiguringInterface
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
    public function getSshArgs(array $extraOptions = [])
    {
        $options = array_merge($this->getSshOptions(), $extraOptions);

        $args = [];
        if ($this->output->isDebug()) {
            $args[] = '-vv';
        } elseif ($this->output->isVeryVerbose()) {
            $args[] = '-v';
        } elseif ($this->output->isQuiet()) {
            $args[] = '-q';
        }

        foreach ($options as $option) {
            $args[] = '-o';
            $args[] = $option;
        }

        return $args;
    }

    /**
     * Returns an array of SSH options, based on the input options.
     *
     * @return string[] An array of SSH options.
     */
    protected function getSshOptions()
    {
        $options = [];

        $options[] = 'SendEnv TERM';

        if ($this->input->hasOption('identity-file') && $this->input->getOption('identity-file')) {
            $file = $this->input->getOption('identity-file');
            if (!file_exists($file)) {
                throw new \InvalidArgumentException('Identity file not found: ' . $file);
            }
            $options[] = 'IdentitiesOnly yes';
            $options[] = 'IdentityFile ' . $file;
        }

        return $options;
    }

    /**
     * Returns an SSH command line.
     *
     * @param array $extraOptions
     *
     * @return string
     */
    public function getSshCommand(array $extraOptions = [])
    {
        $command = 'ssh';
        $args = $this->getSshArgs($extraOptions);
        if (!empty($args)) {
            $command .= " '" . implode("' '", $args) . "'";
        }

        return $command;
    }
}
