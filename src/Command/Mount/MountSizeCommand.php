<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Helper\Helper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountSizeCommand extends MountCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:size')
            ->setDescription('Check the disk usage of mounts')
            ->addOption('bytes', 'B', InputOption::VALUE_NONE, 'Show sizes in bytes');
        Table::configureInput($this->getDefinition());
        Ssh::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $appName = $this->selectApp($input);

        $appConfig = $this->getAppConfig($appName);
        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }

        $this->stdErr->writeln(sprintf('Checking disk usage for all mounts of the application <info>%s</info>...', $appName));

        // Get a list of the mount paths (and normalize them as relative paths,
        // relative to the application directory).
        $mountPaths = [];
        foreach (array_keys($appConfig['mounts']) as $mountPath) {
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
        $commands[] = 'df -P -B1 -a -x squashfs -x tmpfs -x sysfs -x proc -x devpts';
        $commands[] = 'echo';
        $commands[] = 'cd "$' . $appDirVar . '"';
        foreach ($mountPaths as $mountPath) {
            $commands[] = 'du --block-size=1 -s ' . escapeshellarg($mountPath);
        }
        $command = 'set -e; ' . implode('; ', $commands);

        // Connect to the application via SSH and run the commands.
        $sshArgs = [
            'ssh',
            $this->getSelectedEnvironment()->getSshUrl($appName),
        ];
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $sshArgs = array_merge($sshArgs, $ssh->getSshArgs());
        /** @var \Platformsh\Cli\Service\Shell $shell */
        $shell = $this->getService('shell');
        $result = $shell->execute(array_merge($sshArgs, [$command]), null, true);

        // Separate the commands' output.
        list($appDir, $dfOutput, $duOutput) = explode("\n\n", $result, 3);

        // Parse the output of 'df', building a list of results.
        $results = [];
        foreach (explode("\n", $dfOutput) as $i => $line) {
            if ($i === 0) {
                continue;
            }
            $path = $this->getDfColumn($line, 'path');
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
            $available = $this->getDfColumn($line, 'available');
            $used = $this->getDfColumn($line, 'used');
            $results[$filesystem] = [
                'total' => $this->getDfColumn($line, 'total'),
                'used' => $used,
                'available' => $available,
                'mounts' => [$mountPath],
                'percent_used' => $used / $available * 100,
            ];
        }

        // Parse the 'du' output.
        $mountSizes = [];
        $duOutputSplit = explode("\n", $duOutput, count($mountPaths));
        foreach ($mountPaths as $i => $mountPath) {
            if (!isset($duOutputSplit[$i])) {
                throw new \RuntimeException("Failed to find row $i of 'du' command output: \n" . $duOutput);
            }
            list($mountSizes[$mountPath],) = explode("\t", $duOutputSplit[$i], 2);
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');

        $header = ['Mount(s)', 'Size(s)', 'Disk', 'Used', 'Available', 'Capacity'];

        $showInBytes = $input->getOption('bytes');

        $rows = [];
        foreach ($results as $info) {
            $row = [];
            $row[] = implode("\n", $info['mounts']);
            $mountUsage = [];
            foreach ($info['mounts'] as $mountPath) {
                $mountUsage[] = $mountSizes[$mountPath];
            }
            if ($showInBytes) {
                $row[] = implode("\n", $mountUsage);
                $row[] = $info['total'];
                $row[] = $info['used'];
                $row[] = $info['available'];
            } else {
                $row[] = implode("\n", array_map([Helper::class, 'formatMemory'], $mountUsage));
                $row[] = Helper::formatMemory($info['total']);
                $row[] = Helper::formatMemory($info['used']);
                $row[] = Helper::formatMemory($info['available']);
            }
            $row[] = round($info['percent_used'], 1) . '%';
            $rows[] = $row;
        }

        $table->render($rows, $header);

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
            'filesystem' => '#^(.+?)(\s+[0-9])#',
            'total' => '#([0-9]+)\s+[0-9]+\s+[0-9]+\s+[0-9]+%\s+#',
            'used' => '#([0-9]+)\s+[0-9]+\s+[0-9]+%\s+#',
            'available' => '#([0-9]+)\s+[0-9]+%\s+#',
            'path' => '#%\s+(/.+)$#',
        ];
        if (!isset($columnPatterns[$columnName])) {
            throw new \InvalidArgumentException("Invalid df column: $columnName");
        }
        if (!preg_match($columnPatterns[$columnName], $line, $matches)) {
            throw new \RuntimeException("Failed to find column '$columnName' in df line: \n$line");
        }

        return trim($matches[1]);
    }
}
