<?php

namespace Platformsh\Cli\Command\Resources\Build;

use Platformsh\Cli\Command\Resources\ResourcesCommandBase;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BuildResourcesSetCommand extends ResourcesCommandBase
{
    protected function configure()
    {
        $this->setName('resources:build:set')
            ->setAliases(['build-resources:set'])
            ->setDescription('Set the build resources of a project')
            ->addOption('cpu', null, InputOption::VALUE_REQUIRED, 'Build CPU')
            ->addOption('memory', null, InputOption::VALUE_REQUIRED, 'Build memory (in MB)')
            ->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$this->api()->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $project = $this->getSelectedProject();
        $settings = $project->getSettings();

        $cpuOption = $input->getOption('cpu');
        $memoryOption = $input->getOption('memory');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $validateCpu = function ($v) {
            $f = (float) $v;
            if ($f != $v) {
                throw new RuntimeException('The CPU value must be a number');
            }
            return $f;
        };
        $validateMemory = function ($v) {
            $i = (int) $v;
            if ($i != $v) {
                throw new RuntimeException('The memory value must be an integer');
            }
            return $i;
        };

        $this->stdErr->writeln('Update the build resources on the project: ' . $this->api()->getProjectLabel($project));
        $this->stdErr->writeln('');

        $newline = false;

        if ($cpuOption !== null) {
            $cpuOption = $validateCpu($cpuOption);
        } else {
            $cpuOption = $questionHelper->askInput(
                'CPU size',
                $this->formatCPU($settings['build_resources']['cpu']),
                [],
                $validateCpu,
                'current: '
            );
            $newline = true;
        }
        if ($memoryOption !== null) {
            $memoryOption = $validateMemory($memoryOption);
        }
        else {
            $memoryOption = $questionHelper->askInput(
                'Memory size in MB',
                $settings['build_resources']['memory'],
                [],
                $validateMemory,
                'current: '
            );
            $newline = true;
        }
        if ($newline) {
            $this->stdErr->writeln('');
        }

        $updates = [];
        if ($cpuOption !== $settings['build_resources']['cpu']) {
            $updates['build_resources']['cpu'] = $cpuOption;
        }
        if ($memoryOption !== $settings['build_resources']['memory']) {
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
    private function summarizeChanges(array $updates, array $current)
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
