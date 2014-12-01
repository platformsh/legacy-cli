<?php
namespace CommerceGuys\Platform\Cli\Local;

use CommerceGuys\Platform\Cli\Local\Toolstack\ToolstackInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Parser;

class LocalBuild {

    protected $settings;

    /**
     * @return ToolstackInterface[]
     */
    public function getToolstacks()
    {
        return array(
            new Toolstack\Drupal(),
            new Toolstack\Symfony(),
        );
    }

    /**
     * @param array $settings
     */
    public function __construct(array $settings = array())
    {
        $this->settings = $settings;
    }

    /**
     * @param string $projectRoot
     * @param OutputInterface $output
     * @return bool
     */
    public function buildProject($projectRoot, OutputInterface $output)
    {
        $repositoryRoot = $this->getRepositoryRoot($projectRoot);
        $success = true;
        foreach ($this->getApplications($repositoryRoot) as $appRoot) {
            $success = $this->buildApp($appRoot, $projectRoot, $output) && $success;
        }
        return $success;
    }

    /**
     * Get a list of applications in the repository.
     *
     * @param string $repositoryRoot   The absolute path to the repository.
     *
     * @return string[]    A list of directories containing applications.
     */
    public function getApplications($repositoryRoot)
    {
        // @todo: Determine multiple project roots, perhaps using Finder again
        return array($repositoryRoot);
    }

    /**
     * Get the application's configuration, parsed from its YAML definition.
     *
     * @param string $appRoot   The absolute path to the application.
     *
     * @return array
     */
    public function getAppConfig($appRoot)
    {
        if (file_exists($appRoot . '/.platform.app.yaml')) {
            $parser = new Parser();
            return (array) $parser->parse(file_get_contents($appRoot . '/.platform.app.yaml'));
        }
        return array();
    }

    /**
     * Get the toolstack for a particular application.
     *
     * @param string $appRoot   The absolute path to the application.
     * @param mixed $appConfig  The application's configuration.
     *
     * @throws \Exception   If a specified toolstack is not found.
     *
     * @return ToolstackInterface|false
     */
    public function getToolstack($appRoot, array $appConfig = array())
    {
        $toolstackChoice = false;
        if (isset($appConfig['toolstack'])) {
            $toolstackChoice = $appConfig['toolstack'];
        }
        foreach (self::getToolstacks() as $toolstack) {
            if ((!$toolstackChoice && $toolstack->detect($appRoot))
                || $toolstackChoice == $toolstack->getKey()) {
                return $toolstack;
            }
        }
        if ($toolstackChoice) {
            throw new \Exception("Toolstack not found: $toolstackChoice");
        }

        return false;
    }

    /**
     * @var string $projectRoot
     * @return string
     */
    protected function getRepositoryRoot($projectRoot)
    {
        return $projectRoot . '/repository';
    }

    /**
     * @param string $appRoot
     * @param string $projectRoot
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function buildApp($appRoot, $projectRoot, OutputInterface $output)
    {
        $repositoryRoot = $this->getRepositoryRoot($projectRoot);

        $appConfig = $this->getAppConfig($appRoot);
        $appName = false;
        if (isset($appConfig['name'])) {
            $appName = $appConfig['name'];
        }
        elseif ($appRoot != $repositoryRoot) {
            $appName = str_replace($repositoryRoot, '', $appRoot);
        }

        $toolstack = $this->getToolstack($appRoot, $appConfig);
        if (!$toolstack) {
            $output->writeln("<comment>Could not detect toolstack for directory: $appRoot</comment>");
            return false;
        }

        $message = "Building application";
        if ($appName) {
            $message .= " <info>$appName</info>";
        }
        $message .= " using the toolstack <info>" . $toolstack->getKey() . "</info>";
        $output->writeln($message);

        $toolstack->setOutput($output);
        $toolstack->prepareBuild($appRoot, $projectRoot, $this->settings);

        $toolstack->build();
        $toolstack->install();

        $this->warnAboutHooks($appConfig, $output);

        $message = "Build complete";
        if ($appName) {
            $message .= " for <info>$appName</info>";
        }
        $output->writeln($message);
        return true;
    }

    /**
     * Warn the user that the CLI will not run build/deploy hooks.
     *
     * @param array $appConfig
     * @param OutputInterface $output
     *
     * @return bool
     */
    protected function warnAboutHooks(array $appConfig, OutputInterface $output)
    {
        if (empty($appConfig['hooks'])) {
            return false;
        }
        $indent = '        ';
        $output->writeln("<comment>You have defined the following hook(s). The CLI cannot run them locally.</comment>");
        foreach (array('build', 'deploy') as $hookType) {
            if (empty($appConfig['hooks'][$hookType])) {
                continue;
            }
            $output->writeln("    $hookType: |");
            $hooks = (array) $appConfig['hooks'][$hookType];
            $asString = implode("\n", array_map('trim', $hooks));
            $withIndent = $indent . str_replace("\n", "\n$indent", $asString);
            $output->writeln($withIndent);
        }
        return true;
    }

}
