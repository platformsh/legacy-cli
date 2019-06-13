<?php
namespace Platformsh\Cli\Command\Archive;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Model\RemoteContainer\App;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ArchiveExportCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('archive:export')
            ->setDescription('Export an archive from an app')
            ->addOption('exclude-service', 'P', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Exclude a service');
        $this->addRemoteContainerOptions();
        $this->addProjectOption();
        $this->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $environment = $this->getSelectedEnvironment();

        $this->stdErr->writeln(sprintf(
            'Archiving data from the project <info>%s</info>, environment <info>%s</info>',
            $this->api()->getProjectLabel($this->getSelectedProject()),
            $this->api()->getEnvironmentLabel($this->getSelectedEnvironment())
        ));
        $this->stdErr->writeln('');

        $deployment = $this->api()->getCurrentDeployment($environment, true);

        $serviceSupport = [
            'mysql' => 'using "db:dump"',
            'postgresql' => 'using "db:dump"',
            'mongodb' => 'using "mongodump"',
            'network-storage' => 'via mounts',
        ];
        $supported = [];
        $unsupported = [];
        $ignored = [];
        foreach ($deployment->services as $name => $service) {
            list($type, ) = explode(':', $service->type, 2);
            if (isset($serviceSupport[$type]) && !empty($service->disk)) {
                $supported[$name] = $type;
            } elseif (empty($service->disk)) {
                $ignored[$name] = $type;
            } else {
                $unsupported[$name] = $type;
            }
        }

        if (!empty($supported)) {
            $this->stdErr->writeln('Supported services:');
            foreach ($supported as $name => $type) {
                $this->stdErr->writeln(sprintf(
                    '  - <info>%s</info> (%s), %s',
                    $name,
                    $type,
                    $serviceSupport[$type]
                ));
            }
            $this->stdErr->writeln('');
        }

        if (!empty($ignored)) {
            $this->stdErr->writeln('Ignored services, without disk storage:');
            foreach ($ignored as $name => $type) {
                $this->stdErr->writeln(
                    sprintf('  - %s (%s)', $name, $type)
                );
            }
            $this->stdErr->writeln('');
        }

        if (!empty($unsupported)) {
            $this->stdErr->writeln('Unsupported services:');
            foreach ($unsupported as $name => $type) {
                $this->stdErr->writeln(
                    sprintf('  - <error>%s</error> (%s)', $name, $type)
                );
            }
            $this->stdErr->writeln('');
        }

        $apps = [];
        $hasMounts = false;
        foreach ($deployment->webapps as $name => $webApp) {
            $app = new App($webApp, $environment);
            $apps[$name] = $app;
            $hasMounts = $hasMounts || count($app->getMounts());
        }

        if ($hasMounts && count($supported)) {
            $this->stdErr->writeln('Exports from the supported service(s) and files from mounts will be downloaded locally.');
        } elseif (count($supported)) {
            $this->stdErr->writeln('Exports from the above service(s) will be downloaded locally.');
        } elseif ($hasMounts) {
            $this->stdErr->writeln('Files from mounts will be downloaded locally.');
        } else {
            $this->stdErr->writeln('No supported services or mounts were found.');
            return 1;
        }
        $this->stdErr->writeln('');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        if (!$questionHelper->confirm('Are you sure you want to continue?')) {
            return 1;
        }

        if ($hasMounts) {
            foreach ($apps as $app) {
                $mounts = $app->getMounts();
            }
        }
    }
}
