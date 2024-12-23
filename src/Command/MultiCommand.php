<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Service\Shell;
use Platformsh\Client\Model\Project;
use Platformsh\Cli\Application;
use Platformsh\Cli\Console\ArrayArgument;
use Platformsh\Cli\Util\Sort;
use Platformsh\Client\Model\BasicProjectInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\LazyCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'multi', description: 'Execute a command on multiple projects')]
class MultiCommand extends CommandBase
{
    protected bool $canBeRunMultipleTimes = false;

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Identifier $identifier, private readonly Shell $shell)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('cmd', InputArgument::REQUIRED | InputArgument::IS_ARRAY, 'The command to execute')
            ->addOption('projects', 'p', InputOption::VALUE_REQUIRED, 'A list of project IDs. ' . ArrayArgument::SPLIT_HELP)
            ->addOption('continue', null, InputOption::VALUE_NONE, 'Continue running commands even if an exception is encountered')
            ->addOption('sort', null, InputOption::VALUE_REQUIRED, 'A property by which to sort the list of project options', 'title')
            ->addOption('reverse', null, InputOption::VALUE_NONE, 'Reverse the order of project options');
        $this->addExample(
            'List variables on the "main" environment for multiple projects',
            "-p l7ywemwizmmgb,o43m25zns6k2d,3nyujoslhydhx -- var -e main",
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commandArgs = ArrayArgument::getArgument($input, 'cmd');
        $commandName = reset($commandArgs);
        $commandLine = implode(' ', array_map('escapeshellarg', $commandArgs));
        if (!$commandName) {
            throw new InvalidArgumentException('Invalid command: ' . $commandLine);
        }
        $application = new Application();
        $application->setRunningViaMulti();
        $application->setAutoExit(false);
        $application->setIO($input, $output);
        $command = $application->find($commandName);
        if ($command instanceof LazyCommand) {
            $command = $command->getCommand();
        }
        if (!$command instanceof MultiAwareInterface || !$command->canBeRunMultipleTimes()) {
            $this->stdErr->writeln(sprintf(
                'The command <error>%s</error> cannot be run via "%s multi".',
                $commandName,
                $this->config->getStr('application.executable'),
            ));
            return 1;
        } elseif (!$command->getDefinition()->hasOption('project')) {
            $this->stdErr->writeln(sprintf(
                'The command <error>%s</error> does not have a --project option.',
                $commandName,
            ));
            return 1;
        }

        $projects = $this->getSelectedProjects($input);
        if ($projects === false) {
            return 1;
        }

        $success = true;
        $continue = $input->getOption('continue');
        $this->stdErr->writeln(sprintf(
            "Running command on %d %s:  <info>%s</info>",
            count($projects),
            count($projects) === 1 ? 'project' : 'projects',
            $commandLine,
        ));
        foreach ($projects as $project) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=reverse>#</> Project: ' . $this->api->getProjectLabel($project, false));
            try {
                $commandInput = new StringInput($commandLine . ' --project ' . escapeshellarg($project->id));
                $returnCode = $application->run($commandInput, $output);
                if ($returnCode !== 0) {
                    $success = false;
                }
            } catch (\Exception $e) {
                if (!$continue) {
                    throw $e;
                }
                $application->renderThrowable($e, $this->stdErr);
                $success = false;
            }
        }

        return $success ? 0 : 1;
    }

    /**
     * Shows a checklist using the dialog utility.
     *
     * @param array<string, string> $options An array of project labels keyed by ID.
     *
     * @return string[] A list of project IDs.
     */
    protected function showDialogChecklist(array $options, string $text = 'Choose item(s)'): array
    {
        $width = 80;
        $height = 20;
        $listHeight = 20;
        $command = sprintf(
            'dialog --separate-output --checklist %s %d %d %d',
            escapeshellarg($text),
            $height,
            $width,
            $listHeight,
        );
        foreach ($options as $tag => $option) {
            $command .= sprintf(' %s %s off', escapeshellarg($tag), escapeshellarg($option));
        }

        $dialogRc = file_get_contents(CLI_ROOT . '/resources/console/dialogrc');
        $dialogRcFile = $this->config->getWritableUserDir() . '/dialogrc';
        if ($dialogRc !== false && (file_exists($dialogRcFile) || file_put_contents($dialogRcFile, $dialogRc))) {
            putenv('DIALOGRC=' . $dialogRcFile);
        }

        $pipes = [2 => null];
        $process = proc_open($command, [
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!$process) {
            throw new \RuntimeException('Failed to start dialog command: ' . $process);
        }

        // Wait for and read result.
        $result = array_filter(explode("\n", trim((string) stream_get_contents($pipes[2]))));

        // Close handles.
        if (is_resource($pipes[2])) {
            fclose($pipes[2]);
        }

        proc_close($process);

        $this->stdErr->writeln('');

        return $result;
    }

    /**
     * Get a list of the user's projects, sorted according to the input.
     *
     * @param InputInterface $input
     *
     * @return BasicProjectInfo[]
     */
    protected function getAllProjectsBasicInfo(InputInterface $input): array
    {
        $projects = $this->api->getMyProjects();
        if ($input->getOption('sort')) {
            Sort::sortObjects($projects, $input->getOption('sort'));
        }
        if ($input->getOption('reverse')) {
            $projects = array_reverse($projects, true);
        }

        return $projects;
    }

    /**
     * Get the projects selected by the user.
     *
     * Projects can be specified via the command-line option --projects (as a
     * list of project IDs) or, if possible, the user will be prompted with a
     * checklist via the 'dialog' utility.
     *
     * @param InputInterface $input
     *
     * @return Project[]|false
     *   An array of projects, or false on error.
     */
    protected function getSelectedProjects(InputInterface $input): false|array
    {
        $projectList = ArrayArgument::getOption($input, 'projects');

        if (!empty($projectList)) {
            $missing = [];
            $selected = [];
            foreach ($projectList as $projectId) {
                try {
                    $result = $this->identifier->identify($projectId);
                } catch (InvalidArgumentException) {
                    $missing[] = $projectId;
                    continue;
                }
                $project = $this->api->getProject($result['projectId'], $result['host']);
                if ($project !== false) {
                    $selected[$project->id] = $project;
                } else {
                    $missing[] = $projectId;
                }
            }
            if (!empty($missing)) {
                $this->stdErr->writeln(sprintf('Project ID(s) not found: <error>%s</error>', implode(', ', $missing)));
                return false;
            }

            return $selected;
        }

        if (!$input->isInteractive()) {
            $this->stdErr->writeln('In non-interactive mode, the --projects option must be specified.');
            return false;
        }
        if (!$this->shell->commandExists('dialog')) {
            $this->stdErr->writeln('The "dialog" utility is required for interactive use.');
            $this->stdErr->writeln('You can specify projects via the --projects option.');
            return false;
        }

        $projectInfos = $this->getAllProjectsBasicInfo($input);
        $projectOptions = [];
        foreach ($projectInfos as $info) {
            $projectOptions[$info->id] = $info->title ?: $info->id;
        }

        $projectIds = $this->showDialogChecklist($projectOptions, 'Choose one or more projects');
        if (empty($projectIds)) {
            return false;
        }
        $selected = array_intersect(array_keys($projectOptions), $projectIds);
        $this->stdErr->writeln('Selected project(s): ' . implode(',', $selected));
        $this->stdErr->writeln('');

        return array_map(function ($id) {
            $project = $this->api->getProject($id);
            if (!$project) {
                throw new \RuntimeException('Failed to fetch project: ' . $id);
            }
            return $project;
        }, $selected);
    }
}
