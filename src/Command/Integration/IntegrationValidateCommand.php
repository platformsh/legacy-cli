<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Selector\Selector;
use Platformsh\Client\Exception\OperationUnavailableException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'integration:validate', description: 'Validate an existing integration')]
class IntegrationValidateCommand extends IntegrationCommandBase
{
    public function __construct(private readonly Selector $selector)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'An integration ID. Leave blank to choose from a list.');
        $this->selector->addProjectOption($this->getDefinition());
        $this->addCompleter($this->selector);
        $this->setHelp(
            <<<EOF
                This command allows you to check whether an integration is valid.

                An exit code of 0 means the integration is valid, while 4 means it is invalid.
                Any other exit code indicates an unexpected error.

                Integrations are validated automatically on creation and on update. However,
                because they involve external resources, it is possible for a valid integration
                to become invalid. For example, an access token may be revoked, or an external
                repository may be deleted.
                EOF,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selection = $this->selector->getSelection($input);

        $project = $selection->getProject();

        $integration = $this->selectIntegration($project, $input->getArgument('id'), $input->isInteractive());
        if (!$integration) {
            return 1;
        }

        $this->stdErr->writeln(sprintf(
            'Validating the integration <info>%s</info> (type: %s)...',
            $integration->id,
            $integration->type,
        ));

        try {
            $errors = $integration->validate();
        } catch (OperationUnavailableException) {
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
