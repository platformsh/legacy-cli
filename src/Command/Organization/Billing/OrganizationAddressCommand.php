<?php
namespace Platformsh\Cli\Command\Organization\Billing;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Address;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationAddressCommand extends OrganizationCommandBase
{

    protected function configure()
    {
        $this->setName('organization:billing:address')
            ->setDescription("View or change an organization's billing address")
            ->addOrganizationOptions()
            ->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property')
            ->addArgument('properties', InputArgument::IS_ARRAY|InputArgument::OPTIONAL, 'Additional property/value pairs')
            ->addOption('form', null, InputOption::VALUE_NONE, 'Display a form for updating the address interactively');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if ($input->getOption('form') && !$input->isInteractive()) {
            $this->stdErr->writeln('The --form option cannot be used non-interactively.');
            return 1;
        }

        $property = $input->getArgument('property');
        $updates = $this->parseUpdates($input);

        // The 'orders' link depends on the billing permission.
        $org = $this->validateOrganizationInput($input, 'orders');
        $address = $org->getAddress();

        if ($input->getOption('form')) {
            $form = Form::fromArray($this->getAddressFormFields());
            if (($address->country !== '') && ($field = $form->getField('country'))) {
                $field->set('default', $address->country);
            }
            foreach ($address->getProperties() as $key => $value) {
                if ($value !== '' && ($field = $form->getField($key))) {
                    $field->set('autoCompleterValues', [$value]);
                }
            }
            foreach ($updates as $key => $value) {
                if ($field = $form->getField($key)) {
                    $field->set('default', $value);
                }
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $updates = $form->resolveOptions($input, $output, $questionHelper);
        }

        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $result = 0;
        if ($property !== null || !empty($updates)) {
            if (empty($updates)) {
                $formatter->displayData($output, $address->getProperties(), $property);
                return $result;
            }
            $result = $this->setProperties($updates, $address);
        }

        if ($result === 0) {
            $this->display($address, $org, $input);
        }
        return $result;
    }

    protected function display(Address $address, Organization $org, InputInterface $input)
    {
        /** @var Table $table */
        $table = $this->getService('table');

        $headings = [];
        $values = [];
        /** @var PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');
        foreach ($address->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $formatter->format($value, $key);
        }

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Billing address for the organization %s:', $this->api()->getOrganizationLabel($org)));
        }

        $table->renderSimple($values, $headings);

        if (!$table->formatIsMachineReadable()) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(\sprintf('To view the billing profile, run: <info>%s</info>', $this->otherCommandExample($input, 'org:billing:profile')));
            $this->stdErr->writeln(\sprintf('To view organization details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:info')));
        }
    }

    protected function parseUpdates(InputInterface $input)
    {
        $property = $input->getArgument('property');
        $value = $input->getArgument('value');
        if ($property === null || $value === null) {
            return [];
        }
        $updates[$property] = $value;
        $properties = $input->getArgument('properties');
        if (empty($properties)) {
            return $updates;
        }
        if (count($properties) % 2 !== 0) {
            throw new InvalidArgumentException('Invalid number of property/value pair arguments');
        }
        \array_unshift($properties, $value);
        \array_unshift($properties, $property);
        $tempPropertyName = '';
        foreach ($properties as $arg) {
            if ($tempPropertyName === '') {
                $tempPropertyName = $arg;
                continue;
            }
            if (isset($values[$tempPropertyName])) {
                throw new InvalidArgumentException('Property defined twice: ' . $tempPropertyName);
            }
            if (!$this->validateValue($tempPropertyName, $arg)) {
                throw new InvalidArgumentException(\sprintf('Invalid value for %s: %s', $tempPropertyName, $arg));
            }
            $updates[$tempPropertyName] = $arg;
            $tempPropertyName = '';
        }
        return $updates;
    }

    /**
     * @param array $updates
     * @param Address $address
     *
     * @return int
     */
    protected function setProperties(array $updates, Address $address)
    {
        $currentValues = \array_intersect_key($address->getProperties(), $updates);
        if ($currentValues == $updates) {
            $this->stdErr->writeln('There are no changes to make.');
            $this->stdErr->writeln('');
            return 0;
        }
        try {
            $this->stdErr->writeln('Updating the address with values: ' . \json_encode($updates, true, JSON_UNESCAPED_SLASHES));
            $address->update($updates);
            $this->stdErr->writeln('');
            return 0;
        } catch (BadResponseException $e) {
            // Translate validation error messages.
            if (($response = $e->getResponse()) && $response->getStatusCode() === 400 && ($body = $response->getBody())) {
                $detail = \json_decode((string) $body, true);
                if (\is_array($detail) && isset($detail['title']) && \is_string($detail['title'])) {
                    $this->stdErr->writeln($detail['title']);
                    return 1;
                } elseif (\is_array($detail) && isset($detail['detail']) && \is_string($detail['detail'])) {
                    $this->stdErr->writeln($detail['detail']);
                    return 1;
                } elseif (\is_array($detail) && !empty($detail)) {
                    foreach ($detail as $errorProperty => $errorValue) {
                        $this->stdErr->writeln("Invalid value for <error>$errorProperty</error>: " . $errorValue);
                    }
                    return 1;
                }
            }
            throw $e;
        }
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
