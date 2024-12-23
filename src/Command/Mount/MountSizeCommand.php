<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'mount:size', description: 'Check the disk usage of mounts')]
class MountSizeCommand extends CommandBase
{
    /** @var array<string, string> */
    private array $tableHeader = [
        'mounts' => 'Mount(s)',
        'sizes' => 'Size(s)',
        'max' => 'Disk',
        'used' => 'Used',
        'available' => 'Available',
        'percent_used' => '% Used',
    ];
    public function __construct(private readonly Config $config, private readonly Io $io, private readonly Mount $mount, private readonly RemoteEnvVars $remoteEnvVars, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the cache');
        Table::configureInput($this->getDefinition(), $this->tableHeader);
        Ssh::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->selector->addRemoteContainerOptions($this->getDefinition());
        $this->addCompleter($this->selector);
        $help = <<<EOF
            Use this command to check the disk size and usage for an application's mounts.

            Mounts are directories mounted into the application from a persistent, writable
            filesystem. They are configured in the <info>mounts</info> key in the application configuration.

            The filesystem's total size is determined by the <info>disk</info> key in the same file.
            EOF;
        if ($this->config->getBool('api.metrics')) {
            $this->stability = self::STABILITY_DEPRECATED;
            $help .= "\n\n";
            $help .= '<options=bold;fg=yellow>Deprecated:</>';
            $help .= sprintf("\nThis command is deprecated and will be removed in a future version.\nTo see disk metrics, run: <comment>%s disk</comment>", $this->config->getStr('application.executable'));
        }
        $this->setHelp($help);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(
            allowLocalHost: getenv($this->config->getStr('service.env_prefix') . 'APPLICATION') !== false,
        ));
        $host = $this->selector->getHostFromSelection($input, $selection);
        if ($host instanceof LocalHost) {
            $envVars = $this->remoteEnvVars;
            $config = (new AppConfig($envVars->getArrayEnvVar('APPLICATION', $host)));
            $mounts = $this->mount->mountsFromConfig($config);
        } else {
            $container = $selection->getRemoteContainer();
            $mounts = $this->mount->mountsFromConfig($container->getConfig());
        }

        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('No mounts found on host: <info>%s</info>', $host->getLabel()));

            return 1;
        }

        $this->stdErr->writeln(sprintf('Checking disk usage for all mounts on <info>%s</info>...', $host->getLabel()));
        $this->stdErr->writeln('');

        // Get a list of the mount paths (and normalize them as relative paths,
        // relative to the application directory).
        $mountPaths = [];
        foreach (array_keys($mounts) as $mountPath) {
            $mountPaths[] = trim(trim($mountPath), '/');
        }

        // Build a list of multiple commands that will be run over the same SSH
        // connection:
        //   1. Get the application directory (by reading the PLATFORM_APP_DIR
        //      environment variable).
        //   2. Run the 'df' command to find filesystem statistics for the
        //      mounts.
        //   3. Run a 'du' command on each of the mounted paths, to find their
        //      individual sizes.
        $appDirVar = $this->config->getStr('service.env_prefix') . 'APP_DIR';
        $commands = [];
        $commands[] = 'echo "$' . $appDirVar . '"';
        $commands[] = 'echo';

        // The 'df' command uses '-x' to exclude a bunch of irrelevant
        // filesystem types. Currently mounts appear to use 'ext4', but that
        // may not always be the case.
        $commands[] = 'df -P -B1 -a -x squashfs -x tmpfs -x sysfs -x proc -x devpts -x rpc_pipefs -x cgroup -x fake-sysfs';

        $commands[] = 'echo';
        $commands[] = 'cd "$' . $appDirVar . '"';

        foreach ($mountPaths as $mountPath) {
            // The lost+found directory is excluded as it won't be readable.
            $commands[] = 'du --block-size=1 --exclude=lost+found -s ' . escapeshellarg($mountPath);
        }
        $command = 'set -e; ' . implode('; ', $commands);

        // Connect to the application via SSH and run the commands.
        $result = $host->runCommand($command);

        // Separate the commands' output.
        [$appDir, $dfOutput, $duOutput] = explode("\n\n", (string) $result, 3);

        // Parse the output.
        $volumeInfo = $this->parseDf($dfOutput, $appDir, $mountPaths);
        $mountSizes = $this->parseDu($duOutput, $mountPaths);

        // Build a table of results: one line per mount, one (multi-line) row
        // per filesystem.
        $rows = [];
        $showInBytes = $input->getOption('bytes');
        foreach ($volumeInfo as $info) {
            $row = [];
            $row['mounts'] = implode("\n", $info['mounts']);
            $mountUsage = [];
            foreach ($info['mounts'] as $mountPath) {
                $mountUsage[] = $mountSizes[$mountPath];
            }
            if ($showInBytes) {
                $row['sizes'] = implode("\n", $mountUsage);
                $row['max'] = (string) $info['total'];
                $row['used'] = (string) $info['used'];
                $row['available'] = (string) $info['available'];
            } else {
                $row['sizes'] = implode("\n", array_map(Helper::formatMemory(...), $mountUsage));
                $row['max'] = Helper::formatMemory($info['total']);
                $row['used'] = Helper::formatMemory($info['used']);
                $row['available'] = Helper::formatMemory($info['available']);
            }
            $row['percent_used'] = round($info['percent_used'], 1) . '%';
            $rows[] = $row;
        }
        $this->table->render($rows, $this->tableHeader);

        if (!$this->table->formatIsMachineReadable()) {
            if (count($volumeInfo) === 1 && count($mountPaths) > 1) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('All the mounts share the same disk.');
            }
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'To increase the available space, edit the <info>disk</info> key in the application configuration.',
            );

            if ($this->config->getBool('api.metrics') && $this->config->isCommandEnabled('metrics:disk')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('<options=bold;fg=yellow>Deprecated:</>');
                $this->stdErr->writeln('This command is deprecated and will be removed in a future version.');
                $this->stdErr->writeln(sprintf('To see disk metrics, run: <comment>%s disk</comment>', $this->config->getStr('application.executable')));
            }
        }

        return 0;
    }

    /**
     * Get a column from a line of df output.
     *
     * Unfortunately there doesn't seem to be a more reliable way to parse df
     * output than by regular expression.
     *
     * @param string $line
     * @param string $columnName
     *
     * @return string
     */
    private function getDfColumn(string $line, string $columnName): string
    {
        $columnPatterns = [
            'filesystem' => '/^(.+?)(\s+[0-9])/',
            'total' => '/([0-9]+)\s+[0-9]+\s+[0-9]+\s+([0-9]+%|-)\s+/',
            'used' => '/([0-9]+)\s+[0-9]+\s+([0-9]+%|-)\s+/',
            'available' => '/([0-9]+)\s+([0-9]+%|-)\s+/',
            'path' => '/\s(?:[0-9]+%|-)\s+(\/.+)$/',
        ];
        if (!isset($columnPatterns[$columnName])) {
            throw new \InvalidArgumentException("Invalid df column: $columnName");
        }
        if (!preg_match($columnPatterns[$columnName], $line, $matches)) {
            throw new \RuntimeException("Failed to find column '$columnName' in df line: \n$line");
        }

        return trim($matches[1]);
    }

    /**
     * Parse the output of 'df', building a list of results per FS volume.
     *
     * @param string $dfOutput
     * @param string $appDir
     * @param string[] $mountPaths
     *
     * @return array<string, array{total: int, used: int, available: int, mounts: string[], percent_used: float}>
     */
    private function parseDf(string $dfOutput, string $appDir, array $mountPaths): array
    {
        $results = [];
        foreach (explode("\n", $dfOutput) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            try {
                $path = $this->getDfColumn($line, 'path');
            } catch (\RuntimeException $e) {
                $this->io->debug($e->getMessage());
                continue;
            }
            if (!str_starts_with($path, $appDir . '/')) {
                continue;
            }
            $mountPath = ltrim(substr($path, strlen($appDir)), '/');
            if (!in_array($mountPath, $mountPaths)) {
                continue;
            }
            $filesystem = $this->getDfColumn($line, 'filesystem');
            if (isset($results[$filesystem])) {
                $results[$filesystem]['mounts'][] = $mountPath;
                continue;
            }
            $results[$filesystem] = [
                'total' => (int) $this->getDfColumn($line, 'total'),
                'used' => (int) $this->getDfColumn($line, 'used'),
                'available' => (int) $this->getDfColumn($line, 'available'),
                'mounts' => [$mountPath],
            ];
            $results[$filesystem]['percent_used'] = $results[$filesystem]['used'] / $results[$filesystem]['total'] * 100;
        }

        return $results;
    }

    /**
     * Parse the 'du' output.
     *
     * @param string $duOutput
     * @param string[] $mountPaths
     *
     * @return array<string, int> A list of mount sizes (in bytes) keyed by mount path.
     */
    private function parseDu(string $duOutput, array $mountPaths): array
    {
        $mountSizes = [];
        $duOutputSplit = explode("\n", $duOutput, count($mountPaths));
        foreach ($mountPaths as $i => $mountPath) {
            if (!isset($duOutputSplit[$i])) {
                throw new \RuntimeException("Failed to find row $i of 'du' command output: \n" . $duOutput);
            }
            $parts = explode("\t", $duOutputSplit[$i], 2);
            $mountSizes[$mountPath] = (int) $parts[0];
        }

        return $mountSizes;
    }
}
