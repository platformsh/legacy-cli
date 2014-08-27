<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends EnvironmentCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain')
            ->setDescription('Get a list of all domains.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            );
    }

    /**
     * Build a tree out of a list of domains.
     */
    protected function buildDomainTree($domains, $parent = null)
    {
        $children = array();
        foreach ($domains as $domain) {
            if ($domain['parent'] === $parent) {
                $domain['children'] = $this->buildDomainTree($domains, $domain['id']);
                $children[$domain['id']] = $domain;
            }
        }
        return $children;
    }

    /**
     * Build a table of domains.
     */
    protected function buildDomainTable($tree)
    {
        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('Name', 'SSL enabled', 'Creation date'))
            ->setRows($this->buildDomainRows($tree));

        return $table;
    }

    /**
     * Recursively build rows of the domain table.
     */
    protected function buildDomainRows($tree, $indent = 0)
    {
        $rows = array();
        foreach ($tree as $domain) {
            
            // Indicate that the domain is a wildcard.
            $id = str_repeat(' ', $indent) . $domain['id'];
            if ($domain['wildcard'] == FALSE) {
                $id = "<info>*</info>." . $id;
            }

            // Indicate that the domain had a SSL certificate.
            $domain['ssl']['has_certificate'] = ($domain['ssl']['has_certificate'] == TRUE) ? "Yes" : "No";

            $rows[] = array(
                $id,
                $domain['ssl']['has_certificate'],
                $domain['created_at']
            );

            $rows = array_merge($rows, $this->buildDomainRows($domain['children'], $indent + 1));
        }
        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return;
        }

        $this->currentEnvironment = $this->getCurrentEnvironment($this->project);
        $domains = $this->getDomains($this->project);

        // @todo: Remove this since there is no hierarchy in domains.
        $tree = $this->buildDomainTree($domains);

        $output->writeln("\nYour domains are: ");
        $table = $this->buildDomainTable($tree);
        $table->render($output);

        $output->writeln("\n<info>*</info> - Indicates that the domain is a wildcard.");
        $output->writeln("Add a SSL certificate to a domain by running <info>platform domain:ssl-add</info>");
        // Output a newline after the current block of commands.
        $output->writeln("");
    }
}
