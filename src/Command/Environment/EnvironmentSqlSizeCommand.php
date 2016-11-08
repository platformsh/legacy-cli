<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Helper\ShellHelper;
use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentSqlSizeCommand extends CommandBase {

    protected function configure() {
        $this
            ->setName('environment:sql-size')
            ->setAliases(['sqls'])
            ->setDescription('Database size check')
            ->addOption('details', 'd', InputOption::VALUE_NONE, 'Show detailed (per table) report.')
            ->addOption('xml', 'x', InputOption::VALUE_NONE, 'Output results in XML (available for mysql databases only).');
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $this->validateInput($input);
        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));
        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        $command = ['ssh'];
        // Switch on pseudo-tty allocation when there is a local tty.
        if ($this->isTerminal($output)) {
            $command[] = '-t';
        }
        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $command[] = '-vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $command[] = '-v';
        }
        elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
            $command[] = '-q';
        }
        $command[] = $sshUrl;

        switch ($database['scheme']) {
            case 'pgsql':
                if ($input->getOption('details')) {
                    $query = $this->psqlQueryDetails();
                }
                else {
                    $query = $this->psqlQueryOverview();
                }
                $command[] = "psql --echo-hidden postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']} -c \"$query\" 2>&1";
                break;
            default:
                if ($input->getOption('details')) {
                    $query = $this->mysqlQueryDetails();
                }
                else {
                    $query = $this->mysqlQueryOverview();
                }
                $params = '--no-auto-rehash';
                if ($input->getOption('xml')) {
                    $params .= " --xml";
                }
                else {
                    $params .= " --table";
                }
                $command[] = "mysql $params --database={$database['path']} --host={$database['host']} --port={$database['port']} --user={$database['username']} --password={$database['password']} --execute \"$query\" 2>&1";
                break;
        }

        /** @var ShellHelper $shellHelper */
        $shellHelper = $this->getHelper('shell');
        $bufferedOutput = new BufferedOutput();
        $shellHelper->setOutput($bufferedOutput);
        $result = $shellHelper->execute($command);
        // @todo: Parse the result, extract meaningful data.
        $output->writeln($result);
    }

    private function psqlQueryOverview() {
        return "
          SELECT
            pg_size_pretty(sum(pg_relation_size(pg_class.oid))::bigint) AS size,
            nspname,
            CASE pg_class.relkind
              WHEN 'r' THEN 'table'
              WHEN 'i' THEN 'index'
              WHEN 'S' THEN 'sequence'
              WHEN 'v' THEN 'view'
              WHEN 't' THEN 'toast'
              ELSE pg_class.relkind::text
            END
          FROM pg_class
          LEFT OUTER JOIN pg_namespace ON (pg_namespace.oid = pg_class.relnamespace)
          GROUP BY pg_class.relkind, nspname
          ORDER BY sum(pg_relation_size(pg_class.oid)) DESC;
        ";
    }

    private function psqlQueryDetails() {
        return "
          SELECT
            *,
            pg_size_pretty(total_bytes) AS total,
            pg_size_pretty(index_bytes) AS INDEX,
            pg_size_pretty(toast_bytes) AS toast,
            pg_size_pretty(table_bytes) AS TABLE
          FROM (
            SELECT
              *,
              total_bytes-index_bytes-COALESCE(toast_bytes,0) AS table_bytes
            FROM (
              SELECT
                c.oid,nspname AS table_schema,
                relname AS TABLE_NAME,
                c.reltuples AS row_estimate,
                pg_total_relation_size(c.oid) AS total_bytes,
                pg_indexes_size(c.oid) AS index_bytes,
                pg_total_relation_size(reltoastrelid) AS toast_bytes
              FROM pg_class c
              LEFT JOIN pg_namespace n ON n.oid = c.relnamespace
              WHERE relkind = 'r'
            ) a
          ) a;";
    }

    private function mysqlQueryOverview() {
        return "
          SELECT
            SUM(index_length)/1048576                       AS index_length,
            SUM(data_length)/1048576                        AS data_length,
            SUM(data_free)/1048576                          AS data_free,
            SUM(data_length+index_length+data_free)/1048576 AS disk_used,
            (COUNT(*) * 300 * 1024)/1048576                 AS overhead,
            (SUM(data_length+index_length+data_free) + (COUNT(*) * 300 * 1024))/1048576+150 as estimated_actual_disk_usage
          FROM information_schema.tables
          WHERE table_schema = 'main';";
    }

    private function mysqlQueryDetails() {
        return "
          SELECT 
            table_name,
            index_length/1048576                            AS index_length, 
            data_length/1048576                             AS data_length,
            data_free/1048576                               AS data_free,
            (data_length+index_length)/1048576              AS data_used,
            (data_length+index_length+data_free)/1048576    AS disk_used
          FROM information_schema.tables
          WHERE table_schema = 'main'
          ORDER BY (data_length+index_length+data_free) ASC;";
    }
}
