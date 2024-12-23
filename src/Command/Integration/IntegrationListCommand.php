<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:list', description: 'View a list of project integration(s)', aliases: ['integrations'])]
class IntegrationListCommand extends IntegrationCommandBase
{
    /** @var array<string|int, string> */
    private array $tableHeader = ['ID', 'Type', 'Summary'];
    public function __construct(private readonly Config $config, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Filter by type');
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $integrations = $selection->getProject()
                        ->getIntegrations();
        if (!$integrations) {
            $this->stdErr->writeln('No integrations found');

            return 1;
        }

        if ($type = $input->getOption('type')) {
            $integrations = array_filter($integrations, fn(Integration $i): bool => $i->type === $type);
        }
        $rows = [];

        foreach ($integrations as $integration) {
            $rows[] = [
                new AdaptiveTableCell($integration->id, ['wrap' => false]),
                $integration->type,
                $this->getIntegrationSummary($integration),
            ];
        }

        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            $executable = $this->config->getStr('application.executable');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('View integration details with: <info>' . $executable . ' integration:get [id]</info>');
            $this->stdErr->writeln('');
            $this->stdErr->writeln('Add a new integration with: <info>' . $executable . ' integration:add</info>');
            $this->stdErr->writeln('Delete an integration with: <info>' . $executable . ' integration:delete [id]</info>');
        }

        return 0;
    }

    /**
     * @param Integration $integration
     *
     * @return string
     */
    protected function getIntegrationSummary(Integration $integration): string
    {
        $details = $integration->getProperties();
        unset($details['id'], $details['type']);

        switch ($integration->type) {
            case 'github':
            case 'bitbucket':
                $summary = sprintf('Repository: %s', $details['repository']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'bitbucket_server':
                $summary = sprintf('Project: %s', $details['project']);
                $summary .= "\n" . sprintf('Base URL: %s', $details['url']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'gitlab':
                $summary = sprintf('Project: %s', $details['project']);
                $summary .= "\n" . sprintf('Base URL: %s', $details['base_url']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'webhook':
                $summary = sprintf('URL: %s', $details['url']);
                break;

            case 'health.email':
                $summary = 'To: ' . implode(', ', $details['recipients']);
                if (!empty($details['from_address'])) {
                    $summary = 'From: ' . $details['from_address'] . "\n" . $summary;
                }
                break;

            case 'health.slack':
                $summary = sprintf('Channel: %s', $details['channel']);
                break;

            case 'health.pagerduty':
                $summary = sprintf('Routing key: %s', $details['routing_key']);
                break;

            case 'script':
                // Replace tabs with spaces so that table cell width is consistent.
                $summary = "Script:\n" . \substr(\str_replace("\t", '  ', $details['script']), 0, 300);
                break;

            default:
                $summary = json_encode($details, JSON_THROW_ON_ERROR);
        }

        if (strlen($summary) > 240) {
            $summary = substr($summary, 0, 237) . '...';
        }

        return $summary;
    }
}
