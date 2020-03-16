<?php

namespace Platformsh\Cli\Command\Commit;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Model\AppConfig;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;

class CommitValidateCommand extends CommandBase
{
    const EXIT_CODE_0=0;
    const EXIT_CODE_1=1;
    
    const MIN_SERVICE_DISK_SIZE=128;//MB

    /** @var \Platformsh\Cli\Service\Git $git */
    private $git;
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('commit:validate')
            ->setAliases(['pre-commit-validate'])
            ->setDescription('This will validate the commit you are about to make (used by the pre-commit hook)')
            ->addArgument('revision', InputArgument::OPTIONAL, 'The revision to ckeck against','HEAD')            
        ;
    }

    protected function getHookConfigValue($config) {
        return (bool)$this->git->getConfig($config);
    }

    protected function checkDiff($strDiff, $regex, &$matches) {
        return preg_match_all($regex, $strDiff, $matches);
    }

    protected function writeErrorMessage($title, $message) {
        $line_of_stars = str_repeat("*",strlen($title)+4);
        $this->stdErr->writeLn($line_of_stars);
        $this->stdErr->writeLn("* <error>$title</error> *");
        $this->stdErr->writeLn($line_of_stars);
        $this->stdErr->writeLn("");
        $this->stdErr->writeLn($message);
    }

    protected function printRelevantChanges(array $changes,$title="") {
        if(!$changes) return;
        if(!$title) $title = "Relevant changes(s)";
        $message = implode(PHP_EOL, $changes);

        $line_of_dashes = str_repeat("-",strlen($title)+4);
        $this->stdErr->writeLn($line_of_dashes);
        $this->stdErr->writeLn("| $title |");
        $this->stdErr->writeLn($line_of_dashes);
        $this->stdErr->writeLn("");
        $this->stdErr->writeLn("<comment>$message</comment>");
        $this->stdErr->writeLn("");
        $this->stdErr->writeLn($line_of_dashes);        
    }

    protected function printPermanentlyDisableNotice($hook_config_name){
        $this->stdErr->writeLn(
            [
                "If you know what you are doing you can permanently disable this check using:",
                "",
                "<comment>  git config hooks.$hook_config_name true</comment>",
                "",
            ]
        );
    }

    protected function confirmOrExit($question="Proceed?") {
        $questionHelper = $this->getService('question_helper');
        return $questionHelper->confirm($question);
    }

    protected function checkContainerNameChange($strDiff) {
        $this->stdErr->write("<info>Checking name change of containers...</info>");
        $hookConfigName="psh_allow_container_rename";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        if(!$hookConfigValue && preg_match('/name\: ?\[.+\}/', $strDiff, $matches) ){
            $this->stdErr->writeLn("<fg=red>[FAIL]</>");
            $this->writeErrorMessage("Attempt to rename a container detected!",
                [
                    "Changing the name of your application after it has been deployed will destroy all storage volumes and result in the loss of all persistent data. This is typically a Very Bad Thing to do. It could be useful under certain circumstances in the early stages of development but you almost certainly don't want to change it on a live project.",
                    "For more information see: https://docs.platform.sh/configuration/app/name.html"
                ]
            );
            $this->printRelevantChanges($matches);
            $this->printPermanentlyDisableNotice($hookConfigName);
            return false;
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }
    
    protected function checkLargeFiles($arrFilesToCheck) {
        
        $this->stdErr->write("<info>Checking for large files...</info> ");
        $hookConfigName="psh_allow_large_files";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        if(!$hookConfigValue){
            
            $max_byte_size = 1;//get all files larger than x MB
            $large_files = [];
            foreach($arrFilesToCheck as $fileName) {
                $size=round(filesize($fileName) / 1024/1024, 2);//always round down
                if($size >= $max_byte_size) {
                    $large_files[] = "$fileName : $size MB";
                }
            }

            if(count($large_files)) {
                $this->stdErr->writeLn("<fg=red>[FAIL]</>");
                $this->writeErrorMessage("Large file commit detected!",
                    [
                        "It looks like you are attempting to commit a file larger than ~1 MB.",
                        "This will slow down builds/clones, and other operations we would rather not slow down.",
                        "",
                        "The maximum total size of your git environment is 4GB.",
                        "Therefore we strongly recommend against commit large files.",
                        "",
                        "Please verify that you want to commit the following:"
                    ]
                );
                $this->printRelevantChanges($large_files, "   Filename : MB   ");
                $this->printPermanentlyDisableNotice($hookConfigName);

                return false;
            }
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }
    
    protected function checkCommonMistakesInBuildHook() {
        $this->stdErr->write("<info>Checking build hook for known faulty commands...</info> ");
        $hookConfigName="psh_disable_build_hook_check";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        if(!$hookConfigValue){
            $arrFaultyCommands=[
                'npm run serve',
                './mercure'
            ];
            $buildHookContents=$this->getBuildHookContents();
            
            foreach($arrFaultyCommands as $faultyCommand) {
                if(stripos($buildHookContents,$faultyCommand)!==FALSE) {
                    $this->stdErr->writeLn("<fg=red>[FAIL]</>");
                    $this->writeErrorMessage("Possible faulty build hook command detected!",
                        [
                            "If a command in the build hook does not finish, the build is unable to finish and it will have to be killed manually.",
                            "",
                            "It looks like your build hook contains a command that is known to cause this issue.",
                            "Please verify that this is indeed correct.",
                            "",
                        ]
                    );
                    $this->printRelevantChanges([$faultyCommand], "   Command   ");
                    $this->printPermanentlyDisableNotice($hookConfigName);

                    return false;
                }
            }
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }

    protected function checkDiskSizeVsPlanSize($project) {
        $this->stdErr->write("<info>Checking plan size hook for known faulty commands...</info> ");
        $hookConfigName="psh_disable_plan_size_check";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        if(!$hookConfigValue){

            $planSize = $this->getSubscriptionPlanSize($project);
            $summedDiskSize = $this->getSummedDiskSize();
            if($planSize < $summedDiskSize) {

                $this->stdErr->writeLn("<fg=red>[FAIL]</>");
                $this->writeErrorMessage("Disk size seems higher than what your plan allows!",
                    [
                        "We did a check on your yaml files and it looks like you are asking for more disk than your plan allows.",
                        "",
                        "Summed disk size $summedDiskSize MB is greater than the current plan limit $planSize MB",
                        "",
                        "Please check the disk: properties in your .yaml files.",
                        "Alternatively, ask the owner of your project to increase the storage of your plan.",
                        "",
                        "For more information see: https://docs.platform.sh/configuration/app/storage.html#disk",
                        "",
                        "The push might fail with the same error should you continue."
                    ]
                );
                
                $this->printPermanentlyDisableNotice($hookConfigName);

                return false;
            
            }
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }

    protected function checkServiceNameChange($strDiff) {
        $this->stdErr->write("<info>Checking name change of service containers...</info>");
        $hookConfigName="psh_allow_service_container_rename";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        
        if(!$hookConfigValue && preg_match('/\[\-(.+):\-]\{\+.+:\+}/', $strDiff, $matches) && !in_array($matches[1],['disk','type']) ){
            unset($matches[1]);
            $this->stdErr->writeLn("<fg=red>[FAIL]</>");
            $this->writeErrorMessage("Attempt to rename a service container detected!",
                [
                    "Changing the name of your application after it has been deployed will destroy all storage volumes and result in the loss of all persistent data. This is typically a Very Bad Thing to do. It could be useful under certain circumstances in the early stages of development but you almost certainly don't want to change it on a live project.",
                    "For more information see: https://docs.platform.sh/configuration/app/name.html",
                    "",
                    "Please check your services.yaml file"
                ]
            );
            $this->printRelevantChanges($matches);
            $this->printPermanentlyDisableNotice($hookConfigName);
            return false;
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }

    protected function checkServiceTypeChanges($strDiff) {
        $this->stdErr->write("<info>Checking service type changes...</info>");
        $hookConfigName="psh_allow_service_type_change";
        $hookConfigValue=(bool)$this->getHookConfigValue($hookConfigName);
        
        if(!$hookConfigValue && preg_match('/type: ?\[\-.+\-]\{\+.+\+}/', $strDiff, $matches) ){
            $this->stdErr->writeLn("<fg=red>[FAIL]</>");
            $this->writeErrorMessage("Change of service type detected!",
                [
                    "Persistent services can not be downgraded (e.g. MySQL v10.3 -> MySQL v10.2). Only non-persistent containers like chrome-headless, redis, memcached can be downgraded.",
                    "",
                    "Please verify your changes before proceeding:",
                    "- Downgrading to an older version will break your service and has the potential to cause dataloss.",
                    "- Upgrading to a newer version should work flawlessly(*). But please do verify that this is working correctly for your application by branching your production/master environment first.",
                    "Downgrading again later is not possible!",
                    "",
                    "* There are limitations regarding which service supports big version jumps while keeping the data (e.g.: Elasticsearch 1.7 -> 6.5). These are upstream limitations not specific to platform.sh. Check the documentation relevant to your service.",
                    "",
                    "For your convenience, here are the links to documentation of the most common services:",
                    "- MySQL https://docs.platform.sh/configuration/services/mysql.html#supported-versions",
                    "- PostgreSQL https://docs.platform.sh/configuration/services/postgresql.html#upgrading",
                    "- MongoDB https://docs.platform.sh/configuration/services/mongodb.html#supported-versions",                    
                ]
            );
            $this->printRelevantChanges($matches);
            $this->printPermanentlyDisableNotice($hookConfigName);
            return false;
        }
        
        $this->stdErr->writeLn("<fg=green>[OK]</>");
        return true;
    }

    protected function getModifiedFiles($revision) {
        return explode("\0", $this->git->diff($revision,["--diff-filter=M","-z","--name-only"]));
    }

    protected function getAddedFiles($revision) {
        return explode("\0", $this->git->diff($revision,["--diff-filter=A","-z","--name-only"]));
    }

    protected function hasFileChanged($arrModifiedFiles, $patternToLookFor='/.yaml$/') {
        foreach($arrModifiedFiles as $fileName) {
            if(preg_match($patternToLookFor, $fileName) == 1) {
                return true;
            }
        }
        return false;
    }

    protected function hasYamlChanges($arrModifiedFiles) {
        return $this->hasFileChanged($arrModifiedFiles,'/\.yaml$/');
    }

    protected function hasServiceYamlChanges($arrModifiedFiles) {
        return $this->hasFileChanged($arrModifiedFiles,'/services\.yaml$/');
    }

    protected function getSummedDiskSize() {
        $sum=0;
        
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $serviceConfig = $localProject->readProjectConfigFile($this->getProjectRoot(), 'services.yaml');
        foreach($serviceConfig as $service) {
            $sum+= isset($service['disk']) ? $service['disk'] : self::MIN_SERVICE_DISK_SIZE;
        }

        $appConfig = $this->getNormalizedAppConfig();
        if(isset($appConfig['disk'])) {
            $sum+=$appConfig['disk'];
        }
        return $sum;
    }

    protected function getNormalizedAppConfig() {
        $local = new LocalApplication($this->getProjectRoot());
        $appConfig = new AppConfig($local->getConfig());        
        return $appConfig->getNormalized();
    }

    protected function getBuildHookContents() {
        $appConfig = $this->getNormalizedAppConfig();
        if(isset($appConfig['hooks']['build'])) {
            return $appConfig['hooks']['build'];
        }
        return "";
    }

    protected function getSubscriptionPlanSize($project) {
        return $this->getSubscriptionInfo($project, 'storage') ?:PHP_INT_MAX;
    }

    protected function getSubscriptionInfo($project, $property) {
        $id = $project->getSubscriptionId();

        $subscription = $this->api()->getClient()
                             ->getSubscription($id);
        if ($subscription) {
            return $this->api()->getNestedProperty($subscription, $property);
        }
    }

    


    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $project = $this->getCurrentProject();
        $projectRoot = $this->getProjectRoot();
        if (!$project || !$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $this->git = $this->getService('git');
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $this->git->setDefaultRepositoryDir($projectRoot);
        $this->git->setSshCommand($ssh->getSshCommand());
         

        $strDiff = $this->git->diff($input->getArgument('revision'),["--diff-filter=M","-z","--word-diff=plain"]);
        $arrModifiedFiles = $this->getModifiedFiles($input->getArgument('revision'));
        $arrAddedFiles = $this->getAddedFiles($input->getArgument('revision'));

        $questionHelper = $this->getService('question_helper');

        //check the global diff
        if(!$this->checkContainerNameChange($strDiff) && !$questionHelper->confirm('Proceed with commit?')){return self::EXIT_CODE_1;}
        if(!$this->checkLargeFiles(array_merge($arrModifiedFiles,$arrAddedFiles)) && !$questionHelper->confirm('Proceed with commit?') ){return self::EXIT_CODE_1;};
        

        //only do these checks when there are .yaml file changes
        if($this->hasYamlChanges($arrModifiedFiles)) {
            if(!$this->checkCommonMistakesInBuildHook() && !$questionHelper->confirm('Proceed with commit?') ){return self::EXIT_CODE_1;}
            if(!$this->checkDiskSizeVsPlanSize($project) && !$questionHelper->confirm('Proceed with commit?') ){return self::EXIT_CODE_1;}

            if($this->hasServiceYamlChanges($arrModifiedFiles)){
                $strServiceDiff = $this->git->diff($input->getArgument('revision'),["--diff-filter=M","-z","--word-diff=plain", $this->config()->get('service.project_config_dir') . '/services.yaml']);

                if(!$this->checkServiceNameChange($strServiceDiff) && !$questionHelper->confirm('Proceed with commit?')){ return self::EXIT_CODE_1;}
                if(!$this->checkServiceTypeChanges($strServiceDiff) && !$questionHelper->confirm('Proceed with commit?')){ return self::EXIT_CODE_1;}                
            }//end hasServiceYamlChanges
        }//end hasYamlChanges
        
        //if we get down here, all good.
        $this->stdErr->writeln("<info>All good</info>");
        return self::EXIT_CODE_0;
    }
}
