<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->setAliases(['relationships'])
            ->setDescription('Show an environment\'s relationships')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the relationships');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'master' environment's relationships", 'master');
        $this->addExample("View the 'master' environment's database port", 'master --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $app = $this->selectApp($input);
        $environment = $this->getSelectedEnvironment();

        $sshUrl = $environment->getSshUrl($app);
        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $value = $relationshipsService->getRelationships($sshUrl, $input->getOption('refresh'));

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $value, $input->getOption('property'));
    }
}
