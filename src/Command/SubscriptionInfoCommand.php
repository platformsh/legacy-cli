<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'subscription:info', description: 'Read or modify subscription properties')]
class SubscriptionInfoCommand extends CommandBase
{
    /** @var PropertyFormatter|null */
    protected $formatter;
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly PropertyFormatter $propertyFormatter, private readonly QuestionHelper $questionHelper, private readonly Table $table)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('id', 's', InputOption::VALUE_REQUIRED, 'The subscription ID');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addExample('View all subscription properties')
             ->addExample('View the subscription status', 'status')
             ->addExample('View the storage limit (in MiB)', 'storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = $input->getOption('id');
        $project = null;
        if (empty($id)) {
            $this->validateInput($input);
            $project = $this->getSelectedProject();
            $id = $project->getSubscriptionId();
        }

        $subscription = $this->api->loadSubscription($id, $project, $input->getArgument('value') !== null);
        if (!$subscription) {
            $this->stdErr->writeln(sprintf('Subscription not found: <error>%s</error>', $id));

            return 1;
        }

        $this->formatter = $this->propertyFormatter;

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $subscription);
        }

        $value = match ($property) {
            'url' => $subscription->getUri(true),
            default => $this->api->getNestedProperty($subscription, $property),
        };

        $output->writeln($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param Subscription $subscription
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription): int
    {
        $headings = [];
        $values = [];
        foreach ($subscription->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $table = $this->table;
        $table->renderSimple($values, $headings);

        return 0;
    }

    /**
     * @param string       $property
     * @param string       $value
     * @param Subscription $subscription
     *
     * @return int
     */
    protected function setProperty($property, $value, Subscription $subscription): int
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");
            return 1;
        }
        if ($type === 'boolean' && $value === 'false') {
            $value = false;
        }
        settype($value, $type);
        $currentValue = $subscription->getProperty($property);
        if ($currentValue === $value) {
            $this->stdErr->writeln(
                "Property <info>$property</info> already set as: " . $this->formatter->format($value, $property)
            );

            return 0;
        }

        $questionHelper = $this->questionHelper;
        $confirmMessage = sprintf(
            "Are you sure you want to change property '%s' from <comment>%s</comment> to <comment>%s</comment>?",
            $property,
            $this->formatter->format($currentValue, $property),
            $this->formatter->format($value, $property)
        );
        if ($this->config->getWithDefault('warnings.project_users_billing', true)) {
            $warning = sprintf(
                '<comment>This action may %s the cost of your subscription.</comment>',
                is_numeric($value) && $value > $currentValue ? 'increase' : 'change'
            );
            $confirmMessage = $warning . "\n" . $confirmMessage;
            if (!$questionHelper->confirm($confirmMessage)) {
                return 1;
            }
        }

        $subscription->update([$property => $value]);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->formatter->format($value, $property)
        ));

        return 0;
    }

    /**
     * Get the type of a writable property.
     *
     * @param string $property
     *
     * @return string|false
     */
    protected function getType($property): string|false
    {
        $writableProperties = ['plan' => 'string', 'environments' => 'int', 'storage' => 'int'];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }
}
