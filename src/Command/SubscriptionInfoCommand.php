<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionInfoCommand extends CommandBase
{
    /** @var \Platformsh\Cli\Service\PropertyFormatter|null */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('subscription:info')
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
            ->addArgument('value', InputArgument::OPTIONAL, 'Set a new value for the property')
            ->addOption('id', 's', InputOption::VALUE_REQUIRED, 'The subscription ID')
            ->setDescription('Read or modify subscription properties');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addExample('View all subscription properties')
             ->addExample('View the subscription status', 'status')
             ->addExample('View the storage limit (in MiB)', 'storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $id = $input->getOption('id');
        if (empty($id)) {
            $this->validateInput($input);
            $project = $this->getSelectedProject();
            $id = $project->getSubscriptionId();
        }

        $subscription = $this->api()->getClient()
                             ->getSubscription($id);
        if (!$subscription) {
            $this->stdErr->writeln(sprintf('Subscription not found: <error>%s</error>', $id));

            return 1;
        }
        $this->formatter = $this->getService('property_formatter');

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription);
        }

        $value = $input->getArgument('value');
        if ($value !== null) {
            return $this->setProperty($property, $value, $subscription);
        }

        switch ($property) {
            case 'url':
                $value = $subscription->getUri(true);
                break;

            default:
                $value = $this->api()->getNestedProperty($subscription, $property);
        }

        $output->writeln($this->formatter->format($value, $property));

        return 0;
    }

    /**
     * @param Subscription $subscription
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription)
    {
        $headings = [];
        $values = [];
        foreach ($subscription->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
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
    protected function setProperty($property, $value, Subscription $subscription)
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

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $confirmMessage = sprintf(
            "Are you sure you want to change property '%s' from <comment>%s</comment> to <comment>%s</comment>?",
            $property,
            $this->formatter->format($currentValue, $property),
            $this->formatter->format($value, $property)
        );
        $warning = sprintf(
            '<comment>This action may %s the cost of your subscription.</comment>',
            is_numeric($value) && $value > $currentValue ? 'increase' : 'change'
        );
        $confirmMessage = $warning . "\n" . $confirmMessage;
        if (!$questionHelper->confirm($confirmMessage)) {
            return 1;
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
    protected function getType($property)
    {
        $writableProperties = ['plan' => 'string', 'environments' => 'int', 'storage' => 'int'];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }
}
