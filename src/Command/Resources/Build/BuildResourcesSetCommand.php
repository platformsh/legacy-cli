<?php

namespace Platformsh\Cli\Command\Resources\Build;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Command\Resources\ResourcesCommandBase;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'resources:build:set', description: 'Set the build resources of a project', aliases: ['build-resources:set'])]
class BuildResourcesSetCommand extends ResourcesCommandBase
{
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this
            ->addOption('cpu', null, InputOption::VALUE_REQUIRED, 'Build CPU')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Build memory (in MB)')
            ->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        if (!$this->api->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $project = $this->getSelectedProject();
        $capabilities = $project->getCapabilities();

        $capability = $capabilities->getProperty('build_resources', false);
        $maxCpu = $capability ? $capability['max_cpu'] : null;
        $maxMemory = $capability ? $capability['max_memory'] : null;

        $settings = $project->getSettings();

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->questionHelper;

        $validateCpu = function ($v) use ($maxCpu): float {
            $f = (float) $v;
            if ($f != $v) {
                throw new InvalidArgumentException('The CPU value must be a number');
            }
            if ($f < 0.1) {
                throw new InvalidArgumentException(sprintf('The minimum allowed CPU is %.1f', 0.1));
            }
            if ($f > $maxCpu) {
                throw new InvalidArgumentException(sprintf('The maximum allowed CPU is %.1f', $maxCpu));
            }
            return $f;
        };
        $validateMemory = function ($v) use ($maxMemory): int {
            $i = (int) $v;
            if ($i != $v) {
                throw new InvalidArgumentException('The memory value must be an integer');
            }
            if ($i < 64) {
                throw new InvalidArgumentException(sprintf('The minimum allowed memory is %d MB', 64));
            }
            if ($i > $maxMemory) {
                throw new InvalidArgumentException(sprintf('The maximum allowed memory is %d MB', $maxMemory));
            }
            return $i;
        };

        $this->stdErr->writeln('Update the build resources on the project: ' . $this->api->getProjectLabel($project));

        $cpuOption = $input->getOption('cpu');
        $memoryOption = $input->getOption('memory');

        try {
            if ($cpuOption !== null) {
                $cpuOption = $validateCpu($cpuOption);
            }
            if ($memoryOption !== null) {
                $memoryOption = $validateMemory($memoryOption);
            }
        } catch (InvalidArgumentException $e) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('<error>%s</error>', rtrim($e->getMessage(), '.')));
            return 1;
        }

        $this->stdErr->writeln('');

        if ($cpuOption === null && $memoryOption === null) {
            $cpuOption = $questionHelper->askInput(
                'CPU size',
                $this->formatCPU($settings['build_resources']['cpu']),
                [],
                $validateCpu,
                'current: '
            );

            $memoryOption = $questionHelper->askInput(
                'Memory size in MB',
                $settings['build_resources']['memory'],
                [],
                $validateMemory,
                'current: '
            );
            $this->stdErr->writeln('');
        }

        $updates = [];
        if ($cpuOption !== null && $cpuOption !== $settings['build_resources']['cpu']) {
            $updates['build_resources']['cpu'] = $cpuOption;
        }
        if ($memoryOption !== null && $memoryOption !== $settings['build_resources']['memory']) {
            $updates['build_resources']['memory'] = $memoryOption;
        }

        if (empty($updates)) {
            $this->stdErr->writeln('No changes were provided: nothing to update.');
            return 0;
        }

        $this->summarizeChanges($updates['build_resources'], $settings['build_resources']);

        $this->debug('Raw updates: ' . json_encode($updates, JSON_UNESCAPED_SLASHES));

        $this->stdErr->writeln('');
        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        $this->stdErr->writeln('');
        $settings->update($updates);

        $this->stdErr->writeln('The settings were successfully updated.');

        return $this->runOtherCommand('resources:build:get', ['--project' => $project->id]);
    }

    /**
     * Summarizes all the changes that would be made.
     *
     * @param array{cpu: int, memory: int} $updates
     * @param array{cpu: int, memory: int} $current
     * @return void
     */
    private function summarizeChanges(array $updates, array $current): void
    {
        $this->stdErr->writeln('<options=bold>Summary of changes:</>');
        $this->stdErr->writeln('  CPU: ' . $this->formatChange(
            $this->formatCPU($current['cpu']),
            $this->formatCPU(isset($updates['cpu']) ? $updates['cpu'] : $current['cpu'])
        ));
        $this->stdErr->writeln('  Memory: ' . $this->formatChange(
            $current['memory'],
            isset($updates['memory']) ? $updates['memory'] : $current['memory'],
            ' MB'
        ));
    }
}
