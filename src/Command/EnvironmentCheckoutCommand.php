<?php

namespace CommerceGuys\Platform\Cli\Command;

use Guzzle\Http\ClientInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Dumper;

class EnvironmentCheckoutCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:checkout')
            ->setAliases(array('checkout'))
            ->setDescription('Checkout an environment.')
            ->addArgument(
                'branch-id',
                InputArgument::OPTIONAL,
                'The id of the branch to checkout. For example: "sprint2"'
            )
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }
        $branch = $input->getArgument('branch-id');
        if (empty($branch)) {
            $output->writeln("<error>You must specify the id of the branch to checkout.</error>");
            return;
        }

        // Checkout the new branch locally.
        $projectRoot = $this->getProjectRoot();
        $repositoryDir = $projectRoot . '/repository';
        passthru("cd $repositoryDir && git checkout $branch");
    }
}
