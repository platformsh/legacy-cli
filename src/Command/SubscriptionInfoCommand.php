<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionInfoCommand extends CommandBase
{
    protected $hiddenInList = true;

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
            ->setDescription('Read subscription properties');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
        $this->addExample('View all subscription properties')
             ->addExample('View the subscription status', 'status')
             ->addExample('View the storage limit (in MiB)', 'storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();
        $subscription = $this->api()->getClient()
                             ->getSubscription($project->getSubscriptionId());
        if (!$subscription) {
            $this->stdErr->writeln("Subscription not found");

            return 1;
        }
        $this->formatter = $this->getService('property_formatter');

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription);
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
}
