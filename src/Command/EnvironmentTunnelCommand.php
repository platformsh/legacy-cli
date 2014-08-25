<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentTunnelCommand extends EnvironmentCommand
{

    protected function configure()
    {
        $this
            ->setName('environment:tunnel')
            ->setDescription('Tunnel an environment.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            )
            ->addOption(
                'environment',
                null,
                InputOption::VALUE_OPTIONAL,
                'The environment id'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
          return;
        }        
        $environmentId = $this->environment['id'];
        $sshUrl= $this->environment["_links"]["ssh"]["href"];
        $projectRoot = $this->getProjectRoot();
        $mysql_local_port= 3306;
        $http_local_port=  8000;
        $tmp_dir="/tmp";
        
        $tunnelCommand = "ssh -L$mysql_local_port:database.internal:3306 $sshUrl\n";
        $serveCommand = "php -t $projectRoot/www -S localhost:$http_local_port -r'if (preg_match(\"/\.(engine|inc|info|install|make|module|profile|test|po|sh|.*sql|theme|tpl(\.php)?|xtmpl)/\",\$_SERVER[\"REQUEST_URI\"])) { print \"Error\\n\"; } else if (preg_match(\"/(^|\/)\./\",\$_SERVER[\"REQUEST_URI\"])) { return false; } else if (file_exists(\$_SERVER[\"DOCUMENT_ROOT\"].\$_SERVER[\"SCRIPT_NAME\"])) { return false; } else {\$_GET[\"q\"]=\$_SERVER[\"REQUEST_URI\"]; include(\"index.php\"); }'";

        $webOutputFile ="$tmp_dir/platform-local-web-server-$environmentId.log";
        $webPidFile ="$tmp_dir/platform-local-web-server-$environmentId.pid";
        $tunnelOutputFile ="$tmp_dir/platform-local-web-server-$environmentId.log";
        $tunnelPidFile ="$tmp_dir/platform-local-web-server-$environmentId.pid";

        // we need to check the pid file here (and allow for a stop command...)
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $tunnelCommand, $tunnelOutputFile, $tunnelPidFile));
        exec(sprintf("%s > %s 2>&1 & echo $! >> %s", $serveCommand, $webOutputFile, $webPidFile));

        $message = '<info>';
        $message .= "\nA tunnel to environment $environmentId has been created. \n";
        $message .="\nSet your settings to connect to localhost:$mysql_local_port for mysql";
        $message .="\nYou can see the site on http://locahost:$http_local_port";
        $message .= "</info>";
        $output->writeln($message);
    }
}

