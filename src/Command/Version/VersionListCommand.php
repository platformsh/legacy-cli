<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Version;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\PropertyFormatter;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version:list', description: 'List environment versions', aliases: ['versions'])]
class VersionListCommand extends CommandBase
{
    protected string $stability = 'ALPHA';
    public function __construct(private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(selectDefaultEnv: true));
        $environment = $selection->getEnvironment();

        $httpClient = $this->api->getHttpClient();
        $response = $httpClient->get($environment->getLink('#versions'));
        $data = (array) Utils::jsonDecode((string) $response->getBody(), true);

        $header = ['id' => 'ID', 'commit' => 'Commit', 'locked' => 'Locked', 'routing_percentage' => 'Routing %'];

        $rows = [];
        foreach ($data as $versionData) {
            $rows[] = [
                'id' => new AdaptiveTableCell($versionData['id'], ['wrap' => false]),
                'commit' => $versionData['commit'],
                'locked' => $this->propertyFormatter->format($versionData['locked'], 'locked'),
                'routing_percentage' => $versionData['routing']['percentage'],
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Versions for the project %s, environment %s:',
                $this->api->getProjectLabel($selection->getProject()),
                $this->api->getEnvironmentLabel($environment),
            ));
        }

        $this->table->render($rows, $header);

        return 0;
    }
}
