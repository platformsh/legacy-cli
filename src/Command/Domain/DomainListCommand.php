<?php
namespace Platformsh\Cli\Command\Domain;

use GuzzleHttp\Exception\ClientException;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Domain;
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
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
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

        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

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
        $executable = $this->config()->get('application.executable');

        try {
            $domains = $project->getDomains();
        } catch (ClientException $e) {
            $this->handleApiException($e, $project);
            return 1;
        }

        if (empty($domains)) {
            $this->stdErr->writeln('No domains found for ' . $this->api()->getProjectLabel($project) . '.');
            $this->stdErr->writeln('');
            $this->stdErr->writeln(
                'Add a domain to the project by running <info>' . $executable . ' domain:add [domain-name]</info>'
            );

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $header = ['Name', 'SSL enabled', 'Creation date'];
        $rows = $this->buildDomainRows($domains);

        if ($table->formatIsMachineReadable()) {
            $table->render($rows, $header);

            return 0;
        }

        $this->stdErr->writeln("Your domains are: ");
        $table->render($rows, $header);

        $this->stdErr->writeln('');
        $this->stdErr->writeln([
            'To add a new domain, run: <info>' . $executable . ' domain:add [domain-name]</info>',
            'To view a domain, run: <info>' . $executable . ' domain:get [domain-name]</info>',
            'To delete a domain, run: <info>' . $executable . ' domain:delete [domain-name]</info>',
        ]);

        return 0;
    }
}
