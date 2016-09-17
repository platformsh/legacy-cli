<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Util\ActivityUtil;
use Platformsh\Client\Model\Integration;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IntegrationAddCommand extends IntegrationCommandBase
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

        /** @var Integration $integration */
        $integration = $result->getEntity();

        $this->stdErr->writeln("Created integration <info>$integration->id</info> (type: {$values['type']})");

        $success = true;
        if (!$input->getOption('no-wait')) {
            $success = ActivityUtil::waitMultiple($result->getActivities(), $this->stdErr, $this->getSelectedProject());
        }

        $this->displayIntegration($integration, $input, $this->stdErr);

        return $success ? 0 : 1;
    }

}
