<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
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
          ->setAliases(array('relationships'))
          ->setDescription('List an environment\'s relationships')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
          ->addOption('no-wait', null, InputOption::VALUE_NONE, "Do not wait for the environment to become active first");
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $selectedEnvironment = $this->getSelectedEnvironment();

        $this->waitUntilEnvironmentActive($selectedEnvironment, $this->getSelectedProject(), $input);

        $sshUrl = $selectedEnvironment->getSshUrl($input->getOption('app'));

        $util = new RelationshipsUtil($this->stdErr);
        $relationships = $util->getRelationships($sshUrl);
        if (!$relationships) {
            $this->stdErr->writeln('No relationships found');
            return 1;
        }

        foreach ($relationships as $key => $relationship) {
            foreach ($relationship as $delta => $info) {
                $output->writeln("<comment>$key:$delta:</comment>");
                foreach ($info as $prop => $value) {
                    if (is_scalar($value)) {
                        $propString = str_pad("$prop", 10, " ");
                        $output->writeln("<info>  $propString: $value</info>");
                    }
                }
            }
        }

        return 0;
    }
}
