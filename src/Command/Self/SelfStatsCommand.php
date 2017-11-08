<?php
namespace Platformsh\Cli\Command\Self;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SelfStatsCommand extends CommandBase
{
    protected $hiddenInList = true;

    protected function configure()
    {
        $this
            ->setName('self:stats')
            ->setDescription('View stats on GitHub package downloads')
            ->addOption('page', 'p', InputOption::VALUE_REQUIRED, 'Page number', 1);
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
    }

    public function isEnabled()
    {
        return $this->config()->has('application.github_repo');
    }

    /**
     * {@inheritdoc}
     *
     * @throws \GuzzleHttp\Exception\GuzzleException if the request fails
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repo = $this->config()->get('application.github_repo');
        $repoUrl = implode('/', array_map('rawurlencode', explode('/', $repo)));
        $response = $this->api()
            ->getHttpClient()
            ->request('get', 'https://api.github.com/repos/' . $repoUrl . '/releases', [
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
                'auth' => false,
                'query' => [
                    'page' => (int) $input->getOption('page'),
                    'per_page' => 20,
                ],
            ]);
        $releases = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);

        if (empty($releases)) {
            $this->stdErr->writeln('No releases found.');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $headers = ['Release', 'Date', 'Asset', 'Downloads'];
        $rows = [];
        foreach ($releases as $release) {
            $row = [];
            $row[] = $release['name'];
            $time = !empty($release['published_at']) ? $release['published_at'] : $release['created_at'];
            $row[] = $formatter->format($time, 'created_at');
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    $row[] = $asset['name'];
                    $row[] = $formatter->format($asset['download_count']);
                    break;
                }
            }
            $rows[] = $row;
        }

        $table->render($rows, $headers);

        return 0;
    }
}
