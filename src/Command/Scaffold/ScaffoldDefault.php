<?php


namespace Platformsh\Cli\Command\Scaffold;


use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Filesystem;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ScaffoldDefault extends CommandBase
{
    /**
     * @var Filesystem
     */
    protected $fileHandler;

    /**
     * Scaffold constructor.
     */
    public function __construct($name = null)
    {
        $this->fileHandler = new Filesystem();

        parent::__construct($name = null);
    }

    protected function configure()
    {
        $this
            ->setName('scaffold:default')
            ->setAliases(['scaffold'])
            ->setDescription('Set up basic required files within the project');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $cwd = getcwd();
        $this->stdErr->writeln('Setting the project root to ' . $cwd);

        $this->setProjectRoot($cwd);

        $this->copyScaffold('default');
    }

    /**
     * Copy a directory from /resources/scaffold.
     *
     * Only works for the root directory currently.
     *
     * @param $template
     */
    protected function copyScaffold($template) {

        $target = $this->getProjectRoot();

        $this->stdErr->writeln('Building scaffold in ' . $target);

        $basePath = CLI_ROOT . '/resources/scaffold/' . $template;
        $this->fileHandler->getFilesystem()->mirror($basePath, $target);
    }
}
