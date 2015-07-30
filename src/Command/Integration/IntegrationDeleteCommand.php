<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\PlatformCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:delete')
          ->addArgument('id', InputArgument::REQUIRED, 'The integration ID')
          ->setDescription('Delete an integration from a project');
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $id = $input->getArgument('id');

        $integration = $this->getSelectedProject()
                            ->getIntegration($id);
        if (!$integration) {
            $this->stdErr->writeln("Integration not found: <error>$id</error>");

            return 1;
        }

        if (!$integration->operationAvailable('delete')) {
            $this->stdErr->writeln("The integration <error>$id</error> cannot be deleted");

            return 1;
        }

        $type = $integration->getProperty('type');
        $confirmText = "Delete the integration <info>$id</info> (type: $type)?";
        if (!$this->getHelper('question')
                  ->confirm($confirmText, $input, $this->stdErr)
        ) {
            return 1;
        }

        $integration->delete();

        $this->stdErr->writeln("Deleted integration <info>$id</info>");

        return 0;
    }

}
