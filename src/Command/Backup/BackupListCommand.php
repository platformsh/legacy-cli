<?php
namespace Platformsh\Cli\Command\Backup;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BackupListCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('backup:list')
            ->setAliases(['backups'])
            ->setDescription('List available backups of an environment')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Limit the number of backups to list', 10)
            ->addOption('start', null, InputOption::VALUE_REQUIRED, 'Only backups created before this date will be listed');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption()
             ->addEnvironmentOption();
        $this->setHiddenAliases(['snapshots', 'snapshot:list']);
        $this->addExample('List the most recent backups')
             ->addExample('List backups made before last week', "--start '1 week ago'");
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $environment = $this->getSelectedEnvironment();

        $startsAt = null;
        if ($input->getOption('start') && !($startsAt = strtotime($input->getOption('start')))) {
            $this->stdErr->writeln('Invalid date: <error>' . $input->getOption('start') . '</error>');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        /** @var \Platformsh\Cli\Service\ActivityLoader $loader */
        $loader = $this->getService('activity_loader');
        $activities = $loader->load($environment, $input->getOption('limit'), 'environment.backup', $startsAt);
        if (!$activities) {
            $this->stdErr->writeln('No backups found');
            return 1;
        }

        $headers = ['Created', 'name' => 'Backup name', 'Progress', 'State', 'Result'];
        $rows = [];
        foreach ($activities as $activity) {
            $backup_name = !empty($activity->payload['backup_name']) ? $activity->payload['backup_name'] : 'N/A';
            $rows[] = [
                $formatter->format($activity->created_at, 'created_at'),
                'name' => new AdaptiveTableCell($backup_name, ['wrap' => false]),
                $activity->getCompletionPercent() . '%',
                ActivityMonitor::formatState($activity->state),
                ActivityMonitor::formatResult($activity->result, !$table->formatIsMachineReadable()),
            ];
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(sprintf(
                'Backups on the project %s, environment %s:',
                $this->api()->getProjectLabel($this->getSelectedProject()),
                $this->api()->getEnvironmentLabel($environment)
            ));
        }

        $table->render($rows, $headers);

        $max = $input->getOption('limit') ? (int) $input->getOption('limit') : 10;
        $maybeMoreAvailable = count($activities) === $max;
        if (!$table->formatIsMachineReadable() && $maybeMoreAvailable) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'More backups may be available.'
                . ' To display older backups, increase <info>--limit</info> above %d, or set <info>--start</info> to a date in the past.'
                . ' For more information, run: <info>%s backups -h</info>',
                $max,
                $this->config()->get('application.executable')
            ));
        }

        return 0;
    }
}
