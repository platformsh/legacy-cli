<?php
namespace Platformsh\Cli\Command\Certificate;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'certificate:delete', description: 'Delete a certificate from the project')]
class CertificateDeleteCommand extends CommandBase
{
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'The certificate ID (or the start of it)');
        $this->selector->addProjectOption($this->getDefinition());
        $this->activityMonitor->addWaitOptions($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $id = $input->getArgument('id');
        $project = $selection->getProject();

        $certificate = $project->getCertificate($id);
        if (!$certificate) {
            try {
                $certificate = $this->api->matchPartialId($id, $project->getCertificates(), 'Certificate');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $questionHelper = $this->questionHelper;
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

        if ($this->shouldWait($input)) {
            $activityMonitor = $this->activityMonitor;
            $activityMonitor->waitMultiple($result->getActivities(), $project);
        }

        return 0;
    }
}
