<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Domain;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DomainListCommand extends DomainCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:list')
            ->setAliases(['domains'])
            ->setDescription('Get a list of all domains');
        $this->addProjectOption();
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
            ->setHeaders(['Name', 'SSL enabled', 'Creation date'])
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
        $rows = [];

        $formatter = new PropertyFormatter();

        foreach ($tree as $domain) {
            $rows[] = [
                $domain->id,
                $formatter->format((bool) $domain['ssl']['has_certificate']),
                $formatter->format($domain['created_at'], 'created_at'),
            ];
        }

        return $rows;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();


        try {
            $domains = $project->getDomains();
        }
        catch (ClientException $e) {
            $this->handleApiException($e, $project);
            return 1;
        }

        if (empty($domains)) {
            $this->stdErr->writeln("No domains found for <info>{$project->title}</info>");
            $this->stdErr->writeln("\nAdd a domain to the project by running <info>platform domain:add [domain-name]</info>");

            return 1;
        }

        $this->stdErr->writeln("Your domains are: ");
        $table = $this->buildDomainTable($domains, $output);
        $table->render();

        $this->stdErr->writeln("\nAdd a new domain by running <info>platform domain:add [domain-name]</info>");
        $this->stdErr->writeln(
            "Delete a domain by running <info>platform domain:delete [domain-name]</info>"
        );

        return 0;
    }
}
