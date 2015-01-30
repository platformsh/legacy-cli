<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Environment;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->setDescription('List an environment\'s relationships')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        // $this->ignoreValidationErrors(); @todo: Pass extra stuff to ssh? -i?
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environment = new Environment($this->environment);

        $args = array('ssh', $environment->getSshUrl($input->getOption('app')), 'echo $PLATFORM_RELATIONSHIPS');
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
