<?php

namespace Platformsh\Cli\Service;

use Platformsh\Cli\CliConfig;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

class Relationships
{

    protected $output;
    protected $shellHelper;
    protected $config;
    protected $ssh;

    /**
     * @param OutputInterface             $output
     * @param Ssh                         $ssh
     * @param Shell                       $shellHelper
     * @param CliConfig                   $config
     */
    public function __construct(OutputInterface $output, Ssh $ssh, Shell $shellHelper = null, CliConfig $config = null)
    {
        $this->output = $output;
        $this->ssh = $ssh;
        $this->shellHelper = $shellHelper ?: new Shell($output);
        $this->config = $config ?: new CliConfig();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputDefinition $definition
     */
    public static function configureInput(InputDefinition $definition)
    {
        $definition->addOption(
            new InputOption('relationship', 'r', InputOption::VALUE_REQUIRED, 'The database relationship to use')
        );
    }

    /**
     * @param string          $sshUrl
     * @param InputInterface  $input
     *
     * @return array|false
     */
    public function chooseDatabase($sshUrl, InputInterface $input)
    {
        $stdErr = $this->output instanceof ConsoleOutput ? $this->output->getErrorOutput() : $this->output;
        $relationships = $this->getRelationships($sshUrl);

        // Filter to find database (mysql and pgsql) relationships.
        $relationships = array_filter($relationships, function (array $relationship) {
            foreach ($relationship as $key => $service) {
                if ($service['scheme'] === 'mysql' || $service['scheme'] === 'pgsql') {
                    return true;
                }
            }

            return false;
        });

        if (empty($relationships)) {
            $stdErr->writeln('No databases found');
            return false;
        }

        // Use the --relationship option, if specified.
        if ($input->hasOption('relationship')
            && ($relationshipName = $input->getOption('relationship'))) {
            if (!isset($relationships[$relationshipName])) {
                $stdErr->writeln('Database relationship not found: ' . $relationshipName);
                return false;
            }
            $relationships = array_intersect_key($relationships, [$relationshipName => true]);
        }

        $questionHelper = new QuestionHelper($input, $this->output);
        $choices = [];
        $separator = '.';
        foreach ($relationships as $name => $relationship) {
            $serviceCount = count($relationship);
            foreach ($relationship as $key => $service) {
                $choices[$name . $separator . $key] = $name . ($serviceCount > 1 ? '.' . $key : '');
            }
        }
        $choice = $questionHelper->choose($choices, 'Enter a number to choose a database:');
        list($name, $key) = explode($separator, $choice, 2);
        $database = $relationships[$name][$key];

        // Add metadata about the database.
        $database['_relationship_name'] = $name;
        $database['_relationship_key'] = $key;

        return $database;
    }

    /**
     * @param string $sshUrl
     *
     * @return array
     */
    public function getRelationships($sshUrl)
    {
        $args = ['ssh'];
        if (isset($this->ssh)) {
            $args = array_merge($args, $this->ssh->getSshArgs());
        }
        $args[] = $sshUrl;
        $args[] = 'echo $' . $this->config->get('service.env_prefix') . 'RELATIONSHIPS';
        $result = $this->shellHelper->execute($args, null, true);

        return json_decode(base64_decode($result), true);
    }
}
