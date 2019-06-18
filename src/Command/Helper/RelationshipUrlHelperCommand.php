<?php

namespace Platformsh\Cli\Command\Helper;

use GuzzleHttp\Query;
use GuzzleHttp\Url;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class RelationshipUrlHelperCommand extends HelperCommandBase
{
    protected function configure() {
        $this->setName('helper:relationship-url')
            ->setDescription(sprintf('Extract URLs from %sRELATIONSHIPS', $this->config()->get('service.env_prefix')))
            ->addArgument('relationship', InputArgument::OPTIONAL, 'Find by relationship name')
            ->addOption('service', null, InputOption::VALUE_REQUIRED, 'Find by service name')
            ->addOption('one', null, InputOption::VALUE_NONE, 'Return 1 relationship (error if more than 1 is found)');
    }

    protected function execute(InputInterface $input, OutputInterface $output) {
        $relationships = $this->getArrayEnvVar('RELATIONSHIPS');
        if (empty($relationships)) {
            $this->stdErr->writeln('No relationships found.');

            return 1;
        }

        $matching = $relationships;
        if ($name = $input->getArgument('relationship')) {
            if (!isset($relationships[$name])) {
                $this->stdErr->writeln('Relationship name not found: ' . $name);

                return 1;
            }
            $matching = [$relationships[$name]];
        } elseif ($service = $input->getOption('service')) {
            $matching = array_filter($relationships, function (array $route) use ($service) {
                return !empty($route['service']) && $route['service'] === $service;
            });
        }

        if (empty($matching)) {
            $this->stdErr->writeln('No matching relationships found.');

            return 1;
        }

        $urls = [];
        foreach ($matching as $relationship) {
            foreach ($relationship as $instance) {
                $urls[] = $this->buildUrl($instance);
            }
        }

        $success = true;
        if ($input->getOption('one')) {
            if (count($urls) !== 1) {
                $this->stdErr->writeln(sprintf('%d relationship URLs found (1 requested)', count($urls)));
                $success = false;
            }

            $output->writeln(reset($urls) ?: '');
        } else {
            $output->writeln($urls);
        }

        return $success ? 0 : 1;
    }

    /**
     * Builds a URL from the parts included in a relationship array.
     *
     * @param array $relationship
     *
     * @return string
     */
    private function buildUrl(array $relationship) {
        $parts = $relationship;

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
