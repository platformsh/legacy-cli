<?php
namespace Platformsh\Cli\Command\Local;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Drush;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class LocalDrushAliasesCommand extends CommandBase
{
    protected $local = true;

    protected function configure()
    {
        $this
            ->setName('local:drush-aliases')
            ->setAliases(['drush-aliases'])
            ->addOption('recreate', 'r', InputOption::VALUE_NONE, 'Recreate the aliases.')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Recreate the aliases with a new group name.')
            ->addOption('pipe', null, InputOption::VALUE_NONE, 'Output the current group name (do nothing else).')
            ->setDescription('Find the project\'s Drush aliases');
        $this->addExample('Change the alias group to @example', '-g example');
    }

    public function isHiddenInList()
    {
        // Hide this command in the list if the project is not Drupal.
        $projectRoot = $this->getProjectRoot();
        if ($projectRoot && !Drupal::isDrupal($projectRoot)) {
            return true;
        }

        return parent::isHiddenInList();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $projectRoot = $this->getProjectRoot();
        $project = $this->getCurrentProject();
        if (!$projectRoot || !$project) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');

        if (!$drush->getDrupalApps($projectRoot)) {
            $this->stdErr->writeln('No Drupal applications found.');

            return 1;
        }

        $current_group = $drush->getAliasGroup($project, $projectRoot);

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);

            return 0;
        }

        $drush->ensureInstalled();

        if ($input->isInteractive()) {
            $this->migrateAliasFiles($drush);
        }

        $aliases = $drush->getAliases($current_group);
        $new_group = ltrim($input->getOption('group'), '@');
        if (empty($aliases) && !$new_group && $project && $current_group === $project->id) {
            $new_group = (new Slugify())->slugify($project->title);
        }

        if (($new_group && $new_group != $current_group) || empty($aliases) || $input->getOption('recreate')) {
            $new_group = $new_group ?: $current_group;

            $this->stdErr->writeln("Creating Drush aliases in the group <info>@$new_group</info>");

            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');

            if ($new_group !== $current_group) {
                $existing = $drush->getAliases($new_group);
                if (!empty($existing)) {
                    $question = "The Drush alias group <info>@$new_group</info> already exists. Overwrite?";
                    if (!$questionHelper->confirm($question, false)) {
                        return 1;
                    }
                }
                $drush->setAliasGroup($new_group, $projectRoot);
            }

            $environments = $this->api()->getEnvironments($project, true, false);
            $drush->createAliases($project, $projectRoot, $environments, $current_group);

            $this->ensureDrushConfig($drush);

            if ($new_group !== $current_group && !empty($aliases)) {
                if ($questionHelper->confirm("Delete old Drush alias group <info>@$current_group</info>?")) {
                    $drush->deleteOldAliases($current_group);
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $drush->clearCache();

            // Read the new aliases.
            $aliases = $drush->getAliases($new_group, true);
        }

        if (!empty($aliases)) {
            $this->stdErr->writeln('Drush aliases for ' . $this->api()->getProjectLabel($project) . ':');
            foreach (array_keys($aliases) as $name) {
                $output->writeln('    @' . $name);
            }
        }

        return 0;
    }

    /**
     * Ensure that the .drush/drush.yml file has the right config.
     * 
     * @param \Platformsh\Cli\Service\Drush $drush
     */
    protected function ensureDrushConfig(Drush $drush)
    {
        if (!is_dir($drush->getSiteAliasDir())) {
            return;
        }

        $drushYml = $drush->getDrushDir() . '/drush.yml';
        $drushConfig = [];
        if (file_exists($drushYml)) {
            $drushConfig = (array) Yaml::parse(file_get_contents($drushYml));
        }
        $aliasPath = $drush->getSiteAliasDir();
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

            /** @var \Platformsh\Cli\Service\Filesystem $fs */
            $fs = $this->getService('fs');
            $fs->writeFile($drushYml, Yaml::dump($drushConfig, 5));
        }
    }

    /**
     * Migrate old alias file(s) from ~/.drush to ~/.drush/site-aliases.
     *
     * @param \Platformsh\Cli\Service\Drush $drush
     */
    protected function migrateAliasFiles(Drush $drush)
    {
        $newDrushDir = $drush->getHomeDir() . '/.drush/site-aliases';

        $oldDrushDir = $drush->getHomeDir() . '/.drush';
        if (!file_exists($oldDrushDir)) {
            return;
        }

        $oldFilenames = glob($oldDrushDir . '/*.aliases.*', GLOB_NOSORT);
        if (empty($oldFilenames)) {
            return;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $oldDrushDirRelative = str_replace($drush->getHomeDir() . '/', '~/', $oldDrushDir);
        $newDrushDirRelative = str_replace($drush->getHomeDir() . '/', '~/', $newDrushDir);
        $confirmText = "Do you want to move your global Drush alias files from <comment>$oldDrushDirRelative</comment> to <comment>$newDrushDirRelative</comment>?";
        if (!$questionHelper->confirm($confirmText)) {
            return;
        }

        if (!file_exists($newDrushDir) && !mkdir($newDrushDir, 0755, true)) {
            $this->stdErr->writeln(sprintf('Failed to create directory: <error>%s</error>', $newDrushDir));
            $this->stdErr->writeln('The alias files have not been moved.');
            return;
        }

        $success = true;
        foreach ($oldFilenames as $oldFilename) {
            $newFilename = $newDrushDir . '/' . basename($oldFilename);
            if (file_exists($newFilename)) {
                $this->stdErr->writeln(
                    "Failed to move file <error>$oldFilename</error>, because the destination file already exists."
                    . "\nThe file will be ignored by Drush, so you can delete it manually."
                );
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
