<?php

namespace Platformsh\Cli\Command\Organization;

use CommerceGuys\Addressing\AddressFormat\AddressField;
use CommerceGuys\Addressing\AddressFormat\AddressFormatRepository;
use CommerceGuys\Addressing\AddressFormat\AdministrativeAreaType;
use CommerceGuys\Addressing\AddressFormat\LocalityType;
use CommerceGuys\Addressing\AddressFormat\PostalCodeType;
use CommerceGuys\Addressing\Country\CountryRepository;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Organization\Member;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Symfony\Component\Console\Input\InputInterface;

class OrganizationCommandBase extends CommandBase
{
    public function isEnabled()
    {
        if (!$this->config()->getWithDefault('api.organizations', false)) {
            return false;
        }
        return parent::isEnabled();
    }

    protected function memberLabel(Member $member)
    {
        if ($info = $member->getUserInfo()) {
            return $info->email;
        }

        return $member->id;
    }

    /**
     * Returns an example of another organization command, on this organization, for use in help messages.
     *
     * The current input needs to have been validated already (e.g. the --org option).
     *
     * Arguments will not be escaped (pre-escape them, or ideally only use args that do not use escaping).
     *
     * @param InputInterface $input
     * @param string $commandName
     * @param string $otherArgs
     *
     * @return string
     */
    protected function otherCommandExample(InputInterface $input, $commandName, $otherArgs = '')
    {
        $args = [
            $this->config()->get('application.executable'),
            $commandName,
        ];
        if ($input->hasOption('org') && $input->getOption('org')) {
            $args[] = '--org ' . $input->getOption('org');
        }
        if ($otherArgs !== '') {
            $args[] = $otherArgs;
        }
        return \implode(' ', $args);
    }

    /**
     * Returns a list of interactive console form fields for an address.
     *
     * They can dynamically change name, validation or 'required' status, depending on the address country.
     *
     * @return Field[]
     */
    protected function getAddressFormFields()
    {
        $countryRepository = new CountryRepository();
        $addressFormatRepository = new AddressFormatRepository();
        $countryList = $countryRepository->getList();
        $fields = [
            'country' => new OptionsField('Country', [
                'asChoice' => false,
                'includeAsOption' => false,
                'options' => $countryList,
                'normalizer' => function ($value) use ($countryList) {
                    if (isset($countryList[$value])) {
                        return $value;
                    }
                    return \array_search($value, $countryList, true);
                },
            ]),
        ];

        $possibleFields = [
            'premise' => [AddressField::ADDRESS_LINE1, 'Address line 1 ("premise")'],
            'thoroughfare' => [AddressField::ADDRESS_LINE2, 'Address line 2 ("thoroughfare")'],
            'locality' => [AddressField::LOCALITY, 'City or town ("locality")'],
            'dependent_locality' => [AddressField::DEPENDENT_LOCALITY, 'Dependent locality'],
            'administrative_area' => [AddressField::ADMINISTRATIVE_AREA, 'State/county ("administrative area")'],
            'postal_code' => [AddressField::POSTAL_CODE, 'Postal code'],
        ];
        foreach ($possibleFields as $key => $info) {
            list($addressFieldName, $name) = $info;
            $fields[$key] = new Field($name, [
                'includeAsOption' => false,
            ]);
            $field = &$fields[$key];
            $field->set('conditions', [
                'country' => function ($country) use ($addressFieldName, $addressFormatRepository, $field, $key) {
                    $format = $addressFormatRepository->get($country);
                    if (!$format || !\in_array($addressFieldName, $format->getUsedFields())) {
                        return false;
                    }
                    $field->set('required', \in_array($addressFieldName, $format->getRequiredFields(), true));
                    if ($addressFieldName === AddressField::LOCALITY) {
                        if ($localityType = $format->getLocalityType()) {
                            switch ($localityType) {
                                case LocalityType::CITY:
                                    $field->set('name', 'City');
                                    break;
                                case LocalityType::DISTRICT:
                                case LocalityType::SUBURB:
                                    $field->set('name', 'District or suburb');
                                    break;
                                case LocalityType::POST_TOWN:
                                    $field->set('name', 'City or town');
                                    break;
                            }
                        }
                    }
                    if ($addressFieldName === AddressField::ADMINISTRATIVE_AREA) {
                        $map = [
                           AdministrativeAreaType::AREA => 'Area',
                           AdministrativeAreaType::COUNTY => 'County',
                           AdministrativeAreaType::DEPARTMENT => 'Department',
                           AdministrativeAreaType::DISTRICT => 'District',
                           AdministrativeAreaType::DO_SI => 'Do/Si',
                           AdministrativeAreaType::EMIRATE => 'Emirate',
                           AdministrativeAreaType::ISLAND => 'Island',
                           AdministrativeAreaType::OBLAST => 'Oblast',
                           AdministrativeAreaType::PARISH => 'Parish',
                           AdministrativeAreaType::PREFECTURE => 'Prefecture',
                           AdministrativeAreaType::PROVINCE => 'Province',
                           AdministrativeAreaType::STATE => 'State',
                        ];
                        if (isset($map[$format->getAdministrativeAreaType()])) {
                            $field->set('name', $map[$format->getAdministrativeAreaType()]);
                        }
                    }
                    if ($addressFieldName === AddressField::POSTAL_CODE) {
                        if ($postalCodeType = $format->getPostalCodeType()) {
                            switch ($postalCodeType) {
                                case PostalCodeType::PIN:
                                    $field->set('name', 'Pin code');
                                    break;
                                case PostalCodeType::EIR:
                                    $field->set('name', 'Eircode');
                                    break;
                                case PostalCodeType::ZIP:
                                    $field->set('name', 'Zip code');
                                    break;
                                case PostalCodeType::POSTAL:
                                    $field->set('name', 'Postal code');
                                    break;
                            }
                        }
                        $field->set('validator', function ($value) use ($format) {
                            return \preg_match('/' . $format->getPostalCodePattern() . '/i', $value) === 1;
                        });
                    }
                    return true;
                },
            ]);
        }
        return $fields;
    }
}
