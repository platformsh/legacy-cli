<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainDeleteCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:delete')
            ->setDescription('Delete a domain from the project')
            ->addArgument('name', InputArgument::REQUIRED, 'The domain name');
        $this->addProjectOption()->addNoWaitOption();
        $this->addExample('Delete the domain example.com', 'example.com');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $name = $input->getArgument('name');
        $project = $this->getSelectedProject();

        $domain = $project->getDomain($name);

        if (!$domain) {
            $this->stdErr->writeln("Domain not found: <error>$name</error>");
            return 1;
        }

        if (!$this->getHelper('question')->confirm("Are you sure you want to delete the domain <info>$name</info>?")) {
            return 1;
        }

        $result = $domain->delete();

        $this->stdErr->writeln("The domain <info>$name</info> has been deleted.");

        if (!$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $project);
        }

        return 0;
    }
}
