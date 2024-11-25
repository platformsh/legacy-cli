<?php

namespace Platformsh\Cli\Command\BlueGreen;

use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BlueGreenEnableCommand extends CommandBase
{
    protected $stability = 'ALPHA';

    protected function configure()
    {
        $this->setName('blue-green:enable')
            ->setDescription('Enable blue/green deployments')
            ->addOption('routing-percentage', '%', InputOption::VALUE_REQUIRED, "Set the latest version's routing percentage", 100);
        $this->setHelp(
            'Use this command to enable blue/green deployments on an environment.'
            . "\n\n" . 'If multiple environment versions do not already exist, this creates a new version as a copy of the current one.'
            . "\n\n" . '100% of traffic is routed to the current version, and 0% to the new version. This can be flipped or changed with the blue-green:deploy command.'
            . "\n\n" . 'While blue/green deployments are "enabled" (while multiple versions exist), the current version is "locked", and deployments (e.g. from Git pushes) affect the new version.'
        );
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, false, true);
        $environment = $this->getSelectedEnvironment();

        $httpClient = $this->api()->getHttpClient();
        $response = $httpClient->get($environment->getLink('#versions'));
        $data = Utils::jsonDecode((string) $response->getBody(), true);
        if (count($data) > 1) {
            $this->stdErr->writeln(sprintf('Blue/green deployments are already enabled for the environment %s.', $this->api()->getEnvironmentLabel($environment)));
            $this->stdErr->writeln(sprintf('List versions by running: <info>%s versions</info>', $this->config()->get('application.executable')));
            return 0;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if (!$questionHelper->confirm(sprintf('Are you sure you want to enable blue/green deployments for the environment %s?', $this->api()->getEnvironmentLabel($environment)))) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->post($environment->getLink('#versions'), ['json' => new \stdClass()]);
        $this->stdErr->writeln(sprintf('Blue/green deployments are now enabled for the environment %s.', $this->api()->getEnvironmentLabel($environment)));

        return 0;
    }
}
