<?php

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResourcesSizeListCommand extends ResourcesCommandBase
{
    protected $tableHeader = [
        'size' => 'Size name',
        'cpu' => 'CPU',
        'memory' => 'Memory (MB)',
        'cpu_type' => 'CPU type',
    ];
    protected $defaultColumns = ['size', 'cpu', 'memory'];

    protected function configure()
    {
        $this->setName('resources:size:list')
            ->setAliases(['resources:sizes'])
            ->setDescription('List container profile sizes')
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'A service name')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'A profile name');
        $this->addProjectOption()->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$this->api()->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api()->getProjectLabel($this->getSelectedProject(), 'comment')));
            return 1;
        }

        $environment = $this->getSelectedEnvironment();
        $nextDeployment = $this->loadNextDeployment($environment);

        $services = $this->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }
        $servicesByProfile = [];
        foreach ($services as $name => $service) {
            $servicesByProfile[$service->container_profile][] = $name;
        }

        $containerProfiles = $nextDeployment->container_profiles;

        if ($serviceOption = $input->getOption('service')) {
            if (!isset($services[$serviceOption])) {
                $this->stdErr->writeln('Service not found: <error>' . $serviceOption . '</error>');
                return 1;
            }
            $service = $services[$serviceOption];
            $profile = $service->container_profile;
        } elseif ($profileOption = $input->getOption('profile')) {
            $profile = $profileOption;
            if (!isset($containerProfiles[$profile])) {
                $this->stdErr->writeln('Profile not found: ' . $profile);
                return 1;
            }
        } elseif ($input->isInteractive()) {
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $options = [];
            foreach ($servicesByProfile as $profile => $serviceNames) {
                $options[$profile] = sprintf('%s (for %s: %s)', $profile, count($serviceNames) === 1 ? 'service' : 'services', implode(', ', $serviceNames));
            }
            $profile = $questionHelper->choose($options, 'Enter a number to choose a container profile:');
        } elseif (count($services) === 1) {
            $service = reset($services);
            $profile = $service->container_profile;
        } else {
            throw new InvalidArgumentException('The --service or --profile is required.');
        }

        /** @var Table $table */
        $table = $this->getService('table');

        $rows = [];
        $supportsGuaranteedCPU = $this->supportsGuaranteedCPU($nextDeployment->project_info);
        $defaultColumns = $this->defaultColumns;
        if ($supportsGuaranteedCPU) {
            $defaultColumns[] = 'cpu_type';
        }
        foreach ($containerProfiles[$profile] as $sizeName => $sizeInfo) {
            if (!$supportsGuaranteedCPU && $sizeInfo['cpu_type'] == 'guaranteed') {
                continue;
            }
            $rows[] = [
                'size' => $sizeName,
                'cpu' => $this->formatCPU($sizeInfo['cpu']),
                'memory' => $sizeInfo['memory'],
                'cpu_type' => isset($sizeInfo['cpu_type']) ? $sizeInfo['cpu_type'] : '',
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            if (!empty($servicesByProfile[$profile])) {
                $this->stdErr->writeln(sprintf(
                    'Available sizes in the container profile <info>%s</info> (for %s: <info>%s</info>):',
                    $profile,
                    count($servicesByProfile[$profile]) === 1 ? 'service' : 'services',
                    implode('</info>, <info>', $servicesByProfile[$profile])
                ));
            } else {
                $this->stdErr->writeln(sprintf('Available sizes in the container profile <info>%s</info>:', $profile));
            }
        }

        $table->render($rows, $this->tableHeader, $defaultColumns);

        return 0;
    }
}
