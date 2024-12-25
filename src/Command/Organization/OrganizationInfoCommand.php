<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\CountryService;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Organization;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:info', description: 'View or change organization details')]
class OrganizationInfoCommand extends OrganizationCommandBase
{
    public function __construct(private readonly Api $api, private readonly CountryService $countryService, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addCompleter($this->selector);
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Refresh the cache');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
        $this->addExample('View the organization "acme"', '--org acme')
            ->addExample("Show the organization's label", '--org acme label')
            ->addExample('Change the organization label', '--org acme label "ACME Inc."');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $property = $input->getArgument('property');
        $value = $input->getArgument('value');
        $skipCache = $value !== null || $input->getOption('refresh');
        $organization = $this->selector->selectOrganization($input, '', '', $skipCache);

        if ($property === null) {
            $this->listProperties($organization);
            return 0;
        }

        if ($value === null) {
            $this->propertyFormatter->displayData($output, $this->getProperties($organization), $property);
            return 0;
        }

        return $this->setProperty($property, $value, $organization);
    }

    /**
     * @return array<string, mixed>
     */
    private function getProperties(Organization $organization): array
    {
        $data = $organization->getProperties();

        // Set the owner from the user reference.
        if (isset($data['owner_id']) && isset($data['ref:users'][$data['owner_id']])) {
            $data['owner'] = $data['ref:users'][$data['owner_id']]->getProperties();
            unset($data['ref:users']);
        }

        return $data;
    }

    private function listProperties(Organization $organization): void
    {
        $headings = [];
        $values = [];
        foreach ($this->getProperties($organization) as $key => $value) {
            $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
            $values[] = $this->propertyFormatter->format($value, $key);
        }
        $this->table->renderSimple($values, $headings);
    }

    protected function setProperty(string $property, string $value, Organization $organization): int
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }

        $currentValue = $organization->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $this->propertyFormatter->format($organization->getProperty($property, false), $property),
            ));

            return 0;
        }
        try {
            $organization->update([$property => $value]);
        } catch (BadResponseException $e) {
            // Translate validation error messages.
            if ($e->getResponse()->getStatusCode() === 400 && ($body = $e->getResponse()->getBody())) {
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
        $this->api->clearOrganizationCache($organization);
        $this->stdErr->writeln(sprintf(
            'Property <info>%s</info> set to: %s',
            $property,
            $this->propertyFormatter->format($organization->$property, $property),
        ));

        return 0;
    }

    /**
     * Gets the type of a writable property.
     */
    private function getType(string $property): string|false
    {
        $writableProperties = [
            'name' => 'string',
            'label' => 'string',
            'country' => 'string',
        ];

        return $writableProperties[$property] ?? false;
    }

    private function validateValue(string $property, string &$value): bool
    {
        $type = $this->getType($property);
        if (!$type) {
            $this->stdErr->writeln("Property not writable: <error>$property</error>");

            return false;
        }
        if ($property === 'country') {
            $value = $this->countryService->countryToCode($value);
            if (!isset($this->countryService->listCountries()[$value])) {
                $this->stdErr->writeln("Unrecognized country name or code: <error>$value</error>");
                return false;
            }
        }
        return \settype($value, $type);
    }
}
