<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class ConfigCheckCommand extends PlatformCommand
{

    protected function configure()
    {
        $this
          ->setName('config:check')
          ->setAliases(array('check'))
          ->setDescription('Check the project configuration')
          ->addOption('repository', null, InputOption::VALUE_OPTIONAL, 'The repository directory to check');
    }

    public function isLocal()
    {
        return true;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repositoryDir = $input->getOption('repository');
        if ($repositoryDir !== null) {
            if (!is_dir($repositoryDir)) {
                throw new \InvalidArgumentException('Repository directory not found: ' . $repositoryDir);
            }
        }
        elseif (!$projectRoot = $this->getProjectRoot()) {
            throw new RootNotFoundException('Project root not found. Specify --repository or go to a project directory.');
        }
        else {
            $repositoryDir = $projectRoot . '/' . LocalProject::REPOSITORY_DIR;
        }

        $valid = $this->parseYaml($repositoryDir . '/.platform.app.yaml');

        foreach (array('.platform/services.yaml', '.platform/routes.yaml') as $filename) {
            if (file_exists($repositoryDir . '/' . $filename)) {
                $valid = $valid && $repositoryDir . '/' . $filename;
            }
        }

        if (!$valid) {
            return 1;
        }

        // @todo add more checks - at the moment this only finds parse errors

        $this->stdErr->writeln("No configuration errors found.");
        return 0;
    }

    /**
     * @param $filename
     *
     * @return array|false
     */
    protected function parseYaml($filename)
    {
        if (!file_exists($filename)) {
            $this->stdErr->writeln("File not found: <error>$filename</error>");
            return false;
        }

        try {
            $yaml = new Yaml();
            return $yaml->parse($filename);
        }
        catch (ParseException $e) {
            $this->stdErr->writeln("Failed to parse YAML in file: <error>$filename</error>");
            $this->stdErr->writeln($e->getMessage());
            return false;
        }
    }
}
