<?php
namespace Platformsh\Cli\Command\Mount;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MountPushCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('mount:push')
	        ->setAliases(['mpush'])
            ->setDescription('Upload contents to mounts')
	        ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
	    $this->addProjectOption();
	    $this->addEnvironmentOption();
	    $this->addAppOption();
    }

	/**
	 * @param InputInterface  $input
	 * @param OutputInterface $output
	 *
	 * @return int|null|void
	 * @throws \RuntimeException
	 * @throws \Platformsh\Client\Exception\OperationUnavailableException
	 * @throws \Platformsh\Client\Exception\EnvironmentStateException
	 */
	protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

	    /** @var \Platformsh\Cli\Service\RemoteEnvVars $envVarService */
	    $envVarService = $this->getService('remote_env_vars');

	    $sshUrl = $this->getSelectedEnvironment()
		    ->getSshUrl($this->selectApp($input));

	    $result = $envVarService->getEnvVar('APPLICATION', $sshUrl, $input->getOption('refresh'));
	    $appConfig = json_decode(base64_decode($result), true);

	    $mounts = $appConfig['mounts'];
	    if (empty($mounts)) {
	    	$output->writeln(sprintf('The app "%s" doesn\'t define any mounts.', $appConfig['name']));
	    	return;
	    }

	    $app = LocalApplication::getApplication($input->getOption('app'), $this->getProjectRoot(), $this->config());
	    $appRoot = $app->getRoot();

	    $path = $this->selectMount($mounts);
	    $this->runSync($path, $sshUrl, $appRoot);
    }

	/**
	 * Find the mount the user wants to use.
	 *
	 * @param array $mounts
	 *
	 * @return null|string The path of the mount or null.
	 * @throws \RuntimeException
	 */
	private function selectMount(array $mounts)
	{
		$options = array();
		foreach ($mounts as $path => $id) {
			$options[$path] = sprintf('"%s": "%s"', $path, $id);
		}

		/** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
		$questionHelper = $this->getService('question_helper');
		$path = $questionHelper->choose($options, 'Enter a number to choose a mount to upload to:');

		return $path;
	}

	/**
	 * Push the local contents to the chosen mount
	 *
	 * @param $path
	 * @param $sshUrl
	 * @param $appRoot
	 *
	 * @return bool
	 */
	private function runSync($path, $sshUrl, $appRoot)
	{
		$command = sprintf('rsync -az %s%s/ %s:/app%s ', $appRoot, $path, $sshUrl, $path);
		set_time_limit(0);

		// Execute the command.
		$start = microtime(true);
		/** @var \Platformsh\Cli\Service\Shell $shell */
		$shell = $this->getService('shell');
		$exitCode = $shell->executeSimple($command);

		if ($exitCode === 0) {
			$this->stdErr->writeln('The upload completed successfully.', OutputInterface::OUTPUT_NORMAL);
			$this->stdErr->writeln(sprintf('Time: %ss', number_format(microtime(true) - $start, 2)), OutputInterface::VERBOSITY_VERBOSE);
			return true;
		}
		$this->stdErr->writeln('The upload failed. Try running the command with -vvv to gain more insight.', OutputInterface::OUTPUT_NORMAL);
		return false;
	}
}
