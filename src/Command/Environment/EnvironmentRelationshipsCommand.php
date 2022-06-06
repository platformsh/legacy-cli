<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Ssh;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends CommandBase
{
    protected static $defaultName = 'environment:relationships|relationships';
    protected static $defaultDescription = "Show an environment's relationships";

    private $formatter;
    private $relationships;
    private $selector;
    private $ssh;

    public function __construct(PropertyFormatter $formatter, Relationships $relationships, Selector $selector, Ssh $ssh) {
        $this->formatter = $formatter;
        $this->relationships = $relationships;
        $this->selector = $selector;
        $this->ssh = $ssh;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the relationships');

        $definition = $this->getDefinition();
        $this->selector->addAllOptions($definition);
        $this->ssh->configureInput($definition);

        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'main' environment's relationships", 'main');
        $this->addExample("View the 'main' environment's database port", 'main --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input, false, $this->relationships->hasLocalEnvVar());
        $relationships = $this->relationships->getRelationships($selection->getHost(), $input->getOption('refresh'));

        foreach ($relationships as $name => $relationship) {
            foreach ($relationship as $index => $instance) {
                if (!isset($instance['url'])) {
                    $relationships[$name][$index]['url'] = $this->relationships->buildUrl($instance);
                }
            }
        }

        $this->formatter->displayData($output, $relationships, $input->getOption('property'));

        return 0;
    }
}
