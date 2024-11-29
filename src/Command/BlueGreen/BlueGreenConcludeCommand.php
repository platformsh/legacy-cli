<?php

namespace Platformsh\Cli\Command\BlueGreen;

use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blue-green:conclude', description: 'Conclude a blue/green deployment')]
class BlueGreenConcludeCommand extends CommandBase
{
    protected string $stability = 'ALPHA';

    protected function configure()
    {
        $this
            ->setHelp('Use this command to delete the old version after a blue/green deployment, and return to the default deployment flow.');
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input, false, true);
        $environment = $this->getSelectedEnvironment();

        $httpClient = $this->api()->getHttpClient();
        $response = $httpClient->get($environment->getLink('#versions'));
        $data = Utils::jsonDecode((string) $response->getBody(), true);
        if (count($data) < 2) {
            $this->stdErr->writeln(sprintf('Blue/green deployments are not enabled for the environment %s.', $this->api()->getEnvironmentLabel($environment, 'error')));
            return 1;
        }

        $lockedVersionData = [];
        foreach ($data as $versionData) {
            if ($versionData['locked']) {
                $lockedVersionData = $versionData;
                break;
            }
        }
        if (empty($lockedVersionData)) {
            $this->stdErr->writeln('Failed to find old locked version.');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $questionText = sprintf('Are you sure you want to delete version <comment>%s</comment>?', $lockedVersionData['id']);
        if (!$questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->delete($environment->getLink('#versions') . '/' . rawurlencode($lockedVersionData['id']));
        $this->stdErr->writeln(sprintf('Version <info>%s</info> was deleted.', $lockedVersionData['id']));
        $this->stdErr->writeln(sprintf('List versions with: <info>%s versions</info>.', $this->config()->get('application.executable')));

        return 0;
    }
}
