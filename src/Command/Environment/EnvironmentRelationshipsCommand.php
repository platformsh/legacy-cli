<?php
namespace Platformsh\Cli\Command\Environment;

use GuzzleHttp\Query;
use GuzzleHttp\Url;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->setAliases(['relationships'])
            ->setDescription('Show an environment\'s relationships')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the relationships');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'master' environment's relationships", 'master');
        $this->addExample("View the 'master' environment's database port", 'master --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\Relationships $relationshipsService */
        $relationshipsService = $this->getService('relationships');
        $host = $this->selectHost($input, $relationshipsService->hasLocalEnvVar());

        $relationships = $relationshipsService->getRelationships($host, $input->getOption('refresh'));

        foreach ($relationships as $name => $relationship) {
            foreach ($relationship as $index => $instance) {
                if (!isset($instance['url'])) {
                    $relationships[$name][$index]['url'] = $this->buildUrl($instance);
                }
            }
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        $formatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }

    /**
     * Builds a URL from the parts included in a relationship array.
     *
     * @param array $instance
     *
     * @return string
     */
    private function buildUrl(array $instance)
    {
        $parts = $instance;
        // Convert to parse_url parts.
        $parts['user'] = $parts['username'];
        $parts['pass'] = $parts['password'];
        unset($parts['username'], $parts['password']);
        // The 'query' is expected to be a string.
        if (is_array($parts['query'])) {
            $parts['query'] = (new Query($parts['query']))->__toString();
        }

        return Url::buildUrl($parts);
    }
}
