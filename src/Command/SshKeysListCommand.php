<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SshKeysListCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
            ->setName('ssh-keys:list')
                ->setDescription('Get a list of all added SSH keys.');
        ;
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->getAccountClient();
        $data = $client->getSshKeys();
        $currentKeyFingerprint= $this->getKeyFingerprint($this->getCurrentKey());
        $key_rows = array();
        /* no keys on api*/
        if (count($data["keys"])==0){
            $table_header = array('path', 'fingerprint');
            $output->writeln("There are no public SSH keys listed on Platform.sh for your account.\nYou should upload one using <info>platform ssh-keys:add</info>");
            $output->writeln("\nHere is a list of the keys we could find\n");
            $local_keys=$this->listPublicKeys();
            foreach ($local_keys as $key) {
                $key_row = array();
                $key_row[] = $key;
                $key_row[] = $this->getKeyFingerprint($key) ;
                $key_rows[] = $key_row;
            }
            /* no keys on api and no local keys*/
            if (empty($local_keys)){
                $output->writeln("\nAnd we have not found any public keys in your ".getenv('HOME')."/.ssh directory");
                $output->writeln("\nYou should create a keypair with <info>ssh-keygen</info>");
            }
            /* no keys on api but found local keys*/
        }else{
            $table_header = array('ID', 'Key', 'Current?');
            $output->writeln("\nYour SSH keys on Platform.sh are: ");
            foreach ($data['keys'] as $key) {
                $key_row = array();
                $key_row[] = $key['id'];
                $key_row[] = $key['title'] . ' (' . $key['fingerprint'] . ')';
                if ($currentKeyFingerprint == $key['fingerprint']){$key_row[] = " * ";}
                $key_rows[] = $key_row;
            }
        }

        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders($table_header)
                ->setRows($key_rows);
        $table->render($output);

        $message .= "\nAdd (upload) a new SSH key by running <info>platform ssh-keys:add [path]</info>.\n";
        $message .= "Delete from your Platform.sh account an SSH key by running <info>platform ssh-keys:delete [id]</info>. \n";
        $message .= "\nIf you want to chose which ssh key will be used, you can use the following commands to set it:\n\n";
        $message .= '<info>';
        $message .='export GIT_SSH="'.CLI_ROOT.'/platform-git"';
        $message .="\n#and for example (specifiying the key you want to use):\n";
        $message .='export PLATFORM_IDENTITY_FILE="'.$this->getCurrentKey().'"';
        $message .="\n</info>You can put these commands in your .basrc file to make the change permanent";
        $output->writeln($message);
    }
    
    protected function getKeyFingerprint($key){
        if(!file_exists($key)){ return ("Error: key ($key) not found"); };
        $key_finger_print= shell_exec("ssh-keygen -lf " . $key);
        list(, $key_finger_print) = explode(" ", $key_finger_print);
        return str_replace(":","",$key_finger_print);
    }
    
    protected function listPublicKeys(){
        $home = getenv('HOME');
        return explode("\n",trim(shell_exec("ls $home/.ssh/*.pub ")));
    }
    
    protected function getCurrentKey(){
        $home = getenv('HOME');
        $key = getenv("PLATFORM_IDENTITY_FILE");
        if(empty($key)) {$key = $home.'/.ssh/id_rsa.pub';}
        return $key;
    }

}
