<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Activity;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends IntegrationCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:add')
          ->setDescription('Add an integration to the project');
        $this->getForm()->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()->addNoWaitOption();
        $this->addExample(
          'Add an integration with a GitHub repository',
          '--type github --repository myuser/example-repo --token UFpYS1MzQktjNw --fetch-branches 0'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $values = $this->getForm()
          ->resolveOptions($input, $this->stdErr, $this->getHelper('question'));

        $result = $this->getSelectedProject()
                         ->addIntegration($values['type'], $values);

        $integrationId = $result instanceof Activity
          ? $result->payload['integration']['id']
          : $result['_embedded']['entity']['id'];

        $this->stdErr->writeln("Created integration <info>$integrationId</info> (type: {$values['type']})");

        if ($result instanceof Activity && !$input->getOption('no-wait')) {
            $success = ActivityUtil::waitAndLog($result, $this->stdErr);
        }

        $integration = $this->getSelectedProject()->getIntegration($integrationId);
        $output->writeln($this->formatIntegrationData($integration));

        return 0;
    }

}
