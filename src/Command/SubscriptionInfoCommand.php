<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\Table;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionInfoCommand extends CommandBase
{
    protected $hiddenInList = true;

    /** @var PropertyFormatter */
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
        Table::addFormatOption($this->getDefinition());
        $this->addProjectOption();
        $this->addExample('View all subscription properties')
             ->addExample('View the subscription status', 'status')
             ->addExample('View the storage limit (in MiB)', 'storage');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();
        $subscription = $this->getClient()
                             ->getSubscription($project->getSubscriptionId());
        if (!$subscription) {
            $this->stdErr->writeln("Subscription not found");

            return 1;
        }
        $this->formatter = new PropertyFormatter();

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription, new Table($input, $output));
        }

        $output->writeln(
            $this->formatter->format(
                $subscription->getProperty($property),
                $property
            )
        );

        return 0;
    }

    /**
     * @param Subscription $subscription
     * @param Table        $table
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription, Table $table)
    {
        $headings = [];
        $values = [];
        foreach ($subscription->getProperties() as $key => $value) {
            $value = $this->formatter->format($value, $key);
            $value = wordwrap($value, 50, "\n", true);
            $headings[] = $key;
            $values[] = $value;
        }
        $table->renderSimple($values, $headings);

        return 0;
    }
}
