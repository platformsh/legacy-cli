<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'certificate:add', description: 'Add an SSL certificate to the project')]
class CertificateAddCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Selector $selector)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the certificate private key file')
            ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        $project = $selection->getProject();

        $certPath = $input->getOption('cert');
        $keyPath = $input->getOption('key');
        $chainPaths = $input->getOption('chain');
        if (!isset($certPath, $keyPath)) {
            $this->stdErr->writeln('The --cert and --key options are required');
            return 1;
        }

        $options = (new SslUtil())->validate($certPath, $keyPath, $chainPaths);

        $result = $project->addCertificate($options['certificate'], $options['key'], $options['chain']);

        if ($this->activityMonitor->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
