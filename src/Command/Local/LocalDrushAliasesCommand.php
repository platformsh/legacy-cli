<?php
namespace Platformsh\Cli\Command\Local;

use Cocur\Slugify\Slugify;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\BuildFlavor\Drupal;
use Platformsh\Cli\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

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
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');

        if (!$drush->getDrupalApps($projectRoot)) {
            $this->stdErr->writeln('No Drupal applications found.');

            return 1;
        }

        $project = $this->getCurrentProject();

        $current_group = $drush->getAliasGroup($project, $projectRoot);

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);

            return 0;
        }

        $drush->ensureInstalled();

        $this->ensureDrushDir();

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
     * Migrate old alias file(s) from ~/.drush to ~/.drush/site-aliases.
     */
    protected function ensureDrushDir()
    {
        $newDrushDir = Filesystem::getHomeDirectory() . '/.drush/site-aliases';

        $oldDrushDir = dirname($newDrushDir);
        if (!file_exists($oldDrushDir)) {
            return;
        }

        $oldFilenames = glob($oldDrushDir . '/*.alias*.drushrc.*php', GLOB_NOSORT);
        if (empty($oldFilenames)) {
            return;
        }

        if (!file_exists($newDrushDir) && !mkdir($newDrushDir)) {
            return;
        }

        foreach ($oldFilenames as $oldFilename) {
            $newFilename = $newDrushDir . '/' . basename($oldFilename);
            if (file_exists($newFilename)) {
                $this->stdErr->writeln(sprintf('Cannot move file <comment>%s</comment> to %s (destination file already exists).', $oldFilename, $newFilename));
                return;
            }
            if (!rename($oldFilename, $newFilename)) {
                $this->stdErr->writeln(sprintf('Failed to move file <comment>%s</comment> to %s', $oldFilename, $newFilename));
                return;
            }
        }

        $this->stdErr->writeln(sprintf('Successfully moved all site alias files from %s to %s', $oldDrushDir, $newDrushDir));
    }
}
