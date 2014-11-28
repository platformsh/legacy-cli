<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentRelationshipsCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project ID')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment ID')
            ->setDescription('List the environment\'s relationships');
        // $this->ignoreValidationErrors(); @todo: Pass extra stuff to ssh? -i?
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);

        $args = array('ssh', $environment->getSshUrl(), 'echo $PLATFORM_RELATIONSHIPS');
        $relationships = $this->getHelper('shell')->execute($args, null, true);

        if (!$relationships) {
            throw new \Exception('No relationships found');
        }
        $results = json_decode(base64_decode($relationships));

        foreach ($results as $key => $relationship) {
            foreach ($relationship as $delta => $object) {
                $output->writeln("<comment>$key:$delta:</comment>");
                foreach ((array) $object as $prop => $value) {
                    if (is_scalar($value)) {
                        $propString = str_pad("$prop",10," ");
                        $output->writeln("<info>  $propString: $value</info>");
                    }
                }
            }
        }
        return 0;
    }

}
