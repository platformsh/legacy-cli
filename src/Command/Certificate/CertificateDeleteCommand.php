<?php
namespace Platformsh\Cli\Command\Certificate;

use GuzzleHttp\Exception\BadResponseException;
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
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)');
        $this->addProjectOption();
        $this->addNoWaitOption();
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
            try {
                $certificate = $this->api()->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm(sprintf('Are you sure you want to delete the certificate <info>%s</info>?', $certificate->id))) {
            return 1;
        }

        try {
            $result = $certificate->delete();
        } catch (BadResponseException $e) {
            if (($response = $e->getResponse()) && $response->getStatusCode() === 403 && $certificate->is_provisioned) {
                $this->stdErr->writeln(sprintf('The certificate <error>%s</error> is automatically provisioned; it cannot be deleted.', $certificate->id));
                return 1;
            }

            throw $e;
        }

        $this->stdErr->writeln(sprintf('The certificate <info>%s</info> has been deleted.', $certificate->id));

        if (!$input->getOption('no-wait')) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
