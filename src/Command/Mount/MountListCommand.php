<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends MountCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:list')
            ->setAliases(['mounts'])
            ->setDescription('Get a list of mounts')
            ->addOption('paths', null, InputOption::VALUE_NONE, 'Output the mount paths only (one per line)')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        Table::configureInput($this->getDefinition());
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

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));

        $appConfig = $this->getAppConfig($sshUrl, (bool) $input->getOption('refresh'));

        if (empty($appConfig['mounts'])) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 1;
        }
        /** @var \Platformsh\Cli\Service\Mount $mountService */
        $mountService = $this->getService('mount');
        $mounts = $mountService->normalizeMounts($appConfig['mounts']);

        if ($input->getOption('paths')) {
            $output->writeln(array_keys($mounts));

            return 0;
        }

        $header = ['Mount path', 'Definition'];
        $rows = [];
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        foreach ($mounts as $path => $definition) {
            $rows[] = [$path, $formatter->format($definition)];
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $this->stdErr->writeln(sprintf('Mounts in the app <info>%s</info> (environment <info>%s</info>):', $appConfig['name'], $this->getSelectedEnvironment()->id));
        $table->render($rows, $header);

        return 0;
    }
}
