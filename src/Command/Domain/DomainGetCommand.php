<?php
namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
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
        $this->addProjectOption()
            ->addEnvironmentOption();
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $project = $this->getSelectedProject();
        $forEnvironment = $input->getOption('environment') !== null;
        $environment = $forEnvironment ? $this->getSelectedEnvironment() : null;
        $httpClient = $this->api()->getHttpClient();

        $domainName = $input->getArgument('name');
        if (!empty($domainName)) {
            $domain = $forEnvironment
                ? EnvironmentDomain::get($domainName, $environment->getLink('#domains'), $httpClient)
                : $project->getDomain($domainName);
            if (!$domain) {
                $this->stdErr->writeln('Domain not found: <error>' . $domainName . '</error>');
                return 1;
            }
        } elseif (!$input->isInteractive()) {
            $this->stdErr->writeln('The domain name is required.');
            return 1;
        } else {
            $domains = $forEnvironment ? EnvironmentDomain::getList($environment, $httpClient) : $project->getDomains();
            $options = [];
            $byName = [];
            foreach ($domains as $domain) {
                $options[$domain->name] = $domain->name;
                $byName[$domain->name] = $domain;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $domainName = $questionHelper->choose($options, 'Enter a number to choose a domain:');
            $domain = $byName[$domainName];
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
        $exampleArgs = '';
        if ($forEnvironment) {
            $exampleArgs = '-e ' . OsUtil::escapeShellArg($this->getSelectedEnvironment()->name) . ' ';
        }
        $exampleArgs .= OsUtil::escapeShellArg($domainName);
        $this->stdErr->writeln([
            sprintf('To update the domain, run: <info>%s domain:update %s</info>', $executable, $exampleArgs),
            sprintf('To delete the domain, run: <info>%s domain:delete %s</info>', $executable, $exampleArgs),
        ]);

        return 0;
    }
}
