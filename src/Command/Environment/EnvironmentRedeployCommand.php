<?php

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\Variable\VariableSetCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

class EnvironmentRedeployCommand extends VariableSetCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:redeploy')
            ->setAliases(['redeploy'])
            ->setDescription('Redeploys an environment by setting a variable. This will refresh Lets Encrypt certificates if within two weeks of renewal.')
            ->addArgument('name', InputArgument::OPTIONAL, 'The variable name', 'redeploy')
            ->addArgument('value', InputArgument::OPTIONAL, 'The variable value', date(DATE_ATOM, time()))
            ->addOption('json', null, InputOption::VALUE_NONE, 'Mark the value as JSON');
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addNoWaitOption();
    }
}
