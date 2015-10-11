<?php

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Client\Model\Subscription;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class SubscriptionInfoCommand extends PlatformCommand
{
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
        $this->addProjectOption();
        $this->setHiddenInList();
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
            return $this->listProperties($subscription);
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
     *
     * @return int
     */
    protected function listProperties(Subscription $subscription)
    {
        $table = new Table($this->output);
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
