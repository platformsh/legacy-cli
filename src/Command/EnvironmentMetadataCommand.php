<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentMetadataCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:metadata')
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the property')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Clean output suitable for piping')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('Read metadata for an environment');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);
        $environment->setClient($this->getPlatformClient($this->environment['endpoint']));

        $pipe = $input->getOption('pipe') || !$this->isTerminal($output);

        $name = $input->getArgument('name');

        if ($name) {
            try {
                $currentValue = $environment->getProperty($name);
            }
            catch (\InvalidArgumentException $e) {
                $output->writeln("Property not found: <error>$name</error>");
                return 1;
            }
            if ($pipe) {
                $output->write($currentValue);
                return 0;
            }
            $output->writeln("<info>$name</info>: $currentValue");
            return 0;
        }

        $properties = $environment->getProperties();

        if ($pipe) {
            foreach ($properties as $key => $value) {
                if (!is_scalar($value)) {
                    $value = json_encode($value);
                }
                $output->writeln("$key\t$value");
            }
            return 0;
        }

        $output->writeln("Metadata for the environment <info>" . $environment->id() . "</info>:");

        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($properties as $key => $value) {
            if (!is_scalar($value)) {
                $value = json_encode($value);
            }
            $table->addRow(array($key, $value));
        }
        $table->render();

        return 0;
    }

}
