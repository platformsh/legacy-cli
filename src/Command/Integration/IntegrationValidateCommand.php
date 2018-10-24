<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationValidateCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:validate')
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.')
            ->setDescription('Validate an existing integration');
        $this->addProjectOption();
        $this->setHelp("This command allows you to check whether an integration is valid.

An exit code of 0 means the integration is valid, while 4 means it is invalid.
Any other exit code indicates an unexpected error.

Integrations are validated automatically on creation and on update. However,
because they involve external resources, it is possible for a valid integration
to become invalid. For example, an access token may be revoked, or an external
repository may be deleted."
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $project = $this->getSelectedProject();

        $id = $input->getArgument('id');
        if (!$id && !$input->isInteractive()) {
            $this->stdErr->writeln('An integration ID is required.');

            return 1;
        } elseif (!$id) {
            $integrations = $project->getIntegrations();
            if (empty($integrations)) {
                $this->stdErr->writeln('No integrations found.');

                return 1;
            }
            /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
            $questionHelper = $this->getService('question_helper');
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                $integration = $this->api()->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return 1;
            }
        }

        $this->stdErr->writeln(sprintf(
            'Validating the integration <info>%s</info> (type: %s)...',
            $integration->id,
            $integration->type
        ));

        try {
            $errors = $integration->validate();
        } catch (OperationUnavailableException $e) {
            $this->stdErr->writeln('This integration does not support validation.');

            return 1;
        }
        if (empty($errors)) {
            $this->stdErr->writeln('The integration is valid.');

            return 0;
        }

        $this->stdErr->writeln('');

        $this->listValidationErrors($errors, $output);

        // The exit code for an invalid integration (see the command help).
        return 4;
    }
}
