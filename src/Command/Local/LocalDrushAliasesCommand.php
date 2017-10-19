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

        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $projectConfig = $localProject->getProjectConfig($projectRoot);
        $current_group = isset($projectConfig['alias-group']) ? $projectConfig['alias-group'] : $projectConfig['id'];

        if ($input->getOption('pipe')) {
            $output->writeln($current_group);

            return 0;
        }

        $project = $this->getCurrentProject();

        $new_group = ltrim($input->getOption('group'), '@');

        /** @var \Platformsh\Cli\Service\Drush $drush */
        $drush = $this->getService('drush');
        $drush->ensureInstalled();

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $drushDir = Filesystem::getHomeDirectory() . '/.drush/site-aliases';

        // Migrate old alias file(s) to the new location (from .drush to
        // .drush/site-aliases).
        $oldDrushDir = dirname($drushDir);
        if (file_exists($oldDrushDir)) {
            $oldFilenames = glob($oldDrushDir . '/*.alias*.drushrc.*php', GLOB_NOSORT);
            if (!empty($oldFilenames)) {
                $confirmText = "Global Drush aliases are best stored in ~/.drush/site-aliases"
                    . "\nThere are " . count($oldFilenames) . " alias file(s) still stored in ~/.drush"
                    . "\nDo you want to move all your alias files from ~/.drush to ~/.drush/site-aliases?";
                if ($questionHelper->confirm($confirmText)) {
                    if (!file_exists($drushDir) && !mkdir($drushDir)) {
                        $this->stdErr->writeln(sprintf('Failed to create directory: <error>%s</error>', $drushDir));

                        return 1;
                    }
                    foreach ($oldFilenames as $oldFilename) {
                        $newFilename = $drushDir . '/' . basename($oldFilename);
                        if (file_exists($newFilename)) {
                            $this->stdErr->writeln(sprintf('Failed to move file %s to %s (destination file already exists).', $oldFilename, $newFilename));
                            $this->stdErr->writeln('Resolve the conflicting file manually, and try again.');

                            return 1;
                        }
                        if (!rename($oldFilename, $newFilename)) {
                            $this->stdErr->writeln(sprintf('Failed to move file %s to %s', $oldFilename, $newFilename));

                            return 1;
                        }
                    }
                    $this->stdErr->writeln(sprintf('Successfully moved alias files to: <info>%s</info>', $drushDir));
                }
                else {
                    $drushDir = $oldDrushDir;
                }
            }
        }

        $aliases = $drush->getAliases($current_group);
        if (!$aliases && !$new_group && $project && $current_group === $project->id) {
            $new_group = (new Slugify())->slugify($project->title);
        }

        if (($new_group && $new_group != $current_group) || !$aliases || $input->getOption('recreate')) {
            $new_group = $new_group ?: $current_group;

            $this->stdErr->writeln("Creating Drush aliases in the group <info>@$new_group</info>");

            if ($new_group != $current_group) {
                $existing = $drush->getAliases($new_group);
                if ($existing && $new_group != $current_group) {
                    $question = "The Drush alias group <info>@$new_group</info> already exists. Overwrite?";
                    if (!$questionHelper->confirm($question, false)) {
                        return 1;
                    }
                }
                $localProject->writeCurrentProjectConfig(['alias-group' => $new_group], $projectRoot, true);
            }

            $environments = $this->api()->getEnvironments($project, true, false);
            $drush->createAliases($project, $projectRoot, $environments, $current_group);

            if ($new_group != $current_group) {
                $oldFile = $drushDir . '/' . $current_group . '.aliases.drushrc.php';
                if (file_exists($oldFile)) {
                    if ($questionHelper->confirm("Delete old Drush alias group <info>@$current_group</info>?")) {
                        unlink($oldFile);
                    }
                }
            }

            // Clear the Drush cache now that the aliases have been updated.
            $drush->clearCache();

            // Read the new aliases.
            $aliases = $drush->getAliases($new_group);
        }

        if ($aliases) {
            $this->stdErr->writeln('Drush aliases for ' . $this->api()->getProjectLabel($project) . ':');
            foreach (explode("\n", $aliases) as $alias) {
                $output->writeln('    @' . $alias);
            }
        }

        return 0;
    }
}
