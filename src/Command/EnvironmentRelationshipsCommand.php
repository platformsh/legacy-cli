<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentRelationshipsCommand extends EnvironmentSshCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->addOption('project', null, InputOption::VALUE_OPTIONAL, 'The project id')
            ->addOption('environment', null, InputOption::VALUE_OPTIONAL, 'The environment id')
            ->setDescription('SSH to the current environment.');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $sshUrlString = $this->getSshUrl();
        $command = 'ssh ' . $sshUrlString . ' "php -r \'echo getenv(\"PLATFORM_RELATIONSHIPS\");\'"';
        $relationships = shell_exec($command);
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
    }

}
