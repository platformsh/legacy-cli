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

        $activity = $this->getSelectedProject()
                         ->addIntegration($values['type'], $values);

        if ($activity instanceof Activity) {
            $data = $activity->payload['integration'];
            $integrationId = $data['id'];
            $this->stdErr->writeln("Integration <info>$integrationId</info> created for <info>{$values['type']}</info>");
            if (!$input->getOption('no-wait')) {
                ActivityUtil::waitAndLog($activity, $this->stdErr);
            }
        }
        else {
            $this->stdErr->writeln("Integration created for <info>{$values['type']}</info>");
        }

        return 0;
    }

}
