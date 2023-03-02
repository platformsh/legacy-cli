<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\EnvironmentDomain;
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
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
        $this->addExample('Delete the domain example.com', 'example.com');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        $forEnvironment = $input->getOption('environment') !== null;
        $name = $input->getArgument('name');
        $project = $this->getSelectedProject();

        if ($forEnvironment) {
            $httpClient = $this->api()->getHttpClient();
            $environment = $this->getSelectedEnvironment();
            $domain = EnvironmentDomain::get($name, $environment->getLink('#domains'), $httpClient);
        }
        else {
            $domain = $project->getDomain($name);
        }

        if (!$domain) {
            $this->stdErr->writeln("Domain not found: <error>$name</error>");
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm("Are you sure you want to delete the domain <info>$name</info>?")) {
            return 1;
        }

        $result = $domain->delete();

        $this->stdErr->writeln("The domain <info>$name</info> has been deleted.");

        if ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
