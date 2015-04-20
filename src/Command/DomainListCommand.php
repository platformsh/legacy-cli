<?php

namespace Platformsh\Cli\Command;

use Platformsh\Client\Model\Domain;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('domain:list')
          ->setAliases(array('domains'))
          ->setDescription('Get a list of all domains')
          ->addOption(
            'project',
            null,
            InputOption::VALUE_OPTIONAL,
            'The project ID'
          );
    }

    /**
     * Build a table of domains.
     *
     * @param Domain[] $tree
     * @param OutputInterface $output
     *
     * @return Table
     */
    protected function buildDomainTable(array $tree, $output)
    {
        $table = new Table($output);
        $table
          ->setHeaders(array('Name', 'Wildcard', 'SSL enabled', 'Creation date'))
          ->addRows($this->buildDomainRows($tree));

        return $table;
    }

    /**
     * Recursively build rows of the domain table.
     *
     * @param Domain[] $tree
     *
     * @return array
     */
    protected function buildDomainRows(array $tree)
    {
        $rows = array();

        foreach ($tree as $domain) {

            // Indicate that the domain is a wildcard.
            $wildcard = ($domain['wildcard'] == true) ? "Yes" : "No";

            // Indicate that the domain had a SSL certificate.
            $hasCert = ($domain['ssl']['has_certificate'] == true) ? "Yes" : "No";

            $rows[] = array(
              $domain['id'],
              $wildcard,
              $hasCert,
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

        $project = $this->getSelectedProject();
        $domains = $project->getDomains();

        if (empty($domains)) {
            $output->writeln("\nNo domains found for " . $project->title);
        } else {
            $output->writeln("\nYour domains are: ");
            $table = $this->buildDomainTable($domains, $output);
            $table->render();
        }

        $output->writeln("\nAdd a domain to your project by running <info>platform domain:add [domain-name]</info>");
        if (!empty($domains)) {
            $output->writeln(
              "Delete a domain from your project by running <info>platform domain:delete [domain-name]</info>\n"
            );
        }

        // Output a newline after the current block of commands.
        $output->writeln("");
    }
}
