<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Certificate;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'certificate:get', description: 'View a certificate')]
class CertificateGetCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The certificate property to view');
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $id = $input->getArgument('id');
        $cert = $project->getCertificate($id);
        if (!$cert) {
            try {
                /** @var Certificate $cert */
                $cert = $this->api->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $this->propertyFormatter->displayData($output, $cert->getProperties(), $input->getOption('property'));

        return 0;
    }
}
