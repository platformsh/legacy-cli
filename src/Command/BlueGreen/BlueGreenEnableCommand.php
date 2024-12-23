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

#[AsCommand(name: 'blue-green:enable', description: 'Enable blue/green deployments')]
class BlueGreenEnableCommand extends CommandBase
{
    protected string $stability = 'ALPHA';
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('routing-percentage', '%', InputOption::VALUE_REQUIRED, "Set the latest version's routing percentage", 100);
        $this->setHelp(
            'Use this command to enable blue/green deployments on an environment.'
            . "\n\n" . 'If multiple environment versions do not already exist, this creates a new version as a copy of the current one.'
            . "\n\n" . '100% of traffic is routed to the current version, and 0% to the new version. This can be flipped or changed with the blue-green:deploy command.'
            . "\n\n" . 'While blue/green deployments are "enabled" (while multiple versions exist), the current version is "locked", and deployments (e.g. from Git pushes) affect the new version.',
        );
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
        if (count($data) > 1) {
            $this->stdErr->writeln(sprintf('Blue/green deployments are already enabled for the environment %s.', $this->api->getEnvironmentLabel($environment)));
            $this->stdErr->writeln(sprintf('List versions by running: <info>%s versions</info>', $this->config->getStr('application.executable')));
            return 0;
        }
        if (!$this->questionHelper->confirm(sprintf('Are you sure you want to enable blue/green deployments for the environment %s?', $this->api->getEnvironmentLabel($environment)))) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->post($environment->getLink('#versions'), ['json' => new \stdClass()]);
        $this->stdErr->writeln(sprintf('Blue/green deployments are now enabled for the environment %s.', $this->api->getEnvironmentLabel($environment)));

        return 0;
    }
}
