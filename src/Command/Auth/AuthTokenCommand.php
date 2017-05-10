<?php
namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AuthTokenCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this->setName('auth:token')
            ->setDescription(sprintf(
                'Obtain an OAuth 2 access token for requests to %s APIs',
                $this->config()->get('service.name')
            ));
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr->writeln(
            '<comment>Keep access tokens secret. Using this command is not recommended.</comment>'
        );

        $output->writeln($this->api()->getAccessToken());

        return 0;
    }
}
