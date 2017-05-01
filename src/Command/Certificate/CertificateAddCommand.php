<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\SslUtil;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateAddCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('certificate:add')
            ->setDescription('Add an SSL certificate to the project')
            ->addOption('cert', null, InputOption::VALUE_REQUIRED, 'The path to the certificate file')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The path to the certificate private key file')
            ->addOption('chain', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'The path to the certificate chain file');
        $this->addProjectOption();
        $this->addNoWaitOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $certPath = $input->getOption('cert');
        $keyPath = $input->getOption('key');
        $chainPaths = $input->getOption('chain');
        if (!isset($certPath, $keyPath)) {
            $this->stdErr->writeln('The --cert and --key options are required');
            return 1;
        }

        $options = (new SslUtil())->validate($certPath, $keyPath, $chainPaths);

        $result = $project->addCertificate($options['certificate'], $options['key'], $options['chain']);

        if (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
