<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\AppConfig;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountSizeCommand extends CommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:size')
            ->setDescription('Check the disk usage of mounts')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the cache');
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addRemoteContainerOptions();
        $appConfigFile = $this->config()->get('service.app_config_file');
        $this->setHelp(<<<EOF
Use this command to check the disk size and usage for an application's mounts.

Mounts are directories mounted into the application from a persistent, writable
filesystem. They are configured in the <info>mounts</info> key in the <info>$appConfigFile</info> file.

The filesystem's total size is determined by the <info>disk</info> key in the same file.
EOF
);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $host = $this->selectHost($input, getenv($this->config()->get('service.env_prefix') . 'APPLICATION'));
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        if ($host instanceof LocalHost) {
            /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVars */
            $envVars = $this->getService('remote_env_vars');
            $config = (new AppConfig($envVars->getArrayEnvVar('APPLICATION', $host)));
            $mounts = $mountService->mountsFromConfig($config);
        } else {
            $container = $this->selectRemoteContainer($input);
            $mounts = $mountService->mountsFromConfig($container->getConfig());
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
        $appDirVar = $this->config()->get('service.env_prefix') . 'APP_DIR';
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
        list($appDir, $dfOutput, $duOutput) = explode("\n\n", $result, 3);

        // Parse the output.
        $volumeInfo = $this->parseDf($dfOutput, $appDir, $mountPaths);
        $mountSizes = $this->parseDu($duOutput, $mountPaths);

        // Build a table of results: one line per mount, one (multi-line) row
        // per filesystem.
        $header = [
            'mounts' => 'Mount(s)',
            'sizes' => 'Size(s)',
            'max' => 'Disk',
            'used' => 'Used',
            'available' => 'Available',
            'percent_used' => '% Used',
        ];
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
                $row['max'] = $info['total'];
                $row['used'] = $info['used'];
                $row['available'] = $info['available'];
            } else {
                $row['sizes'] = implode("\n", array_map([Helper::class, 'formatMemory'], $mountUsage));
                $row['max'] = Helper::formatMemory($info['total']);
                $row['used'] = Helper::formatMemory($info['used']);
                $row['available'] = Helper::formatMemory($info['available']);
            }
            $row['percent_used'] = round($info['percent_used'], 1) . '%';
            $rows[] = $row;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->render($rows, $header);

        if (!$table->formatIsMachineReadable()) {
            if (count($volumeInfo) === 1 && count($mountPaths) > 1) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('All the mounts share the same disk.');
            }
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To increase the available space, edit the <info>disk</info> key in the <info>%s</info> file.',
                $this->config()->get('service.app_config_file')
            ));
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
    private function getDfColumn($line, $columnName)
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
     * @param array  $mountPaths
     *
     * @return array
     */
    private function parseDf($dfOutput, $appDir, array $mountPaths)
    {
        $results = [];
        foreach (explode("\n", $dfOutput) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            try {
                $path = $this->getDfColumn($line, 'path');
            } catch (\RuntimeException $e) {
                $this->debug($e->getMessage());
                continue;
            }
            if (strpos($path, $appDir . '/') !== 0) {
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
                'total' => $this->getDfColumn($line, 'total'),
                'used' => $this->getDfColumn($line, 'used'),
                'available' => $this->getDfColumn($line, 'available'),
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
     * @param array  $mountPaths
     *
     * @return array A list of mount sizes (in bytes) keyed by mount path.
     */
    private function parseDu($duOutput, array $mountPaths)
    {
        $mountSizes = [];
        $duOutputSplit = explode("\n", $duOutput, count($mountPaths));
        foreach ($mountPaths as $i => $mountPath) {
            if (!isset($duOutputSplit[$i])) {
                throw new \RuntimeException("Failed to find row $i of 'du' command output: \n" . $duOutput);
            }
            list($mountSizes[$mountPath],) = explode("\t", $duOutputSplit[$i], 2);
        }

        return $mountSizes;
    }
}
