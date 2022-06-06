<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Self;

use GuzzleHttp\Client;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfStatsCommand extends CommandBase
{
    protected static $defaultName = 'self:stats';
    protected static $defaultDescription = 'View stats on GitHub package downloads';

    private $config;
    private $formatter;
    private $table;

    public function __construct(
        Config $config,
        PropertyFormatter $formatter,
        Table $table
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->table = $table;
        parent::__construct();
    }

    protected function configure()
    {
        $this->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number', 1)
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'Results per page (max: 100)', 20);
        $this->setHidden(true);
        $this->table->configureInput($this->getDefinition());
        $this->formatter->configureInput($this->getDefinition());
    }

    public function isEnabled()
    {
        return $this->config->has('application.github_repo');
    }

    /**
     * {@inheritdoc}
     *
     * @throws \GuzzleHttp\Exception\GuzzleException if the request fails
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $repo = $this->config->get('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $response = (new Client())
            ->request('get', 'https://api.github.com/repos/' . $repoUrl . '/releases', [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
                'query' => [
                    'page' => (int) $input->getOption('page'),
                    'per_page' => (int) $input->getOption('count'),
                ],
            ]);
        $releases = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);

        if (empty($releases)) {
            $this->stdErr->writeln('No releases found.');

            return 1;
        }

        $headers = ['Release', 'Date', 'Asset', 'Downloads'];
        $rows = [];
        foreach ($releases as $release) {
            $row = [];
            $row[] = $release['name'];
            $time = !empty($release['published_at']) ? $release['published_at'] : $release['created_at'];
            $row[] = $this->formatter->format($time, 'created_at');
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    $row[] = $asset['name'];
                    $row[] = $this->formatter->format($asset['download_count']);
                    break;
                }
            }
            $rows[] = $row;
        }

        $this->table->render($rows, $headers);

        return 0;
    }
}
