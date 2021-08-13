<?php
namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Ref\UserRef;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationInfoCommand extends OrganizationCommandBase
{

    protected function configure()
    {
        $this->setName('organization:info')
            ->setDescription('View or change a single organization')
            ->addOrganizationOptions()
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'A property to display');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->validateOrganizationInput($input);

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $data = $organization->getProperties();

        // Set the owner from the user reference.
        if (isset($data['owner_id']) && isset($data['ref:users'][$data['owner_id']])) {
            $data['owner'] = $data['ref:users'][$data['owner_id']]->getProperties();
            unset($data['ref:users']);
        }

        if ($input->getOption('property')) {
            $formatter->displayData($output, $data, $input->getOption('property'));
            return 0;
        }

        $headings = [];
        $values = [];
        foreach ($data as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $formatter->format($value, $key);
        }
        /** @var Table $table */
        $table = $this->getService('table');
        $table->renderSimple($values, $headings);

        return 0;
    }
}
