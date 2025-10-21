<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Organization;

use Platformsh\Cli\Service\CountryService;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Cocur\Slugify\Slugify;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'organization:create', description: 'Create a new organization')]
class OrganizationCreateCommand extends OrganizationCommandBase
{
    public function __construct(private readonly Api $api, private readonly Config $config, private readonly CountryService $countryService, private readonly QuestionHelper $questionHelper, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }
    protected function configure(): void
    {
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $serviceName = $this->config->getStr('service.name');
        $help = <<<END_HELP
            Organizations allow you to manage your $serviceName projects, users and billing. Projects are owned by organizations.

            You can add other users to your organization, for collaboratively managing the organization as well as its projects and billing information.

            Access to individual projects (API and SSH) is managed separately, for now.
            END_HELP;
        $this->setHelp($help);
    }

    private function getForm(): Form
    {
        $countryList = $this->countryService->listCountries();
        $fields = [];
        $fields['label'] = new Field('Label', [
            'description' => 'The full name of the organization, e.g. "ACME Inc."',
        ]);
        if ($orgTypes = $this->config->get('api.organization_types')) {
            $options = [];
            foreach ((array) $orgTypes as $type) {
                $options[$type] = ucfirst($type);
            }
            $fields['type'] = new OptionsField('Type', [
                'description' => 'The organization type.',
                'options' => $options,
                'default' => $this->config->getWithDefault('api.default_organization_type', key($options)),
            ]);
        }
        $fields['name'] = new Field('Name', [
            'description' => 'The organization machine name, used for URL paths and similar purposes.',
            'defaultCallback' => function ($values) {
                return isset($values['label']) ? (new Slugify())->slugify($values['label']) : null;
            },
        ]);
        $fields['country'] = new OptionsField('Country', [
            'description' => 'The organization country. Used as the default for the billing address.',
            'options' => $countryList,
            'asChoice' => false,
            'defaultCallback' => function () {
                return $this->api->getUser()->country ?: null;
            },
            'normalizer' => function ($value) { return $this->countryService->countryToCode($value); },
            'validator' => function ($countryCode) use ($countryList) {
                return isset($countryList[$countryCode]) ? true : "Invalid country: $countryCode";
            },
        ]);

        return Form::fromArray($fields);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure login before presenting the form.
        $client = $this->api->getClient();
        $form = $this->getForm();
        if (($name = $input->getOption('name')) && $input->getOption('label') === null) {
            $form->getField('label')->set('default', \ucfirst((string) $name));
        }
        $values = $form->resolveOptions($input, $output, $this->questionHelper);

        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to create a new organization <info>%s</info>?', $values['name']), false)) {
            return 1;
        }

        try {
            $organization = $client->createOrganization(
                $values['name'],
                $values['label'],
                $values['country'],
                '',
                $values['type'] ?? ''
            );
        } catch (BadResponseException $e) {
            if ($e->getResponse()->getStatusCode() === 409) {
                $this->stdErr->writeln(\sprintf('An organization already exists with the same name: <error>%s</error>', $values['name']));
                return 1;
            }
            throw $e;
        }

        $this->stdErr->writeln(sprintf('Created organization %s', $this->api->getOrganizationLabel($organization)));

        $this->subCommandRunner->run('organization:info', ['--org' => $organization->name], $this->stdErr);

        return 0;
    }
}
