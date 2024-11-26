<?php

namespace Platformsh\Cli\Command\BlueGreen;

use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blue-green:deploy', description: 'Perform a blue/green deployment')]
class BlueGreenDeployCommand extends CommandBase
{
    protected $stability = 'ALPHA';

    protected function configure()
    {
        $this
            ->addOption('routing-percentage', null, InputOption::VALUE_REQUIRED, "Set the latest version's routing percentage", 100)
            ->setHelp('Use this command to deploy the latest (green) version, or otherwise change its routing percentage, during a blue/green deployment.');
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
            $this->stdErr->writeln(sprintf('Enable blue/green first by running: <info>%s blue-green:enable</info>', $this->config()->get('application.executable')));
            return 1;
        }

        $latestVersionData = [];
        foreach ($data as $versionData) {
            if (!$versionData['locked']) {
                $latestVersionData = $versionData;
                break;
            }
        }
        if (empty($latestVersionData)) {
            $this->stdErr->writeln('Failed to find latest version.');
            return 1;
        }

        $targetPercentage = rtrim($input->getOption('routing-percentage'), '%');
        if (!is_numeric($targetPercentage) || $targetPercentage > 100 || $targetPercentage < 0) {
            $this->stdErr->writeln('Invalid percentage: <error>' . $input->getOption('routing-percentage') . '</error>');
            return 1;
        }
        $targetPercentage = (int) $targetPercentage;

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if ($targetPercentage === 100) {
            $questionText = sprintf('Are you sure you want to deploy version <info>%s</info>?', $latestVersionData['id']);
        } else {
            $questionText = sprintf('Are you sure you want to change the routing percentage for version <info>%s</info> from %d to %d?', $latestVersionData['id'], $latestVersionData['routing']['percentage'], $targetPercentage);
        }
        if (!$questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->patch($environment->getLink('#versions') . '/' . rawurlencode($latestVersionData['id']), [
            'json' => [
                'routing' => ['percentage' => $targetPercentage],
            ],
        ]);
        if ($targetPercentage === 100) {
            $this->stdErr->writeln(sprintf('Version <info>%s</info> has now been deployed.', $latestVersionData['id']));
            $this->stdErr->writeln(sprintf('List versions with: <info>%s versions</info>.', $this->config()->get('application.executable')));
        } else {
            $this->stdErr->writeln(sprintf('Version <info>%s</info> now has a routing percentage of %d.', $latestVersionData['id'] ,$targetPercentage));
        }

        return 0;
    }
}
