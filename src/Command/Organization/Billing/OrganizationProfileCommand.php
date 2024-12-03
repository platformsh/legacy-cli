<?php
namespace Platformsh\Cli\Command\Organization\Billing;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Api;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\Organization\OrganizationCommandBase;
use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Organization\Profile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:billing:profile', description: "View or change an organization's billing profile")]
class OrganizationProfileCommand extends OrganizationCommandBase
{

    public function __construct(private readonly Api $api, private readonly PropertyFormatter $propertyFormatter, private readonly Selector $selector, private readonly Table $table)
    {
        parent::__construct();
    }
    protected function configure()
    {
        $this->selector->addOrganizationOptions($this->getDefinition(), true);
        $this->addArgument('property', InputArgument::OPTIONAL, 'The name of a property to view or change')
            ->addArgument('value', InputArgument::OPTIONAL, 'A new value for the property');
        PropertyFormatter::configureInput($this->getDefinition());
        Table::configureInput($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $org = $this->selector->selectOrganization($input, 'orders');
        $profile = $org->getProfile();

        $formatter = $this->propertyFormatter;

        $property = $input->getArgument('property');
        if ($property === null) {
            $headings = [];
            $values = [];
            $formatter = $this->propertyFormatter;
            foreach ($profile->getProperties() as $key => $value) {
                $headings[] = new AdaptiveTableCell($key, ['wrap' => false]);
                $values[] = $formatter->format($value, $key);
            }

            $table = $this->table;

            if (!$table->formatIsMachineReadable()) {
                $this->stdErr->writeln(\sprintf('Billing profile for the organization %s:', $this->api->getOrganizationLabel($org)));
            }

            $table->renderSimple($values, $headings);

            if (!$table->formatIsMachineReadable()) {
                $this->stdErr->writeln(\sprintf('To view the billing address, run: <info>%s</info>', $this->otherCommandExample($input, 'org:billing:address')));
                $this->stdErr->writeln(\sprintf('To view organization details, run: <info>%s</info>', $this->otherCommandExample($input, 'org:info')));
            }
            return 0;
        }

        $value = $input->getArgument('value');
        if ($value === null) {
            $formatter->displayData($output, $profile->getProperties(), $property);
            return 0;
        }

        return $this->setProperty($property, $value, $profile);
    }

    /**
     * @param string  $property
     * @param string  $value
     * @param Profile $profile
     *
     * @return int
     */
    protected function setProperty($property, $value, Profile $profile): int
    {
        if (!$this->validateValue($property, $value)) {
            return 1;
        }
        $formatter = $this->propertyFormatter;

        $currentValue = $profile->getProperty($property, false);
        if ($currentValue === $value) {
            $this->stdErr->writeln(sprintf(
                'Property <info>%s</info> already set as: %s',
                $property,
                $formatter->format($profile->getProperty($property, false), $property)
            ));

            return 0;
        }
        try {
            $profile->update([$property => $value]);
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
            $formatter->format($profile->$property, $property)
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
    private function getType($property): string|false
    {
        $writableProperties = [
            'company_name' => 'string',
            'billing_contact' => 'string',
            'security_contact' => 'string',
            'vat_number' => 'string',
            'default_catalog' => 'string',
            'project_options_url' => 'string',
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
