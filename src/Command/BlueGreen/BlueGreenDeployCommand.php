<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\BlueGreen;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blue-green:deploy', description: 'Perform a blue/green deployment')]
class BlueGreenDeployCommand extends CommandBase
{
    protected string $stability = 'ALPHA';
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('routing-percentage', null, InputOption::VALUE_REQUIRED, "Set the latest version's routing percentage", 100)
            ->setHelp('Use this command to deploy the latest (green) version, or otherwise change its routing percentage, during a blue/green deployment.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));
        $environment = $selection->getEnvironment();

        $httpClient = $this->api->getHttpClient();
        $response = $httpClient->get($environment->getLink('#versions'));
        $data = (array) Utils::jsonDecode((string) $response->getBody(), true);
        if (count($data) < 2) {
            $this->stdErr->writeln(sprintf('Blue/green deployments are not enabled for the environment %s.', $this->api->getEnvironmentLabel($environment, 'error')));
            $this->stdErr->writeln(sprintf('Enable blue/green first by running: <info>%s blue-green:enable</info>', $this->config->getStr('application.executable')));
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

        $targetPercentage = rtrim((string) $input->getOption('routing-percentage'), '%');
        if (!is_numeric($targetPercentage) || $targetPercentage > 100 || $targetPercentage < 0) {
            $this->stdErr->writeln('Invalid percentage: <error>' . $input->getOption('routing-percentage') . '</error>');
            return 1;
        }
        $targetPercentage = (int) $targetPercentage;

        if ($targetPercentage === 100) {
            $questionText = sprintf('Are you sure you want to deploy version <info>%s</info>?', $latestVersionData['id']);
        } else {
            $questionText = sprintf('Are you sure you want to change the routing percentage for version <info>%s</info> from %d to %d?', $latestVersionData['id'], $latestVersionData['routing']['percentage'], $targetPercentage);
        }
        if (!$this->questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->patch($environment->getLink('#versions') . '/' . rawurlencode((string) $latestVersionData['id']), [
            'json' => [
                'routing' => ['percentage' => $targetPercentage],
            ],
        ]);
        if ($targetPercentage === 100) {
            $this->stdErr->writeln(sprintf('Version <info>%s</info> has now been deployed.', $latestVersionData['id']));
            $this->stdErr->writeln(sprintf('List versions with: <info>%s versions</info>.', $this->config->getStr('application.executable')));
        } else {
            $this->stdErr->writeln(sprintf('Version <info>%s</info> now has a routing percentage of %d.', $latestVersionData['id'], $targetPercentage));
        }

        return 0;
    }
}
