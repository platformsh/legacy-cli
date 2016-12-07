<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DbSqlCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('db:sql')
            ->setAliases(['sql'])
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute');
        RelationshipsUtil::configureInput($this->getDefinition());
        $this->addProjectOption()->addEnvironmentOption()->addAppOption();
        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
        $this->addExample('Import a dump file into the remote database', '< dump.sql');
        $this->setHiddenAliases(['environment:sql']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        if (!$input->getArgument('query') && $this->runningViaMulti) {
            throw new \InvalidArgumentException('The query argument is required when running via "multi"');
        }

        $sshOptions = '';

        $sshUrl = $this->getSelectedEnvironment()
                       ->getSshUrl($this->selectApp($input));

        $util = new RelationshipsUtil($this->stdErr);
        $database = $util->chooseDatabase($sshUrl, $input);
        if (empty($database)) {
            return 1;
        }

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = "psql postgresql://{$database['username']}:{$database['password']}@{$database['host']}/{$database['path']}";
                $queryOption = ' -c ';
                break;

            default:
                $sqlCommand = "mysql --no-auto-rehash --database={$database['path']}"
                    . " --host={$database['host']} --port={$database['port']}"
                    . " --user={$database['username']} --password={$database['password']}";
                $queryOption = ' --execute ';
                break;
        }

        $query = $input->getArgument('query');
        if ($query) {
            $sqlCommand .= $queryOption . escapeshellarg($query) . ' 2>&1';
        }

        // Switch on pseudo-tty allocation when there is a local tty.
        if ($this->isTerminal($output)) {
            $sshOptions .= ' -t';
        }

        if ($output->getVerbosity() >= OutputInterface::VERBOSITY_DEBUG) {
            $sshOptions .= ' -vv';
        }
        elseif ($output->getVerbosity() >= OutputInterface::VERBOSITY_VERY_VERBOSE) {
            $sshOptions .= ' -v';
        }
        elseif ($output->getVerbosity() <= OutputInterface::VERBOSITY_VERBOSE) {
            $sshOptions .= ' -q';
        }

        $command = 'ssh' . $sshOptions . ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($sqlCommand);

        return $this->getHelper('shell')->executeSimple($command);
    }
}
