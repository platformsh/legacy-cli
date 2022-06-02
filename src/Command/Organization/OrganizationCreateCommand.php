<?php
namespace Platformsh\Cli\Command\Organization;

use Cocur\Slugify\Slugify;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CountryService;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationCreateCommand extends OrganizationCommandBase
{
    protected static $defaultName = 'organization:create';
    protected static $defaultDescription = 'Create a new organization';

    private $api;
    private $config;
    private $countryService;
    private $questionHelper;
    private $subCommandRunner;

    public function __construct(
        Api $api,
        Config $config,
        CountryService $countryService,
        QuestionHelper $questionHelper,
        SubCommandRunner $subCommandRunner
    ) {
        $this->api = $api;
        $this->config = $config;
        $this->countryService = $countryService;
        $this->questionHelper = $questionHelper;
        $this->subCommandRunner = $subCommandRunner;
        parent::__construct($config);
    }

    protected function configure()
    {
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $serviceName = $this->config->get('service.name');
        $help = <<<END_HELP
Organizations allow you to manage your $serviceName projects, users and billing. Projects are owned by organizations.

You can add other users to your organization, for collaboratively managing the organization as well as its projects and billing information.

Access to individual projects (API and SSH) is managed separately, for now.
END_HELP;
        $this->setHelp($help);
    }

    private function getForm()
    {
        $countryList = $this->countryService->list();
        return Form::fromArray([
            'label' => new Field('Label', [
                'description' => 'The full name of the organization, e.g. "ACME Inc."',
            ]),
            'name' => new Field('Name', [
                'description' => 'The organization machine name, used for URL paths and similar purposes.',
                'defaultCallback' => function ($values) {
                    return isset($values['label']) ? (new Slugify())->slugify($values['label']) : null;
                },
            ]),
            'country' => new OptionsField('Country', [
                'description' => 'The organization country. Used as the default for the billing address.',
                'options' => $countryList,
                'asChoice' => false,
                'defaultCallback' => function () use ($countryList) {
                    if ($this->api->authApiEnabled()) {
                        $userCountry = $this->api->getUser()->country;
                        if (isset($countryList[$userCountry])) {
                            return $countryList[$userCountry];
                        }
                        return $userCountry ?: null;
                    }
                    return null;
                },
                'normalizer' => function ($value) { return $this->countryService->countryToCode($value); },
                'validator' => function ($countryCode) use ($countryList) {
                    return isset($countryList[$countryCode]) ? true : "Invalid country: $countryCode";
                },
            ]),
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Ensure login before presenting the form.
        $client = $this->api->getClient();

        $form = $this->getForm();
        if (($name = $input->getOption('name')) && $input->getOption('label') === null) {
            $form->getField('label')->set('default', \ucfirst($name));
        }
        $values = $form->resolveOptions($input, $output, $this->questionHelper);

        if (!$this->questionHelper->confirm(\sprintf('Are you sure you want to create a new organization <info>%s</info>?', $values['name']), false)) {
            return 1;
        }

        try {
            $organization = $client->createOrganization($values['name'], $values['label'], $values['country']);
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 409) {
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
