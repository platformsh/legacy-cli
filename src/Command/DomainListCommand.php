<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\PropertyFormatter;
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

        $formatter = new PropertyFormatter();

        foreach ($tree as $domain) {
            $rows[] = array(
              $domain['id'],
              $formatter->format($domain['wildcard']),
              $formatter->format((bool) $domain['has_certificate']),
              $formatter->format($domain['created_at'], 'created_at'),
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
            $output->writeln("No domains found for <info>{$project->title}</info>");
        } else {
            $output->writeln("Your domains are: ");
            $table = $this->buildDomainTable($domains, $output);
            $table->render();
        }

        $output->writeln("\nAdd a domain to the project by running <info>platform domain:add [domain-name]</info>");
        if (!empty($domains)) {
            $output->writeln(
              "Delete domains by running <info>platform domain:delete [domain-name]</info>"
            );
        }
    }
}
