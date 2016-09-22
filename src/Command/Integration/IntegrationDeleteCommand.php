<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\ActivityUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationDeleteCommand extends CommandBase
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
        $this->addProjectOption()->addNoWaitOption();
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

        $type = $integration->getProperty('type');
        $confirmText = "Delete the integration <info>$id</info> (type: $type)?";
        if (!$this->getHelper('question')->confirm($confirmText)) {
            return 1;
        }

        $result = $integration->delete();

        $this->stdErr->writeln("Deleted integration <info>$id</info>");

        if (!$input->getOption('no-wait')) {
            ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $this->getSelectedProject());
        }

        return 0;
    }

}
