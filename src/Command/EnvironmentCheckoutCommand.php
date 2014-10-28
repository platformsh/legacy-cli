<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentCheckoutCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:checkout')
            ->setAliases(array('checkout'))
            ->setDescription('Checkout an environment.')
            ->addArgument(
                'branch-name',
                InputArgument::OPTIONAL,
                'The name of the branch to checkout. For example: "sprint2"'
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
        $branch = $input->getArgument('branch-name');
        if (empty($branch)) {
            $output->writeln("<error>You must specify the name of the branch to checkout.</error>");
            return;
        }

        // Checkout the new branch locally.
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new \Exception('This can only be run from inside a project directory');
        }
        $repositoryDir = $projectRoot . '/repository';
        passthru("cd " . escapeshellarg($repositoryDir) . " && git fetch origin && git checkout $branch");
    }
}
