<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CertificateDeleteCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('certificate:delete')
            ->setDescription('Delete a certificate from the project')
            ->addArgument('id', InputArgument::REQUIRED, 'The full certificate ID');
        $this->addProjectOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');
        $project = $this->getSelectedProject();

        $certificate = $project->getCertificate($id);
        if (!$certificate) {
            $this->stdErr->writeln(sprintf('Certificate not found: <error>%s</error>', $id));
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm(sprintf('Are you sure you want to delete the certificate <info>%s</info>?', $certificate->id))) {
            return 1;
        }

        $result = $certificate->delete();

        $this->stdErr->writeln(sprintf('The certificate <info>%s</info> has been deleted.', $certificate->id));

        if (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
