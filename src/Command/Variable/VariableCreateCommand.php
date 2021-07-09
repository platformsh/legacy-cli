<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableCreateCommand extends VariableCommandBase
{
    /** @var Form */
    private $form;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('variable:create')
            ->setDescription('Create a variable')
            ->addArgument('name', InputArgument::OPTIONAL, 'The variable name');
        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);

        // Set the default for the environment form field.
        if ($this->hasSelectedEnvironment() && ($field = $this->form->getField('environment'))) {
            $field->set('default', $this->getSelectedEnvironment()->id);
        }

        // Merge the 'name' argument with the --name option.
        if ($input->getArgument('name')) {
            if ($input->getOption('name')) {
                $this->stdErr->writeln('You cannot use both the <error>name</error> argument and <error>--name</error> option.');

                return 1;
            }
            $input->setOption('name', $input->getArgument('name'));
        }

        // Check whether the variable already exists, if a name is provided.
        if (($name = $input->getOption('name')) && $this->getExistingVariable($name, $input->getOption('level'), false)) {
            $this->stdErr->writeln('The variable already exists: <error>' . $name . '</error>');

            $executable = $this->config()->get('application.executable');
            $escapedName = $this->escapeShellArg($name);
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'To view the variable, use: <comment>%s variable:get %s</comment>',
                $executable,
                $escapedName
            ));
            $this->stdErr->writeln(sprintf(
                'To update the variable, use: <comment>%s variable:update %s</comment>',
                $executable,
                $escapedName
            ));

            return 1;
        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $values = $this->form->resolveOptions($input, $output, $questionHelper);

        if (isset($values['prefix']) && isset($values['name'])) {
            if ($values['prefix'] !== 'none') {
                $values['name'] = rtrim($values['prefix'], ':') . ':' .  $values['name'];
            }
            unset($values['prefix']);
        }

        if (isset($values['environment'])) {
            $this->selectEnvironment($values['environment']);
            unset($values['environment']);
        }

        // Validate the is_json setting against the value.
        if (isset($values['value']) && !empty($values['is_json'])) {
            if (json_decode($values['value']) === null && json_last_error()) {
                $this->stdErr->writeln('The value is not valid JSON: <error>' . $values['value'] . '</error>');

                return 1;
            }
        }

        // Validate the variable name for "env:"-prefixed variables.
        $envPrefixLength = 4;
        if (substr($values['name'], 0, $envPrefixLength) === 'env:'
            && !preg_match('/^[a-z][a-z0-9_]*$/i', substr($values['name'], $envPrefixLength))) {
            $this->stdErr->writeln('The environment variable name is invalid: <error>' . substr($values['name'], $envPrefixLength) . '</error>');
            $this->stdErr->writeln('Environment variable names can only contain letters (A-Z), digits (0-9), and underscores. The first character must be a letter.');

            return 1;
        }

        $level = $values['level'];
        unset($values['level']);

        switch ($level) {
            case 'environment':
                // Unset visible_build and visible_runtime if they are already the API's defaults.
                // This is to provide backwards compatibility with older API versions.
                // @todo remove when API version 12 is everywhere
                if (isset($values['visible_build']) && $values['visible_build'] === false) {
                    unset($values['visible_build']);
                }
                if (isset($values['visible_runtime']) && $values['visible_runtime'] === true) {
                    unset($values['visible_runtime']);
                }

                $environment = $this->getSelectedEnvironment();
                if ($environment->getVariable($values['name'])) {
                    $this->stdErr->writeln(sprintf(
                        'The variable <error>%s</error> already exists on the environment <error>%s</error>',
                        $values['name'],
                        $environment->id
                    ));

                    return 1;
                }
                $this->stdErr->writeln(sprintf(
                    'Creating variable <info>%s</info> on the environment <info>%s</info>', $values['name'], $environment->id));
                $result = Variable::create($values, $environment->getLink('#manage-variables'), $this->api()->getHttpClient());
                break;

            case 'project':
                $project = $this->getSelectedProject();
                if ($project->getVariable($values['name'])) {
                    $this->stdErr->writeln(sprintf(
                        'The variable <error>%s</error> already exists on the project %s',
                        $values['name'],
                        $this->api()->getProjectLabel($project, 'error')
                    ));

                    return 1;
                }
                $this->stdErr->writeln(sprintf(
                    'Creating variable <info>%s</info> on the project %s',
                    $values['name'],
                    $this->api()->getProjectLabel($project, 'info')
                ));

                $result = ProjectLevelVariable::create($values, $project->getUri() . '/variables', $this->api()->getHttpClient());
                break;

            default:
                throw new \RuntimeException('Invalid level: ' . $level);
        }

        $this->displayVariable($result->getEntity());

        $success = true;
        if (!$result->countActivities() || $level === self::LEVEL_PROJECT) {
            $this->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var \Platformsh\Cli\Service\ActivityMonitor $activityMonitor */
            $activityMonitor = $this->getService('activity_monitor');
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
