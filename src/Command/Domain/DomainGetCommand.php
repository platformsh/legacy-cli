<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Domain;

use Platformsh\Cli\Selector\SelectorConfig;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Model\EnvironmentDomain;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Util\OsUtil;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'domain:get', description: 'Show detailed information for a domain')]
class DomainGetCommand extends DomainCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The domain name')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The domain property to view');
        Table::configureInput($this->getDefinition());
        PropertyFormatter::configureInput($this->getDefinition());
        $this->selector->addProjectOption($this->getDefinition());
        $this->selector->addEnvironmentOption($this->getDefinition());
        $this->addCompleter($this->selector);
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, new SelectorConfig(envRequired: false));
        $project = $selection->getProject();
        $forEnvironment = $input->getOption('environment') !== null;
        $environment = $forEnvironment ? $selection->getEnvironment() : null;
        $httpClient = $this->api->getHttpClient();

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
            $domainName = $this->questionHelper->choose($options, 'Enter a number to choose a domain:');
            $domain = $byName[$domainName];
        }

        if ($property = $input->getOption('property')) {
            $value = $this->api->getNestedProperty($domain, $property);
            $output->writeln($this->propertyFormatter->format($value, $property));

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
            $values[] = $this->propertyFormatter->format($value, $name);
        }
        $this->table->renderSimple($values, $properties);

        $this->stdErr->writeln('');
        $executable = $this->config->getStr('application.executable');
        $exampleArgs = '';
        if ($forEnvironment) {
            $exampleArgs = '-e ' . OsUtil::escapeShellArg($selection->getEnvironment()->name) . ' ';
        }
        $exampleArgs .= OsUtil::escapeShellArg($domainName);
        $this->stdErr->writeln([
            sprintf('To update the domain, run: <info>%s domain:update %s</info>', $executable, $exampleArgs),
            sprintf('To delete the domain, run: <info>%s domain:delete %s</info>', $executable, $exampleArgs),
        ]);

        return 0;
    }
}
