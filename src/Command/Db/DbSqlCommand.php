<?php
namespace Platformsh\Cli\Command\Db;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DbSqlCommand extends CommandBase
{
    protected static $defaultName = 'db:sql';

    private $relationships;
    private $selector;
    private $shell;
    private $ssh;

    public function __construct(
        Relationships $relationships,
        Selector $selector,
        Shell $shell,
        Ssh $ssh
    ) {
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->shell = $shell;
        $this->ssh = $ssh;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setAliases(['sql'])
            ->setDescription('Run SQL on the remote database')
            ->addArgument('query', InputArgument::OPTIONAL, 'An SQL statement to execute')
            ->addOption('raw', null, InputOption::VALUE_NONE, 'Produce raw, non-tabular output');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->relationships->configureInput($definition);
        $this->ssh->configureInput($definition);

        $this->addExample('Open an SQL console on the remote database');
        $this->addExample('View tables on the remote database', "'SHOW TABLES'");
        $this->addExample('Import a dump file into the remote database', '< dump.sql');
        $this->setHiddenAliases(['environment:sql']);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input);
        if (!$input->getArgument('query') && $this->runningViaMulti) {
            throw new InvalidArgumentException('The query argument is required when running via "multi"');
        }

        $sshUrl = $selection->getEnvironment()
            ->getSshUrl($selection->getAppName());

        $database = $this->relationships->chooseDatabase($sshUrl, $input, $output);
        if (empty($database)) {
            return 1;
        }

        $query = $input->getArgument('query');

        switch ($database['scheme']) {
            case 'pgsql':
                $sqlCommand = 'psql ' . $this->relationships->getDbCommandArgs('psql', $database);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' -t';
                    }
                    $sqlCommand .= ' -c ' . OsUtil::escapePosixShellArg($query);
                }
                break;

            default:
                $sqlCommand = 'mysql --no-auto-rehash ' . $this->relationships->getDbCommandArgs('mysql', $database);
                if ($query) {
                    if ($input->getOption('raw')) {
                        $sqlCommand .= ' --batch --raw';
                    }
                    $sqlCommand .= ' --execute ' . OsUtil::escapePosixShellArg($query);
                }
                break;
        }

        $sshOptions = [];
        $sshCommand = $this->ssh->getSshCommand($sshOptions);
        if ($this->isTerminal(STDIN)) {
            $sshCommand .= ' -t';
        }
        $sshCommand .= ' ' . escapeshellarg($sshUrl)
            . ' ' . escapeshellarg($sqlCommand);

        return $this->shell->executeSimple($sshCommand);
    }
}
