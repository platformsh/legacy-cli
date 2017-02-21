<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DomainGetCommand extends DomainCommandBase
{

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('domain:get')
            ->setDescription('Show detailed information for a domain')
            ->addArgument('name', InputArgument::OPTIONAL, 'The domain name')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The domain property to view');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $project = $this->getSelectedProject();

        $domainName = $input->getArgument('name');
        if (empty($domainName)) {
            if (!$input->isInteractive()) {
                $this->stdErr->writeln('The domain name is required.');
                return 1;
            }

            $domains = $project->getDomains();
            $options = [];
            foreach ($domains as $domain) {
                $options[$domain->name] = $domain->name;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $domainName = $questionHelper->choose($options, 'Enter a number to choose a domain:');
        }

        $domain = $project->getDomain($domainName);
        if (!$domain) {
            $this->stdErr->writeln('Domain not found: <error>' . $domainName . '</error>');
            return 1;
        }

        /** @var \Platformsh\Cli\Service\PropertyFormatter $propertyFormatter */
        $propertyFormatter = $this->getService('property_formatter');

        if ($property = $input->getOption('property')) {
            $value = $this->api()->getNestedProperty($domain, $property);
            $output->writeln($propertyFormatter->format($value, $property));

            return 0;
        }

        $values = [];
        $properties = [];
        foreach ($domain->getProperties() as $name => $value) {
            // Hide the deprecated (irrelevant) property 'wildcard'.
            if ($name === 'wildcard') {
                continue;
            }
            $properties[] = $name;
            $values[] = $propertyFormatter->format($value, $name);
        }
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $table->renderSimple($values, $properties);

        $this->stdErr->writeln('');
        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln([
            'To update a domain, run: <info>' . $executable . ' domain:update [domain-name]</info>',
            'To delete a domain, run: <info>' . $executable . ' domain:delete [domain-name]</info>',
        ]);

        return 0;
    }
}
