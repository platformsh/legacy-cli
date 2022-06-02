<?php
namespace Platformsh\Cli\Command\Organization;

use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CountryService;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationInfoCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:info';
    protected static $defaultDescription = 'View or change organization details';

    private $countryService;
    private $formatter;
    private $selector;
    private $table;

    public function __construct(Config $config, CountryService $countryService, PropertyFormatter $formatter, Selector $selector, Table $table)
    {
        $this->countryService = $countryService;
        $this->formatter = $formatter;
        $this->selector = $selector;
        $this->table = $table;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition());
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property');
        $this->formatter->configureInput($this->getDefinition());
        $this->table->configureInput($this->getDefinition());
        $this->addExample('View the organization "acme"', '--org acme')
            ->addExample("Show the organization's label", '--org acme label')
            ->addExample('Change the organization label', '--org acme label "ACME Inc."');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $organization = $this->selector->selectOrganization($input);

        $property = $input->getArgument('property');
        if ($property === null) {
            $this->listProperties($organization);
            return 0;
        }

        $value = $input->getArgument('value');
        if ($value === null) {
            $this->formatter->displayData($output, $this->getProperties($organization), $property);
            return 0;
        }

        return $this->setProperty($property, $value, $organization);
    }

    private function getProperties(Organization $organization)
    {
        $data = $organization->getProperties();

        // Set the owner from the user reference.
        if (isset($data['owner_id']) && isset($data['ref:users'][$data['owner_id']])) {
            $data['owner'] = $data['ref:users'][$data['owner_id']]->getProperties();
            unset($data['ref:users']);
        }

        return $data;
    }

    private function listProperties(Organization $organization)
    {
        $headings = [];
        $values = [];
        foreach ($this->getProperties($organization) as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->formatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);
    }

    /**
     * @param string      $property
     * @param string      $value
     * @param Organization $organization
     *
     * @return int
     */
    protected function setProperty($property, $value, Organization $organization)
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }

        $currentValue = $organization->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $this->formatter->format($organization->getProperty($property, false), $property)
            ));

            return 0;
        }
        try {
            $organization->update([$property => $value]);
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
            $this->formatter->format($organization->$property, $property)
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
            'name' => 'string',
            'label' => 'string',
            'country' => 'string',
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
        if ($property === 'country') {
            $value = $this->countryService->countryToCode($value);
            if (!isset($this->countryService->list()[$value])) {
                $this->stdErr->writeln("Unrecognized country name or code: <error>$value</error>");
                return false;
            }
        }
        return \settype($value, $type);
    }
}
