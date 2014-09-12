<?php

namespace CommerceGuys\Platform\Cli\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends DomainCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domains')
            ->setDescription('Get a list of all domains.')
            ->addOption(
                'project',
                null,
                InputOption::VALUE_OPTIONAL,
                'The project id'
            );
            parent::configure();
    }

    /**
     * Build a table of domains.
     */
    protected function buildDomainTable($tree)
    {
        $table = $this->getHelperSet()->get('table');
        $table
            ->setHeaders(array('Name', 'Wildcard', 'SSL enabled', 'Creation date'))
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
            $domain['wildcard'] = ($domain['wildcard'] == TRUE) ? "Yes" : "No";

            // Indicate that the domain had a SSL certificate.
            $domain['ssl']['has_certificate'] = ($domain['ssl']['has_certificate'] == TRUE) ? "Yes" : "No";

            $rows[] = array(
                $domain['id'],
                $domain['wildcard'],
                $domain['ssl']['has_certificate'],
                $domain['created_at'],
            );
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

        $domains = $this->getDomains($this->project);
        $project_name = !empty($this->project['name']) ? $this->project['name'] : $this->project['id'];

        if (empty($domains)) {
            $output->writeln("\nNo domains found for " . $project_name);
        } else {
            $output->writeln("\nYour domains are: ");
            $table = $this->buildDomainTable($domains);
            $table->render($output);
        }

        $output->writeln("\nAdd a domain to your project by running <info>platform domain:add [domain-name]</info>");
        if (!empty($domains)) {
            $output->writeln("Delete a domain from your project by running <info>platform domain:delete [domain-name]</info>\n");
        }

        // Output a newline after the current block of commands.
        $output->writeln("");
    }
}
