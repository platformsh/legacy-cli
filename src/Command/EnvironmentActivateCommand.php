<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentActivateCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:activate')
            ->setDescription('Activate an environment')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment to activate');
        $this->addProjectOption()->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $environmentId = $this->environment['id'];

        if (!$this->operationAllowed('activate')) {
            if (!empty($this->environment['_links']['public-url'])) {
                $output->writeln("The environment <info>$environmentId</info> is already active.");
                return 0;
            }
            $output->writeln(
              "Operation not permitted: The environment <error>$environmentId</error> can't be activated."
            );
            return 1;
        }

        $client = $this->getPlatformClient($this->environment['endpoint']);
        $client->activateEnvironment();

        $output->writeln("The environment <info>$environmentId</info> has been activated.");
        return 0;
    }
}
