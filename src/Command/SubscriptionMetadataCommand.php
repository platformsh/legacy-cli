<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionMetadataCommand extends PlatformCommand
{
    /** @var PropertyFormatter */
    protected $formatter;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('subscription:metadata')
          ->addArgument('property', InputArgument::OPTIONAL, 'The name of the property')
          ->setDescription('Read metadata for a subscription');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $project = $this->getSelectedProject();
        $subscription = $this->getClient()
          ->getSubscription($project->getSubscriptionId());
        if (!$subscription) {
            $output->writeln("Subscription not found");

            return 1;
        }
        $this->formatter = new PropertyFormatter();

        $property = $input->getArgument('property');

        if (!$property) {
            return $this->listProperties($subscription, $output);
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
     * @param Subscription    $subscription
     * @param OutputInterface $output
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription, OutputInterface $output)
    {
        $output->writeln("Metadata for the subscription <info>" . $subscription->id . "</info>:");

        $table = new Table($output);
        $table->setHeaders(array("Property", "Value"));
        foreach ($subscription->getProperties() as $key => $value) {
            $value = $this->formatter->format($value, $key);
            $value = wordwrap($value, 50, "\n", true);
            $table->addRow(array($key, $value));
        }
        $table->render();

        return 0;
    }
}
