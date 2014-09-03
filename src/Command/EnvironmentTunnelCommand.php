<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputArgument;
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
             ->addArgument(
                 'operation',
                 InputArgument::OPTIONAL,
                 '\'close\' will close an existing tunnel, \'status\' will output tunnel status'
             )
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

    protected function kill_existing($pid_file){
          $output=[];
          $outputs="";
          if (file_exists($pid_file)){
            $pids = file($pid_file, FILE_IGNORE_NEW_LINES);
            if (!empty($pids)){
              foreach ($pids as $pid){
                exec("kill $pid", $output);
                $outputs.=join("\n",$output);
              }
            exec(">| $pid_file");
            }
          }
          return $outputs;
    }

    protected function show_status($pid_file){
          $output=[];
          $outputs="";
          if (file_exists($pid_file)){
            $pids = file($pid_file, FILE_IGNORE_NEW_LINES);
            if (!empty($pids)){
              $outputs="Found tunnel pids".join(", ",$pids)."\n";;
              foreach ($pids as $pid){
                exec("ps $pid", $output);
                $outputs.=join("\n",$output);
              }
            }
          }
          return $outputs;
    }
    
    protected function close_tunnel(){
            $tmp_dir="/tmp";
            $environmentId = $this->environment['id'];
            $projectId = $this->project['id'];
            $webPidFile ="$tmp_dir/platform-local-web-server-$environmentId.pid";
            $tunnelPidFile ="$tmp_dir/platform-local-ssh-tunnel-$environmentId.pid";
            $killOutPut = $this->kill_existing($webPidFile);
            $killOutPut .= $this->kill_existing($tunnelPidFile);
            $message = '<info>';
            if(!empty($killOutPut)){$message .= "Killing Tunnel $killOutPut\n";}
            $message .= "</info>";
            return $message;
    }

    protected function status_tunnel(){
            $tmp_dir="/tmp";
            $environmentId = $this->environment['id'];
            $projectId = $this->project['id'];
            $webPidFile ="$tmp_dir/platform-local-web-server-$environmentId.pid";
            $tunnelOutputFile ="$tmp_dir/platform-local-ssh-tunnel-$environmentId.log";
            $tunnelPidFile ="$tmp_dir/platform-local-ssh-tunnel-$environmentId.pid";
            
            $statusOutPut = $this->show_status($webPidFile);
            $statusOutPut .= $this->show_status($tunnelPidFile);
            $message = '<info>';
            $message = "Tunnel Status:\n";
            if(!empty($statusOutPut)){$message .= " $statusOutPut\n";}
            $message .= "</info>";
            return $message;
    }
            
    protected function open_tunnel(){
            $environmentId = $this->environment['id'];
            $projectId = $this->project['id'];
            list($protocol,$sshUrl) = split('://',$this->environment["_links"]["ssh"]["href"]); // We can't pass the ssh:// protocol indentifier to the ssh command
            $projectWWWRoot = $this->getProjectRoot()."/www";
            $localhost = "local.platform.sh";
            $mysql_local_port= 33306;
            $http_local_port=  8000;
            $tmp_dir="/tmp";
            $tunnelCommand = "ssh -N -L$mysql_local_port:database.internal:3306 $sshUrl";
            $serveCommand = "php -t $projectWWWRoot -S $localhost:$http_local_port -r'if (preg_match(\"/\.(engine|inc|info|install|make|module|profile|test|po|sh|.*sql|theme|tpl(\.php)?|xtmpl)/\",\$_SERVER[\"REQUEST_URI\"])) { print \"Error\\n\"; } else if (preg_match(\"/(^|\/)\./\",\$_SERVER[\"REQUEST_URI\"])) { return false; } else if (file_exists(\$_SERVER[\"DOCUMENT_ROOT\"].\$_SERVER[\"SCRIPT_NAME\"])) { return false; } else {\$_GET[\"q\"]=\$_SERVER[\"REQUEST_URI\"]; include(\"$projectWWWRoot/index.php\"); }'";
            $webOutputFile ="$tmp_dir/platform-local-web-server-$environmentId.log";
            $webPidFile ="$tmp_dir/platform-local-web-server-$environmentId.pid";
            $tunnelOutputFile ="$tmp_dir/platform-local-ssh-tunnel-$environmentId.log";
            $tunnelPidFile ="$tmp_dir/platform-local-ssh-tunnel-$environmentId.pid";

            // we need to check the pid file here (and allow for a stop command...)

            $command = sprintf("%s >> %s  2>&1 & echo $! >> %s", $serveCommand, $webOutputFile, $webPidFile);
            exec($command);
            $command = sprintf("%s >> %s 2>&1 & echo $! >> %s", $tunnelCommand, $tunnelOutputFile, $tunnelPidFile);
            shell_exec($command);

            $message = '<info>';
            if(!empty($killOutPut)){$message .= "Killing existing tunnel $killOutPut\n";}
            $message .= "\nA host has been configured as $localhost\n";
            $message .= "\nA tunnel to environment $environmentId has been created. \n";
            $message .= "\nSet your settings to connect $localhost:$mysql_local_port for mysql (do not use localhost !)\n";
            $message .= "\nYou can see the site on http://$localhost:$http_local_port";
            $message .= "</info>";
            return $message;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
          return;
        }
        
        $operation = $input->getArgument('operation');
        
        switch ($operation) {
            case "close":
                $message = $this->close_tunnel();
                break;
            case "status":
                $message = $this->status_tunnel();
                break;
            case "open":
            default:
                $message = $this->open_tunnel();
                break;
        }
        
        $output->writeln($message);
    }
}

