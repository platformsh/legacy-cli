<?php
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

    private function getForm(): Form
    {
        $countryList = $this->countryService->listCountries();
        return Form::fromArray([
            'label' => new Field('Label', [
                'description' => 'The full name of the organization, e.g. "ACME Inc."',
            ]),
            'name' => new Field('Name', [
                'description' => 'The organization machine name, used for URL paths and similar purposes.',
                'defaultCallback' => fn($values) => isset($values['label']) ? (new Slugify())->slugify($values['label']) : null,
            ]),
            'country' => new OptionsField('Country', [
                'description' => 'The organization country. Used as the default for the billing address.',
                'options' => $countryList,
                'asChoice' => false,
                'defaultCallback' => fn() => $this->api->getUser()->country ?: null,
                'normalizer' => $this->countryService->countryToCode(...),
                'validator' => fn($countryCode) => isset($countryList[$countryCode]) ? true : "Invalid country: $countryCode",
            ]),
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        // Ensure login before presenting the form.
        $client = $this->api->getClient();

        $questionHelper = $this->questionHelper;
        $form = $this->getForm();
        if (($name = $input->getOption('name')) && $input->getOption('label') === null) {
            $form->getField('label')->set('default', \ucfirst((string) $name));
        }
        $values = $form->resolveOptions($input, $output, $questionHelper);

        if (!$questionHelper->confirm(\sprintf('Are you sure you want to create a new organization <info>%s</info>?', $values['name']), false)) {
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
