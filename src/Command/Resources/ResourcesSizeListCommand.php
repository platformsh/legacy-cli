<?php

namespace Platformsh\Cli\Command\Resources;

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
    protected $tableHeader = ['size' => 'Size name', 'cpu' => 'CPU', 'memory' => 'Memory (MB)'];
    public function __construct(private readonly Api $api, private readonly QuestionHelper $questionHelper, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure()
    {
        $this
            ->addOption('service', 's', InputOption::VALUE_REQUIRED, 'A service name')
            ->addOption('profile', null, InputOption::VALUE_REQUIRED, 'A profile name');
        $this->addProjectOption()->addEnvironmentOption();
        Table::configureInput($this->getDefinition(), $this->tableHeader);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input);
        if (!$this->api->supportsSizingApi($this->getSelectedProject())) {
            $this->stdErr->writeln(sprintf('The flexible resources API is not enabled for the project %s.', $this->api->getProjectLabel($this->getSelectedProject(), 'comment')));
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
            $questionHelper = $this->questionHelper;
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

        $table = $this->table;

        $rows = [];
        foreach ($containerProfiles[$profile] as $sizeName => $sizeInfo) {
            $rows[] = ['size' => $sizeName, 'cpu' => $this->formatCPU($sizeInfo['cpu']), 'memory' => $sizeInfo['memory']];
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

        $table->render($rows, $this->tableHeader);

        return 0;
    }
}
