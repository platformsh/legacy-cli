<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Local;

use Platformsh\Cli\Service\HostFactory;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\RemoteEnvVars;
use Symfony\Component\Process\Exception\RuntimeException;
use Cocur\Slugify\Slugify;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Drush;
use Platformsh\Client\Exception\EnvironmentStateException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'local:drush-aliases', description: 'Find the project\'s Drush aliases', aliases: ['drush-aliases'])]
class LocalDrushAliasesCommand extends CommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Drush $drush, private readonly Filesystem $filesystem, private readonly HostFactory $hostFactory, private readonly Io $io, private readonly QuestionHelper $questionHelper, private readonly RemoteEnvVars $remoteEnvVars, private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('recreate', 'r', InputOption::VALUE_NONE, 'Recreate the aliases.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Recreate the aliases with a new group name.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the current group name (do nothing else).');
        $this->addExample('Change the alias group to @example', '-g example');
    }

    public function isHidden(): bool
    {
        if (parent::isHidden()) {
            return true;
        }

        // Only show this command if drush_aliases are enabled.
        if (!$this->config->getBool('application.drush_aliases')) {
            return true;
        }

        // Hide the command in the list while in a project directory, if the
        // project is not Drupal.
        // Avoid checking if running in the home directory.
        $projectRoot = $this->selector->getProjectRoot();
        if ($projectRoot && $this->config->getHomeDirectory() !== getcwd() && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projectRoot = $this->selector->getProjectRoot();
        $project = $this->selector->getCurrentProject();
        if (!$projectRoot || !$project) {
            throw new RootNotFoundException();
        }

        $apps = $this->drush->getDrupalApps($projectRoot);
        if (empty($apps)) {
            $this->stdErr->writeln('No Drupal applications found.');

            return 1;
        }

        $current_group = $this->drush->getAliasGroup($project, $projectRoot);

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);

            return 0;
        }

        if ($this->drush->getVersion() === false) {
            $this->stdErr->writeln('Drush is not installed, or the Drush version could not be determined.');
            return 1;
        }

        if ($input->isInteractive()) {
            $this->migrateAliasFiles();
        }

        $aliases = $this->drush->getAliases($current_group);
        $new_group = ltrim((string) $input->getOption('group'), '@');
        if (empty($aliases) && !$new_group && $current_group === $project->id) {
            $new_group = (new Slugify())->slugify($project->title);
        }

        if (($new_group && $new_group != $current_group) || empty($aliases) || $input->getOption('recreate')) {
            $new_group = $new_group ?: $current_group;

            $this->stdErr->writeln("Creating Drush aliases in the group <info>@$new_group</info>");

            if ($new_group !== $current_group) {
                $existing = $this->drush->getAliases($new_group);
                if (!empty($existing)) {
                    $question = "The Drush alias group <info>@$new_group</info> already exists. Overwrite?";
                    if (!$this->questionHelper->confirm($question)) {
                        return 1;
                    }
                }
                $this->drush->setAliasGroup($new_group, $projectRoot);
            }

            $environments = $this->api->getEnvironments($project, true, false);

            // Attempt to find the absolute application root directory for
            // each Enterprise environment. This will be cached by the Drush
            // service ($drush), for use while generating aliases.
            $envVarsService = $this->remoteEnvVars;
            foreach ($environments as $environment) {

                // Cache the environment's deployment information.
                // This will at least be used for \Platformsh\Cli\Service\Drush::getSiteUrl().
                if (!$this->api->hasCachedCurrentDeployment($environment) && $environment->isActive()) {
                    $this->io->debug('Fetching deployment information for environment: ' . $environment->id);
                    try {
                        $this->api->getCurrentDeployment($environment);
                    } catch (BadResponseException $e) {
                        if ($e->getResponse()->getStatusCode() === 400) {
                            $this->io->debug('The deployment is invalid: ' . $e->getMessage());
                        } elseif ($e->getResponse()->getStatusCode() === 404) {
                            $this->io->debug('Current deployment not found: ' . $e->getMessage());
                        } else {
                            throw $e;
                        }
                    } catch (EnvironmentStateException) {
                        $this->io->debug('Current deployment not found.');
                    }
                }

                if (!$environment->isActive() || $environment->deployment_target === 'local') {
                    // We are only interested in active environments with non-grid deployment targets.
                    continue;
                }
                foreach ($apps as $app) {
                    $sshUrl = $environment->getSshUrl($app->getName());
                    if (empty($sshUrl)) {
                        continue;
                    }
                    try {
                        $appRoot = $envVarsService->getEnvVar('APP_DIR', $this->hostFactory->remote($sshUrl, $environment));
                    } catch (RuntimeException $e) {
                        $this->stdErr->writeln(sprintf(
                            'Unable to find app root for environment %s, app %s',
                            $this->api->getEnvironmentLabel($environment, 'comment'),
                            '<comment>' . $app->getName() . '</comment>',
                        ));
                        $this->stdErr->writeln($e->getMessage());
                        continue;
                    }
                    if (!empty($appRoot)) {
                        $this->io->debug(sprintf('App root for %s: %s', $sshUrl, $appRoot));
                        $this->drush->setCachedAppRoot($sshUrl, $appRoot);
                    }
                }
            }

            $this->drush->createAliases($project, $projectRoot, $environments, $current_group);

            $this->ensureDrushConfig();

            if ($new_group !== $current_group && !empty($aliases)) {
                if ($this->questionHelper->confirm("Delete old Drush alias group <info>@$current_group</info>?")) {
                    $this->drush->deleteOldAliases($current_group);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $this->drush->clearCache();

            // Read the new aliases.
            $aliases = $this->drush->getAliases($new_group, true);
        }

        if (!empty($aliases)) {
            $this->stdErr->writeln('Drush aliases for ' . $this->api->getProjectLabel($project) . ':');
            foreach (array_keys($aliases) as $name) {
                $output->writeln('    @' . ltrim($name, '@'));
            }
        }

        return 0;
    }

    /**
     * Ensures that the .drush/drush.yml file has the right config.
     */
    protected function ensureDrushConfig(): void
    {
        if (!is_dir($this->drush->getSiteAliasDir())) {
            return;
        }

        $drushYml = $this->drush->getDrushDir() . '/drush.yml';
        $drushConfig = [];
        if (file_exists($drushYml)) {
            $drushConfig = (array) Yaml::parse((string) file_get_contents($drushYml));
        }
        $aliasPath = $this->drush->getSiteAliasDir();
        if (getenv('HOME')) {
            $aliasPath = str_replace(getenv('HOME') . '/', '${env.home}/', $aliasPath);
        }
        if (empty($drushConfig['drush']['paths']['alias-path'])
            || !in_array($aliasPath, $drushConfig['drush']['paths']['alias-path'], true)) {
            if (file_exists($drushYml)) {
                $this->stdErr->writeln('Writing to <info>~/.drush/drush.yml</info> file to configure the global alias-path');
            } else {
                $this->stdErr->writeln('Creating <info>~/.drush/drush.yml</info> file to configure the global alias-path');
            }

            $drushConfig['drush']['paths']['alias-path'][] = $aliasPath;

            $this->filesystem->writeFile($drushYml, Yaml::dump($drushConfig, 5));
        }
    }

    /**
     * Migrates old alias file(s) from ~/.drush to ~/.drush/site-aliases.
     */
    protected function migrateAliasFiles(): void
    {
        $newDrushDir = $this->drush->getHomeDir() . '/.drush/site-aliases';
        $oldFilenames = $this->drush->getLegacyAliasFiles();
        if (empty($oldFilenames)) {
            return;
        }
        $newDrushDirRelative = str_replace($this->drush->getHomeDir() . '/', '~/', $newDrushDir);
        $confirmText = "Do you want to move your global Drush alias files from <comment>~/.drush</comment> to <comment>$newDrushDirRelative</comment>?";
        if (!$this->questionHelper->confirm($confirmText)) {
            return;
        }

        if (!file_exists($newDrushDir) && !mkdir($newDrushDir, 0o755, true)) {
            $this->stdErr->writeln(sprintf('Failed to create directory: <error>%s</error>', $newDrushDir));
            $this->stdErr->writeln('The alias files have not been moved.');
            return;
        }

        $success = true;
        foreach ($oldFilenames as $oldFilename) {
            $newFilename = $newDrushDir . '/' . basename($oldFilename);
            if (file_exists($newFilename)) {
                $this->stdErr->writeln("Failed to move file <error>$oldFilename</error>, because the destination file already exists.");
                $success = false;
            } elseif (!rename($oldFilename, $newFilename)) {
                $this->stdErr->writeln("Failed to move file <error>$oldFilename</error> to <error>$newFilename</error>");
                $success = false;
            }
        }

        if ($success) {
            $this->stdErr->writeln(sprintf('Global Drush alias files have been successfully moved to <info>%s</info>.', $newDrushDirRelative));
        }
    }
}
