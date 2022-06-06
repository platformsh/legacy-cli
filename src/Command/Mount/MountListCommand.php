<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends CommandBase
{

    protected static $defaultName = 'mount:list|mounts';
    protected static $defaultDescription = 'Get a list of mounts';

    private $config;
    private $formatter;
    private $mountService;
    private $remoteEnvVars;
    private $selector;
    private $table;

    public function __construct(
        Config $config,
        RemoteEnvVars $remoteEnvVars,
        PropertyFormatter $formatter,
        Mount $mountService,
        Selector $selector,
        Table $table
    ) {
        $this->config = $config;
        $this->formatter = $formatter;
        $this->mountService = $mountService;
        $this->remoteEnvVars = $remoteEnvVars;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOption('paths', null, InputOption::VALUE_NONE, 'Output the mount paths only (one per line)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

        $this->selector->addAllOptions($this->getDefinition(), true);
        $this->table->configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $host = $this->selector->getSelection($input, false, getenv($this->config->get('service.env_prefix') . 'APPLICATION'))
            ->getHost();
        if ($host instanceof LocalHost) {
            $config = (new AppConfig($this->remoteEnvVars->getArrayEnvVar('APPLICATION', $host)));
            $mounts = $this->mountService->mountsFromConfig($config);
        } else {
            $selection = $this->selector->getSelection($input);
            $container = $selection->getRemoteContainer();
            $mounts = $this->mountService->mountsFromConfig($container->getConfig());
        }

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('No mounts found on host: <info>%s</info>', $host->getLabel()));

            return 1;
        }

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $header = ['path' => 'Mount path', 'definition' => 'Definition'];
        $rows = [];
        foreach ($mounts as $path => $definition) {
            $rows[] = [
                'path' => $path,
                'definition' => $this->formatter->format($definition),
            ];
        }

        $this->stdErr->writeln(sprintf('Mounts on <info>%s</info>:', $host->getLabel()));
        $this->table->render($rows, $header);

        return 0;
    }
}
