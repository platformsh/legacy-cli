<?php
namespace Platformsh\Cli\Command\Organization\Billing;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Address;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationAddressCommand extends OrganizationCommandBase
{

    protected function configure()
    {
        $this->setName('organization:billing:address')
            ->setDescription("View or change an organization's billing address")
            ->addOrganizationOptions()
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $org = $this->validateOrganizationInput($input);
        $address = $org->getAddress();

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $property = $input->getArgument('property');
        if ($property === null) {
            $headings = [];
            $values = [];
            /** @var PropertyFormatter $formatter */
            $formatter = $this->getService('property_formatter');
            foreach ($address->getProperties() as $key => $value) {
                $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
                $values[] = $formatter->format($value, $key);
            }

            /** @var Table $table */
            $table = $this->getService('table');

            if (!$table->formatIsMachineReadable()) {
                $this->stdErr->writeln(\sprintf('Billing address for the organization %s:', $this->api()->getOrganizationLabel($org)));
            }

            $table->renderSimple($values, $headings);

            if (!$table->formatIsMachineReadable()) {
                $this->stdErr->writeln(\sprintf('To view the billing profile, run: <info>%s</info>', $this->otherCommandExample($input, 'org:billing:profile')));
                $this->stdErr->writeln(\sprintf('To view organization details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:info')));
            }
            return 0;
        }

        $value = $input->getArgument('value');
        if ($value === null) {
            $formatter->displayData($output, $address->getProperties(), $property);
            return 0;
        }

        return $this->setProperty($property, $value, $address);
    }

    /**
     * @param string  $property
     * @param string  $value
     * @param Address $address
     *
     * @return int
     */
    protected function setProperty($property, $value, Address $address)
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }
        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $currentValue = $address->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $formatter->format($address->getProperty($property, false), $property)
            ));

            return 0;
        }
        try {
            $address->update([$property => $value]);
        } catch (BadResponseException $e) {
            // Translate validation error messages.
            if (($response = $e->getResponse()) && $response->getStatusCode() === 400 && ($body = $response->getBody())) {
                $detail = \json_decode((string) $body, true);
                if (\is_array($detail) && !empty($detail['detail'][$property])) {
                    $this->stdErr->writeln("Invalid value for <error>$property</error>: " . $detail['detail'][$property]);
                    return 1;
                }
                if (\is_array($detail) && isset($detail['detail']) && \is_string($detail['detail'])) {
                    $this->stdErr->writeln($detail['detail']);
                    return 1;
                }
            }
            throw $e;
        }
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $formatter->format($address->$property, $property)
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
    private function getType($property)
    {
        $writableProperties = [
            'country' => 'string',
            'name_line' => 'string',
            'premise' => 'string',
            'sub_premise' => 'string',
            'thoroughfare' => 'string',
            'administrative_area' => 'string',
            'sub_administrative_area' => 'string',
            'locality' => 'string',
            'dependent_locality' => 'string',
            'postal_code' => 'string',
        ];

        return isset($writableProperties[$property]) ? $writableProperties[$property] : false;
    }

    /**
     * @param string $property
     * @param string &$value
     *
     * @return bool
     */
    private function validateValue($property, &$value)
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }
        return \settype($value, $type);
    }
}
