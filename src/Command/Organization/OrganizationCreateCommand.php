<?php
namespace Platformsh\Cli\Command\Organization;

use Cocur\Slugify\Slugify;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class OrganizationCreateCommand extends CommandBase
{
    /**
     * The timestamp when we can stop showing a warning about legacy APIs.
     */
    const LEGACY_WARNING_END = 1648771200; // April 2022
    const LEGACY_WARNING = <<<END_WARNING
<options=bold;fg=yellow>Warning</>
Owning more than one organization will cause some older APIs to stop working. If you have scripts that create projects automatically, they need to be updated to use the newer organization-based APIs.
END_WARNING;


    protected $stability = 'BETA';

    protected function configure()
    {
        $this->setName('organization:create')
            ->setDescription('Create a new organization');
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $serviceName = $this->config()->get('service.name');
        $help = <<<END_HELP
Organizations allow you to manage your $serviceName projects, users and billing. Projects are owned by organizations.

You can add other users to your organization, for collaboratively managing the organization as well as its projects and billing information.

Access to individual projects (API and SSH) is managed separately, for now.
END_HELP;
        if ($this->config()->isDirect() && time() < self::LEGACY_WARNING_END) {
            $help .= "\n\n" . self::LEGACY_WARNING;
        }
        $this->setHelp(\wordwrap($help));
    }

    private function getForm()
    {
        return Form::fromArray([
            'label' => new Field('Label', [
                'description' => 'The full name of the organization, e.g. "ACME Inc."',
            ]),
            'name' => new Field('Name', [
                'description' => 'The organization machine name, used for URL paths and similar purposes.',
                'defaultCallback' => function ($values) {
                    return isset($values['label']) ? (new Slugify())->slugify($values['label']) : null;
                }
            ]),
        ]);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $form = $this->getForm();
        if (($name = $input->getOption('name')) && $input->getOption('label') === null) {
            $form->getField('label')->set('default', \ucfirst($name));
        }
        $values = $form->resolveOptions($input, $output, $questionHelper);

        $client = $this->api()->getClient();

        $current = $client->listOrganizationsByOwner($this->api()->getMyUserId());
        if (\count($current) === 1 && ($currentOrg = \reset($current))) {
            /** @var \Platformsh\Client\Model\Organization\Organization $currentOrg */
            if ($currentOrg->name === $values['name']) {
                $this->stdErr->writeln('You already own the organization: ' . $this->api()->getOrganizationLabel($currentOrg));
                return 1;
            }
            $show_legacy_warning = $this->config()->isDirect()
                && (
                    time() < self::LEGACY_WARNING_END
                    || (($created = \strtotime($currentOrg->created_at)) !== false && $created < self::LEGACY_WARNING_END)
                );
            if ($show_legacy_warning) {
                $this->stdErr->writeln('You currently own one organization: ' . $this->api()->getOrganizationLabel($currentOrg));
                $this->stdErr->writeln('');
                $this->stdErr->writeln(\wordwrap(self::LEGACY_WARNING));
                $this->stdErr->writeln('');
            }
        }

        if (!$questionHelper->confirm(\sprintf('Are you sure you want to create a new organization <info>%s</info>?', $values['name']), false)) {
            return 1;
        }

        try {
            $organization = $client->createOrganization($values['name'], $values['label']);
        } catch (BadResponseException $e) {
            if ($e->getResponse() && $e->getResponse()->getStatusCode() === 409) {
                $this->stdErr->writeln(\sprintf('An organization already exists with the same name: <error>%s</error>', $values['name']));
                return 1;
            }
            throw $e;
        }

        $this->stdErr->writeln(sprintf('Created organization %s', $this->api()->getOrganizationLabel($organization)));

        $this->runOtherCommand('organization:info', ['--org' => $organization->name], $this->stdErr);

        return 0;
    }
}
