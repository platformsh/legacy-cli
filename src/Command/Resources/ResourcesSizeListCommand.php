<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Resources;

use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'resources:size:list', description: 'List container profile sizes', aliases: ['resources:sizes'])]
class ResourcesSizeListCommand extends ResourcesCommandBase
{
    /** @var array<string, string> */
    protected array $tableHeader = [
        'size' => 'Size name',
        'cpu' => 'CPU',
        'memory' => 'Memory (MB)',
        'cpu_type' => 'CPU type',
    ];

    /** @var string[] */
    protected array $defaultColumns = ['size', 'cpu', 'memory'];

    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly ResourcesUtil $resourcesUtil, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'A service name')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'A profile name');
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
        Table::configureInput($this->getDefinition(), $this->tableHeader, $this->defaultColumns);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);
        if (!$this->api->supportsSizingApi($selection->getProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api->getProjectLabel($selection->getProject(), 'comment')));
            return 1;
        }

        $environment = $selection->getEnvironment();
        $nextDeployment = $this->resourcesUtil->loadNextDeployment($environment);

        $services = $this->resourcesUtil->allServices($nextDeployment);
        if (empty($services)) {
            $this->stdErr->writeln('No apps or services found');
            return 1;
        }
        $servicesByProfile = [];
        foreach ($services as $name => $service) {
            $servicesByProfile[$service->container_profile][] = $name;
        }

        $containerProfiles = $this->sortContainerProfiles($nextDeployment->container_profiles);

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
            $options = [];
            foreach ($servicesByProfile as $profile => $serviceNames) {
                $options[$profile] = sprintf('%s (for %s: %s)', $profile, count($serviceNames) === 1 ? 'service' : 'services', implode(', ', $serviceNames));
            }
            $profile = $this->questionHelper->choose($options, 'Enter a number to choose a container profile:');
        } elseif (count($services) === 1) {
            $service = reset($services);
            $profile = $service->container_profile;
        } else {
            throw new InvalidArgumentException('The --service or --profile is required.');
        }

        $rows = [];
        $supportsGuaranteedCPU = $this->api->supportsGuaranteedCPU($selection->getProject(), $nextDeployment);
        $defaultColumns = $this->defaultColumns;
        if ($supportsGuaranteedCPU) {
            $defaultColumns[] = 'cpu_type';
        }
        foreach ($containerProfiles[$profile] as $sizeName => $sizeInfo) {
            if (!$supportsGuaranteedCPU && $sizeInfo['cpu_type'] === 'guaranteed') {
                continue;
            }
            $rows[] = [
                'size' => $sizeName,
                'cpu' => $this->resourcesUtil->formatCPU($sizeInfo['cpu']),
                'memory' => $sizeInfo['memory'],
                'cpu_type' => $sizeInfo['cpu_type'] ?? '',
            ];
        }

        if (!$this->table->formatIsMachineReadable()) {
            if (!empty($servicesByProfile[$profile])) {
                $this->stdErr->writeln(sprintf(
                    'Available sizes in the container profile <info>%s</info> (for %s: <info>%s</info>):',
                    $profile,
                    count($servicesByProfile[$profile]) === 1 ? 'service' : 'services',
                    implode('</info>, <info>', $servicesByProfile[$profile]),
                ));
            } else {
                $this->stdErr->writeln(sprintf('Available sizes in the container profile <info>%s</info>:', $profile));
            }
        }

        $this->table->render($rows, $this->tableHeader, $defaultColumns);

        return 0;
    }
}
