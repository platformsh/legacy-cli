<?php

declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Event\EnvironmentsChangedEvent;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

readonly class DrushAliasUpdater
{
    private OutputInterface $stdErr;

    public function __construct(
        private Config       $config,
        private Drush        $drush,
        private LocalProject $localProject,
        OutputInterface      $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Reacts on the environments list changing, to update Drush aliases.
     */
    public function onEnvironmentsChanged(EnvironmentsChangedEvent $event): void
    {
        // Make sure the local:drush-aliases command is enabled.
        if (!$this->config->isCommandEnabled('local:drush-aliases')) {
            return;
        }
        // Check we are in a local project.
        $projectRoot = $this->localProject->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        // Double-check that the passed project is the current one.
        $projectConfig = $this->localProject->getProjectConfig($projectRoot);
        if (!$projectConfig || empty($projectConfig['id']) || $projectConfig['id'] !== $event->getProject()->id) {
            return;
        }
        // Ignore the project if it doesn't contain a Drupal application.
        if (!Drupal::isDrupal($projectRoot)) {
            return;
        }
        if ($this->drush->getVersion() === false) {
            $this->stdErr->writeln('<options=reverse>DEBUG</> Not updating Drush aliases: the Drush version cannot be determined.', OutputInterface::VERBOSITY_DEBUG);
            return;
        }
        $this->stdErr->writeln('<options=reverse>DEBUG</> Updating Drush aliases', OutputInterface::VERBOSITY_DEBUG);
        try {
            $this->drush->createAliases($event->getProject(), $projectRoot, $event->getEnvironments());
        } catch (\Exception $e) {
            $this->stdErr->writeln(sprintf(
                "<comment>Failed to update Drush aliases:</comment>\n%s\n",
                preg_replace('/^/m', '  ', trim($e->getMessage())),
            ));
        }
    }
}
