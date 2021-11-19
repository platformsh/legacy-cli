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
    protected $stability = 'BETA';

    protected function configure()
    {
        $this->setName('organization:create')
            ->setDescription('Create a new organization');
        $this->getForm()->configureInputDefinition($this->getDefinition());
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
            if ($currentOrg->name === $values['name']) {
                $this->stdErr->writeln('You already own the organization: ' . $this->api()->getOrganizationLabel($currentOrg));
                return 1;
            }
            $this->stdErr->writeln('You currently own one organization: ' . $this->api()->getOrganizationLabel($currentOrg));
            $this->stdErr->writeln('');
            $this->stdErr->writeln('<options=bold;fg=yellow>Warning</>');
            $this->stdErr->writeln('Owning more than one organization will cause some older APIs to stop working.');
            $this->stdErr->writeln('If you have scripts that create projects automatically, they need to be updated to specify an organization ID.');
            $this->stdErr->writeln('');
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
