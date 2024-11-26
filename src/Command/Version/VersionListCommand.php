<?php

namespace Platformsh\Cli\Command\Version;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'version:list', description: 'List environment versions', aliases: ['versions'])]
class VersionListCommand extends CommandBase
{
    protected $stability = 'ALPHA';

    protected function configure()
    {
        $this->addProjectOption();
        $this->addEnvironmentOption();
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input, false, true);
        $environment = $this->getSelectedEnvironment();

        $httpClient = $this->api()->getHttpClient();
        $response = $httpClient->get($environment->getLink('#versions'));
        $data = \GuzzleHttp\Utils::jsonDecode((string) $response->getBody(), true);

        /** @var Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $header = ['id' => 'ID', 'commit' => 'Commit', 'locked' => 'Locked', 'routing_percentage' => 'Routing %'];

        $rows = [];
        foreach ($data as $versionData) {
            $rows[] = [
                'id' => new AdaptiveTableCell($versionData['id'], ['wrap' => false]),
                'commit' => $versionData['commit'],
                'locked' => $formatter->format($versionData['locked'], 'locked'),
                'routing_percentage' => $versionData['routing']['percentage'],
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Versions for the project %s, environment %s:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($environment)
            ));
        }

        $table->render($rows, $header);

        return 0;
    }
}
