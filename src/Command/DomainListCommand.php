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
            ->setDescription('Get a list of all environments.')
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
    protected function buildDomainTree($environments, $parent = null)
    {
        $children = array();
        foreach ($environments as $environment) {
            if ($environment['parent'] === $parent) {
                $environment['children'] = $this->buildDomainTree($environments, $environment['id']);
                $children[$environment['id']] = $environment;
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
            ->setHeaders(array('ID', 'Name', 'SSL', 'Wildcard'))
            ->setRows($this->buildDomainRows($tree));

        return $table;
    }

    /**
     * Recursively build rows of the domain table.
     */
    protected function buildDomainRows($tree, $indent = 0)
    {
        $rows = array();
        foreach ($tree as $environment) {
            // Inactive environments have no public url.
            $link = '';
            if (!empty($environment['_links']['public-url'])) {
                $link = $environment['_links']['public-url']['href'];
            }

            $id = str_repeat(' ', $indent) . $environment['id'];
            if ($environment['id'] == $this->currentEnvironment['id']) {
                $id .= "<info>*</info>";
            }
            $rows[] = array(
                $id,
                $environment['title'],
                $link,
            );

            $rows = array_merge($rows, $this->buildDomainRows($environment['children'], $indent + 1));
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
        $environments = $this->getEnvironments($this->project);
        $tree = $this->buildDomainTree($environments);

        // To make the display nicer, we move all the children of master
        // to the top level.
        if (isset($tree['master'])) {
            $tree += $tree['master']['children'];
            $tree['master']['children'] = array();
        }

        $output->writeln("\nYour domains are: ");
        $table = $this->buildDomainTable($tree);
        $table->render($output);

        $output->writeln("\n<info>*</info> - Indicates the default domain.");
        $output->writeln("Add a SSL certificate to a domain by running <info>platform domain:ssl-add</info>");
        // Output a newline after the current block of commands.
        $output->writeln("");
    }
}
