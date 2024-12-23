<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Service\Config;
use GuzzleHttp\Client;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'self:stats', description: 'View stats on GitHub package downloads')]
class SelfStatsCommand extends CommandBase
{
    protected bool $hiddenInList = true;

    /** @var array<string|int, string> */
    private array $tableHeader = ['Release', 'Date', 'Asset', 'Downloads'];

    public function __construct(private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number', 1)
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Results per page (max: 100)', 20);
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        PropertyFormatter::configureInput($this->getDefinition());
    }

    public function isEnabled(): bool
    {
        return $this->config->has('application.github_repo');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->config->getStr('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $response = (new Client())
            ->get('https://api.github.com/repos/' . $repoUrl . '/releases', [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
                'query' => [
                    'page' => (int) $input->getOption('page'),
                    'per_page' => (int) $input->getOption('count'),
                ],
            ]);
        $releases = (array) Utils::jsonDecode((string) $response->getBody(), true);

        if (empty($releases)) {
            $this->stdErr->writeln('No releases found.');

            return 1;
        }
        $rows = [];
        foreach ($releases as $release) {
            $row = [];
            $row[] = $release['name'];
            $time = !empty($release['published_at']) ? $release['published_at'] : $release['created_at'];
            $row[] = $this->propertyFormatter->format($time, 'created_at');
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    $row[] = $asset['name'];
                    $row[] = $this->propertyFormatter->format($asset['download_count']);
                    break;
                }
            }
            $rows[] = $row;
        }

        $this->table->render($rows, $this->tableHeader);

        return 0;
    }
}
