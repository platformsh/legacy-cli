<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationInfoCommand extends CommandBase
{

    protected function configure()
    {
        $this->setName('organization:info')
            ->setDescription('View or change a single organization')
            ->addOption('name', null, InputOption::VALUE_REQUIRED, 'The organization name')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'A property to display');
        PropertyFormatter::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $client = $this->api()->getClient();
        $name = $input->getOption('name');
        if (!$name) {
            // @todo interactive selection
            $this->stdErr->writeln('An organization <error>--name</error> is required.');
            return 1;
        }

        $organization = $client->getOrganizationByName($name);
        if (!$organization) {
            $this->stdErr->writeln(\sprintf('Organization not found: <error>%s</error>', $name));
            return 1;
        }

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $data = $organization->getProperties();
        // Convert ref objects to arrays.
        if (isset($data['ref:users'])) {
            foreach ($data['ref:users'] as &$item) {
                if ($item instanceof UserRef) {
                    $item = $item->getProperties();
                }
            }
        }

        $formatter->displayData($output, $data, $input->getOption('property'));

        return 0;
    }
}
