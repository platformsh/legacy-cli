<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command\Variable;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Service\ActivityService;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\Selector;
use Platformsh\Cli\Service\VariableService;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Client\Model\ProjectLevelVariable;
use Platformsh\Client\Model\Variable;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class VariableCreateCommand extends CommandBase
{
    /** @var Form */
    private $form;

    protected static $defaultName = 'variable:create';

    private $activityService;
    private $api;
    private $config;
    private $questionHelper;
    private $selector;
    private $variableService;

    public function __construct(
        ActivityService $activityService,
        Api $api,
        Config $config,
        QuestionHelper $questionHelper,
        Selector $selector,
        VariableService $variableService
    ) {
        $this->activityService = $activityService;
        $this->api = $api;
        $this->config = $config;
        $this->questionHelper = $questionHelper;
        $this->selector = $selector;
        $this->variableService = $variableService;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setDescription('Create a variable')
            ->addArgument('name', InputArgument::OPTIONAL, 'The variable name');
        $this->form = Form::fromArray($this->variableService->getFields());

        $definition = $this->getDefinition();
        $this->form->configureInputDefinition($definition);
        $this->selector->addProjectOption($definition);
        $this->selector->addEnvironmentOption($definition);
        $this->activityService->configureInput($definition);
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $selection = $this->selector->getSelection($input, true);

        // Set the default for the environment form field.
        if ($environmentField = $this->form->getField('environment')) {
            if ($selection->hasEnvironment()) {
                $environmentField->set('default', $selection->getEnvironment()->id);
            }
            $project = $selection->getProject();
            $environmentField->set('optionsCallback', function () use ($project) {
                return array_keys($this->api->getEnvironments($project));
            });
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
        if (($name = $input->getOption('name'))
            && $this->variableService->getExistingVariable($selection, $name, $input->getOption('level'), false)) {
            $this->stdErr->writeln('The variable already exists: <error>' . $name . '</error>');

            $executable = $this->config->get('application.executable');
            $escapedName = $this->variableService->escapeShellArg($name);
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

        try {
            $values = $this->form->resolveOptions($input, $output, $this->questionHelper);
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
                $values['name'] = rtrim($values['prefix'], ':') . ':' .  $values['name'];
            }
            unset($values['prefix']);
        }

        if (isset($values['environment'])) {
            if (!$selection->hasEnvironment()) {
                $environment = $this->api->getEnvironment($values['environment'], $selection->getProject());
                if (!$environment) {
                    throw new InvalidArgumentException('Specified environment not found: ' . $values['environment']);
                }
                $selection = new Selection($selection->getProject(), $environment);
            }
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

        $project = $selection->getProject();

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

                $environment = $selection->getEnvironment();
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
                    $this->api->getProjectLabel($project)
                ));

                $result = ProjectLevelVariable::create($values, $project->getUri() . '/variables', $this->api->getHttpClient());
                break;

            default:
                throw new \RuntimeException('Invalid level: ' . $level);
        }

        $this->variableService->displayVariable($result->getEntity());

        $success = true;
        if (!$result->countActivities() || $level === 'project') {
            $this->activityService->redeployWarning();
        } elseif ($this->activityService->shouldWait($input)) {
            $success = $this->activityService->waitMultiple($result->getActivities(), $project);
        }

        return $success ? 0 : 1;
    }
}
