<?php

namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountListCommand extends CommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('mount:list')
            ->setAliases(['mounts'])
            ->setDescription('List project mounts')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');

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

        /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVarService */
        $envVarService = $this->getService('remote_env_vars');

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));

        $result = $envVarService->getEnvVar('APPLICATION', $sshUrl, $input->getOption('refresh'));
        $appConfig = json_decode(base64_decode($result), true);

        $mounts = $appConfig['mounts'];
        if (empty($mounts)) {
            $this->stdErr->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));

            return 0;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $appConfig, 'mounts');

        return 0;
    }
}
