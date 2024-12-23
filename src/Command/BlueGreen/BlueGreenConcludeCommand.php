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
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'blue-green:conclude', description: 'Conclude a blue/green deployment')]
class BlueGreenConcludeCommand extends CommandBase
{
    protected string $stability = 'ALPHA';
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setHelp('Use this command to delete the old version after a blue/green deployment, and return to the default deployment flow.');
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

        $questionText = sprintf('Are you sure you want to delete version <comment>%s</comment>?', $lockedVersionData['id']);
        if (!$this->questionHelper->confirm($questionText)) {
            return 1;
        }

        $this->stdErr->writeln('');
        $httpClient->delete($environment->getLink('#versions') . '/' . rawurlencode((string) $lockedVersionData['id']));
        $this->stdErr->writeln(sprintf('Version <info>%s</info> was deleted.', $lockedVersionData['id']));
        $this->stdErr->writeln(sprintf('List versions with: <info>%s versions</info>.', $this->config->getStr('application.executable')));

        return 0;
    }
}
