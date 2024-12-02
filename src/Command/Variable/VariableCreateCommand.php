<?php

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'variable:create', description: 'Create a variable')]
class VariableCreateCommand extends VariableCommandBase
{
    private ?Form $form = null;
    public function __construct(private readonly ActivityMonitor $activityMonitor, private readonly Api $api, private readonly Config $config, private readonly QuestionHelper $questionHelper)
    {
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The variable name')
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'Update the variable if it already exists');
        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());
        $this->addProjectOption()
            ->addEnvironmentOption()
            ->addWaitOptions();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->validateInput($input, true);

        // Merge the 'name' argument with the --name option.
        if ($input->getArgument('name')) {
            if ($input->getOption('name')) {
                $this->stdErr->writeln('You cannot use both the <error>name</error> argument and <error>--name</error> option.');

                return 1;
            }
            $input->setOption('name', $input->getArgument('name'));
        }

        // Check whether the variable already exists, if a name is provided.
        if (($name = $input->getOption('name'))) {
            if (($prefix = $input->getOption('prefix')) && $prefix !== 'none') {
                $name = rtrim((string) $prefix, ':') . ':' .  $name;
            }
            $existing = $this->getExistingVariable($name, $this->getRequestedLevel($input), false);
            if ($existing) {
                if (!$input->getOption('update')) {
                    $this->stdErr->writeln('The variable already exists: <error>' . $name . '</error>');

                    $executable = $this->config->get('application.executable');
                    $escapedName = $this->escapeShellArg($name);
                    $this->stdErr->writeln('');
                    $this->stdErr->writeln(sprintf(
                        'To view the variable, use: <comment>%s variable:get %s</comment>',
                        $executable,
                        $escapedName
                    ));
                    $this->stdErr->writeln(
                        'To skip this check, use the <comment>--update</comment> (<comment>-u</comment>) option.'
                    );

                    return 1;
                }
                $arguments = [
                    '--allow-no-change' => true,
                    '--project' => $this->getSelectedProject()->id,
                    'name' => $name,
                ];
                if ($this->hasSelectedEnvironment()) {
                    $arguments['--environment'] = $this->getSelectedEnvironment()->id;
                }
                foreach ($this->form->getFields() as $field) {
                    $argName = '--' . $field->getOptionName();
                    $value = $field->getValueFromInput($input, false);
                    if ($value !== null && !in_array($argName, ['--name', '--level', '--prefix'])) {
                        $arguments[$argName] = $value;
                    }
                }
                return $this->runOtherCommand('variable:update', $arguments, $output);
            }
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->questionHelper;

        try {
            $values = $this->form->resolveOptions($input, $output, $questionHelper);
        } catch (ConditionalFieldException $e) {
            $previousValues = $e->getPreviousValues();
            $field = $e->getField();
            $conditions = $field->getConditions();
            if (isset($previousValues['level']) && isset($conditions['level']) && is_string($conditions['level']) && $previousValues['level'] !== $conditions['level']) {
                $this->stdErr->writeln(\sprintf(
                    'The option <error>--%s</error> can only be used for variables at the <comment>%s</comment> level, not at the <comment>%s</comment> level.',
                    $field->getOptionName(),
                    $conditions['level'],
                    $previousValues['level']
                ));
                return 1;
            }
            throw $e;
        }

        if (isset($values['prefix']) && isset($values['name'])) {
            if ($values['prefix'] !== 'none') {
                $values['name'] = rtrim((string) $values['prefix'], ':') . ':' .  $values['name'];
            }
            unset($values['prefix']);
        }

        if (isset($values['environment'])) {
            $this->selectEnvironment($values['environment']);
            unset($values['environment']);
        }

        // Validate the is_json setting against the value.
        if (isset($values['value']) && !empty($values['is_json'])) {
            if (json_decode((string) $values['value']) === null && json_last_error()) {
                $this->stdErr->writeln('The value is not valid JSON: <error>' . $values['value'] . '</error>');

                return 1;
            }
        }

        // Validate the variable name for "env:"-prefixed variables.
        $envPrefixLength = 4;
        if (substr((string) $values['name'], 0, $envPrefixLength) === 'env:'
            && !preg_match('/^[a-z][a-z0-9_]*$/i', substr((string) $values['name'], $envPrefixLength))) {
            $this->stdErr->writeln('The environment variable name is invalid: <error>' . substr((string) $values['name'], $envPrefixLength) . '</error>');
            $this->stdErr->writeln('Environment variable names can only contain letters (A-Z), digits (0-9), and underscores. The first character must be a letter.');

            return 1;
        }

        $level = $values['level'];
        unset($values['level']);

        $project = $this->getSelectedProject();

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

                try {
                    $result = Variable::create($values, $environment->getLink('#manage-variables'), $this->api->getHttpClient());
                } catch (BadResponseException $e) {

                    // Explain the error with visible_build on older API versions.
                    if ($e->getResponse() && $e->getResponse()->getStatusCode() === 400 && !empty($values['visible_build'])) {
                        $info = $project->systemInformation();
                        if (\version_compare($info->version, '12', '<')) {
                            $this->stdErr->writeln('');
                            $this->stdErr->writeln('This project does not yet support build-visible environment variables.');
                            $this->stdErr->writeln(\sprintf('The project API version is <comment>%s</comment> but version 12 would be required.', $info->version));
                            return 1;
                        }
                    }

                    throw $e;
                }
                break;

            case 'project':
                if ($project->getVariable($values['name'])) {
                    $this->stdErr->writeln(sprintf(
                        'The variable <error>%s</error> already exists on the project %s',
                        $values['name'],
                        $this->api->getProjectLabel($project, 'error')
                    ));

                    return 1;
                }
                $this->stdErr->writeln(sprintf(
                    'Creating variable <info>%s</info> on the project %s',
                    $values['name'],
                    $this->api->getProjectLabel($project, 'info')
                ));

                $result = ProjectLevelVariable::create($values, $project->getUri() . '/variables', $this->api->getHttpClient());
                break;

            default:
                throw new \RuntimeException('Invalid level: ' . $level);
        }

        $this->displayVariable($result->getEntity());

        $success = true;
        if (!$result->countActivities() || $level === self::LEVEL_PROJECT) {
            $this->api->redeployWarning();
        } elseif ($this->shouldWait($input)) {
            /** @var ActivityMonitor $activityMonitor */
            $activityMonitor = $this->activityMonitor;
            $success = $activityMonitor->waitMultiple($result->getActivities(), $this->getSelectedProject());
        }

        return $success ? 0 : 1;
    }
}
