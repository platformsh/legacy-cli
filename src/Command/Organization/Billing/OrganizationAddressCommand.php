<?php
namespace Platformsh\Cli\Command\Organization\Billing;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Address;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationAddressCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:billing:address';
    protected static $defaultDescription = "View or change an organization's billing address";

    private $api;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(
        Api $api,
        Config $config,
        PropertyFormatter $formatter,
        Selector $selector,
        Table $table
    )
    {
        $this->api = $api;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property')
            ->addArgument('properties', InputArgument::IS_ARRAY|InputArgument::OPTIONAL, 'Additional property/value pairs');
        $this->formatter->configureInput($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $property = $input->getArgument('property');
        $updates = $this->parseUpdates($input);

        // The 'orders' link depends on the billing permission.
        $org = $this->selector->selectOrganization($input, 'orders');
        $address = $org->getAddress();

        $result = 0;
        if ($property !== null) {
            if (empty($updates)) {
                $this->formatter->displayData($output, $address->getProperties(), $property);
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
        $headings = [];
        $values = [];
        foreach ($address->getProperties() as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }

        if (!$this->table->formatIsMachineReadable()) {
            $this->stdErr->writeln(\sprintf('Billing address for the organization %s:', $this->api->getOrganizationLabel($org)));
        }

        $this->table->renderSimple($values, $headings);

        if (!$this->table->formatIsMachineReadable()) {
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
        $properties = $input->getArgument('properties');
        if (empty($properties)) {
            return [$property => $value];
        }
        if (count($properties) % 2 !== 0) {
            throw new InvalidArgumentException('Invalid number of property/value pair arguments');
        }
        \array_unshift($properties, $value);
        \array_unshift($properties, $property);
        $updates = [];
        $tempPropertyName = '';
        foreach ($properties as $arg) {
            if ($tempPropertyName === '') {
                $tempPropertyName = $arg;
                continue;
            }
            if (isset($updates[$tempPropertyName])) {
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
            $this->stdErr->writeln('Updating the address with values: ' . \json_encode($updates, JSON_UNESCAPED_SLASHES));
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
