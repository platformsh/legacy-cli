<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Console\AdaptiveTableCell;
use Platformsh\Cli\Service\Table;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationListCommand extends IntegrationCommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('integration:list')
            ->setAliases(['integrations'])
            ->setDescription('View a list of project integration(s)');
        Table::configureInput($this->getDefinition());
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $integrations = $this->getSelectedProject()
                        ->getIntegrations();
        if (!$integrations) {
            $this->stdErr->writeln('No integrations found');

            return 1;
        }

        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        $header = ['ID', 'Type', 'Summary'];
        $rows = [];

        foreach ($integrations as $integration) {
            $rows[] = [
                new AdaptiveTableCell($integration->id, ['wrap' => false]),
                $integration->type,
                $this->getIntegrationSummary($integration),
            ];
        }

        $table->render($rows, $header);

        $executable = $this->config()->get('application.executable');
        $this->stdErr->writeln('');
        $this->stdErr->writeln('View integration details with: <info>' . $executable . ' integration:get [id]</info>');
        $this->stdErr->writeln('');
        $this->stdErr->writeln('Add a new integration with: <info>' . $executable . ' integration:add</info>');
        $this->stdErr->writeln('Delete an integration with: <info>' . $executable . ' integration:delete [id]</info>');

        return 0;
    }

    /**
     * @param Integration $integration
     *
     * @return string
     */
    protected function getIntegrationSummary(Integration $integration)
    {
        $details = $integration->getProperties();
        unset($details['id'], $details['type']);

        switch ($integration->type) {
            case 'github':
            case 'bitbucket':
                $summary = sprintf('Repository: %s', $details['repository']);
                if ($integration->hasLink('#hook')) {
                    $summary .= "\n" . sprintf('Hook URL: %s', $integration->getLink('#hook'));
                }
                break;

            case 'hipchat':
                $summary = sprintf('Room ID: %s', $details['room']);
                break;

            case 'webhook':
                $summary = sprintf('URL: %s', $details['url']);
                break;

            default:
                $summary = json_encode($details);
        }

        if (strlen($summary) > 240) {
            $summary = substr($summary, 0, 237) . '...';
        }

        return $summary;
    }
}
