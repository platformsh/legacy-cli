<?php

declare(strict_types=1);

namespace Platformsh\Cli\Selector;

use Platformsh\Cli\Console\CompleterInterface;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Model\Host\LocalHost;
use Platformsh\Cli\Model\RemoteContainer\BrokenEnv;
use Platformsh\Cli\Model\RemoteContainer\Worker;
use Platformsh\Cli\Model\RemoteContainer\App;
use GuzzleHttp\Exception\BadResponseException;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Exception\NoOrganizationsException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Model\Host\HostInterface;
use Platformsh\Cli\Model\RemoteContainer\RemoteContainerInterface;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\HostFactory;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\BasicProjectInfo;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Completion\Suggestion;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Exception\InvalidArgumentException as ConsoleInvalidArgumentException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;

/**
 * Service allowing the user to select the current project, environment, app, and organization.
 */
class Selector implements CompleterInterface
{
    public const DEFAULT_ENVIRONMENT_CODE = '.';

    private readonly OutputInterface $stdErr;

    private Project|false|null $currentProject = null;
    private string|false|null $projectRoot = null;

    /**
     * The ID of the last printed project.
     */
    private ?string $printedProject = null;

    /**
     * The ID of the last printed environment.
     */
    private ?string $printedEnvironment = null;

    public function __construct(
        private readonly Identifier $identifier,
        private readonly Config $config,
        private readonly Api $api,
        private readonly HostFactory $hostFactory,
        private readonly LocalProject $localProject,
        private readonly QuestionHelper $questionHelper,
        private readonly Git $git,
        OutputInterface $output,
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Prints a message if debug output is enabled.
     *
     * @todo refactor this
     *
     * @param string $message
     */
    private function debug(string $message): void
    {
        $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Processes and validates input to select the project, environment and app.
     *
     * @param InputInterface $input
     * @param ?SelectorConfig $config
     *
     * @return Selection
     */
    public function getSelection(InputInterface $input, ?SelectorConfig $config = null): Selection
    {
        $config = $config ?: new SelectorConfig();

        // Determine whether the localhost can be used.
        $envPrefix = $this->config->getStr('service.env_prefix');
        $allowLocalHost = $config->allowLocalHost && !LocalHost::conflictsWithCommandLineOptions($input, $envPrefix);

        // If the user is not logged in, then return an empty selection.
        if ($allowLocalHost && !$config->requireApiOnLocal && !$this->api->isLoggedIn()) {
            return new Selection($config);
        }

        $projectId = $input->hasOption('project') ? $input->getOption('project') : null;
        $projectHost = $input->hasOption('host') ? $input->getOption('host') : null;
        $environmentId = null;

        // Identify the project.
        if ($projectId !== null) {
            $result = $this->identifier->identify($projectId);
            $projectId = $result['projectId'];
            $projectHost = $projectHost ?: $result['host'];
            $environmentId = $result['environmentId'];
        }

        // Load the project ID from an environment variable, if available.
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId,
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Select the project.
        $project = $this->selectProject($input, $config, $projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        $environment = null;

        $envArgName = $config->envArgName;
        if ($input->hasArgument($envArgName)
            && $input->getArgument($envArgName) !== null
            && $input->getArgument($envArgName) !== []) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        $envArgName,
                        $envOptionName,
                    ),
                );
            }
            $argument = $input->getArgument($envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $environment = $this->selectEnvironment($input, $project, $config, $argument);
            }
        } elseif ($input->hasOption($envOptionName)) {
            if ($input->getOption($envOptionName) !== null) {
                $environmentId = $input->getOption($envOptionName);
            }
            $environment = $this->selectEnvironment($input, $project, $config, $environmentId);
        }

        // Select the app.
        $appName = null;
        $remoteContainer = null;
        if ($input->hasOption('app')) {
            if ($input->getOption('app')) {
                $appName = (string) $input->getOption('app');
            } elseif (isset($result['appId'])) {
                // An app ID might be provided from the parsed project URL.
                $appName = $result['appId'];
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $appName,
                ));
            } elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                // Or from an environment variable.
                $appName = (string) getenv($envPrefix . 'APPLICATION_NAME');
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $appName,
                ), OutputInterface::VERBOSITY_VERBOSE);
            }

            $remoteContainer = $this->selectRemoteContainer($environment, $input, $appName);
        }

        $selection = new Selection($config, $project, $environment, $appName, $remoteContainer);
        if ($this->stdErr->isVerbose()) {
            $this->ensurePrintedSelection($selection);
        }

        return $selection;
    }

    /**
     * Ensures the selection is printed.
     *
     * @param bool $blankLine Append an extra newline after the message, if any is printed.
     */
    public function ensurePrintedSelection(Selection $selection, bool $blankLine = false): void
    {
        $outputAnything = false;
        if ($selection->hasProject() && $this->printedProject !== $selection->getProject()->id) {
            $this->stdErr->writeln('Selected project: ' . $this->api->getProjectLabel($selection->getProject()));
            $outputAnything = true;
            $this->printedProject = $selection->getProject()->id;
        }
        if ($selection->hasEnvironment() && $this->printedEnvironment !== $selection->getEnvironment()->id) {
            $this->stdErr->writeln('Selected environment: ' . $this->api->getEnvironmentLabel($selection->getEnvironment()));
            $outputAnything = true;
            $this->printedEnvironment = $selection->getEnvironment()->id;
        }
        if ($blankLine && $outputAnything) {
            $this->stdErr->writeln('');
        }
    }

    public function getHostFromSelection(InputInterface $input, Selection $selection): HostInterface
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        $allowLocalHost = $selection->config->allowLocalHost && !LocalHost::conflictsWithCommandLineOptions($input, $envPrefix);
        if ($allowLocalHost) {
            $this->debug('Selected host: localhost');
            return $this->hostFactory->local();
        }

        $remoteContainer = $selection->getRemoteContainer();
        $instanceId = $input->hasOption('instance') ? $input->getOption('instance') : null;
        if ($input->hasOption('instance') && $instanceId !== null) {
            $instances = $selection->getEnvironment()->getSshInstanceURLs($remoteContainer->getName());
            if ((!empty($instances) || $instanceId !== '0') && !isset($instances[$instanceId])) {
                throw new ConsoleInvalidArgumentException("Instance not found: $instanceId. Available instances: " . implode(', ', array_keys($instances)));
            }
        }

        $sshUrl = $remoteContainer->getSshUrl($instanceId);
        $this->debug('Selected host: ' . $sshUrl);
        return $this->hostFactory->remote($sshUrl, $selection->getEnvironment());
    }

    public function isProjectCurrent(Project $project): bool
    {
        $current = $this->getCurrentProject(true);

        return $current && $current->id === $project->id;
    }

    /**
     * Selects the project for the user, based on input or the environment.
     */
    private function selectProject(InputInterface $input, SelectorConfig $config, ?string $projectId = null, ?string $host = null): Project
    {
        if (!empty($projectId)) {
            $project = $this->api->getProject($projectId, $host);
            if (!$project) {
                throw new InvalidArgumentException($this->getProjectNotFoundMessage($projectId));
            }

            $this->debug('Selected project: ' . $project->id);

            return $project;
        }

        if ($config->detectCurrentEnv) {
            $currentProject = $this->getCurrentProject();
            if ($currentProject) {
                return $currentProject;
            }
        }

        if ($input->isInteractive()) {
            $myProjects = $this->api->getMyProjects();
            if (count($myProjects) > 0) {
                $this->debug('No project specified: offering a choice...');
                $projectId = $this->offerProjectChoice($myProjects, $config);
                $project = $this->api->getProject($projectId);
                if (!$project) {
                    throw new \RuntimeException('Failed to load project: ' . $projectId);
                }

                return $project;
            }
        }

        if ($config->detectCurrentEnv) {
            throw new RootNotFoundException(
                "Could not determine the current project."
                    . "\n\nSpecify it using --project, or go to a project directory.",
            );
        }

        throw new InvalidArgumentException('You must specify a project.');
    }

    /**
     * Format an error message about a not-found project.
     *
     * @param string $projectId
     *
     * @return string
     */
    private function getProjectNotFoundMessage(string $projectId): string
    {
        $message = 'Specified project not found: ' . $projectId;
        if ($projectInfos = $this->api->getMyProjects()) {
            $message .= "\n\nYour projects are:";
            $limit = 8;
            foreach (array_slice($projectInfos, 0, $limit) as $info) {
                $message .= "\n    " . $info->id;
                if ($info->title !== '') {
                    $message .= ' - ' . $info->title;
                }
            }
            if (count($projectInfos) > $limit) {
                $message .= "\n    ...";
                $message .= "\n\n    List projects with: " . $this->config->getStr('application.executable') . ' projects';
            }
        }

        return $message;
    }

    /**
     * Select the current environment for the user.
     *
     * @param InputInterface $input
     * @param Project $project
     *   The project, or null if no project is selected.
     * @param SelectorConfig $config
     * @param string|null $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     *
     * @return Environment|null
     * @throws \Exception
     */
    private function selectEnvironment(InputInterface $input, Project $project, SelectorConfig $config, ?string $environmentId = null): ?Environment
    {
        $envPrefix = $this->config->getStr('service.env_prefix');
        if ($environmentId === null && getenv($envPrefix . 'BRANCH')) {
            $environmentId = getenv($envPrefix . 'BRANCH');
            $this->stdErr->writeln(sprintf(
                'Environment ID read from environment variable %s: %s',
                $envPrefix . 'BRANCH',
                $environmentId,
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        if ($environmentId !== null) {
            if ($environmentId === self::DEFAULT_ENVIRONMENT_CODE) {
                $this->stdErr->writeln(sprintf('Selecting default environment (indicated by <info>%s</info>)', $environmentId));
                $environments = $this->api->getEnvironments($project);
                $environment = $this->api->getDefaultEnvironment($environments, $project, true);
                if (!$environment) {
                    throw new \RuntimeException('Default environment not found');
                }
                $this->stdErr->writeln(\sprintf('Selected environment: %s', $this->api->getEnvironmentLabel($environment)));
                $this->printedEnvironment = $environmentId;
                return $environment;
            }

            $environment = $this->api->getEnvironment($environmentId, $project, null, true);
            if (!$environment) {
                throw new InvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            return $environment;
        }

        if ($config->detectCurrentEnv && ($environment = $this->getCurrentEnvironment($project))) {
            return $environment;
        }

        if ($config->selectDefaultEnv) {
            $this->debug('No environment specified or detected: finding a default...');
            $environments = $this->api->getEnvironments($project);
            $environment = $this->api->getDefaultEnvironment($environments, $project);
            if ($environment) {
                $this->stdErr->writeln(\sprintf('Selected default environment: %s', $this->api->getEnvironmentLabel($environment)));
                $this->printedEnvironment = $environment->id;
                return $environment;
            }
        }

        if ($config->envRequired && $input->isInteractive()) {
            $environments = $this->api->getEnvironments($project);
            if ($config->chooseEnvFilter !== null) {
                $environments = array_filter($environments, $config->chooseEnvFilter);
            }
            if (count($environments) === 1) {
                $only = reset($environments);
                $this->stdErr->writeln(\sprintf('Selected environment: %s (by default)', $this->api->getEnvironmentLabel($only)));
                $this->printedEnvironment = $only->id;
                return $only;
            }
            if (count($environments) > 0) {
                $this->debug('No environment specified or detected: offering a choice...');
                return $this->offerEnvironmentChoice($input, $project, $config, $environments);
            }
            throw new InvalidArgumentException('Could not select an environment automatically.'
                . "\n" . 'Specify one manually using --environment (-e).');
        }

        if ($config->envRequired) {
            if ($this->getProjectRoot() || !$config->detectCurrentEnv) {
                $message = 'Could not determine the current environment.'
                    . "\n" . 'Specify it manually using --environment (-e).';
            } else {
                $message = 'No environment specified.'
                    . "\n" . 'Specify one using --environment (-e), or go to a project directory.';
            }
            throw new InvalidArgumentException($message);
        }

        return null;
    }

    /**
     * Offer the user an interactive choice of projects.
     *
     * @param BasicProjectInfo[] $projectInfos
     * @param SelectorConfig $config
     *
     * @return string
     *   The chosen project ID.
     */
    private function offerProjectChoice(array $projectInfos, SelectorConfig $config): string
    {
        if (count($projectInfos) >= 25 || count($projectInfos) > (new Terminal())->getHeight() - 3) {
            $autocomplete = [];
            foreach ($projectInfos as $info) {
                if ($info->title) {
                    $autocomplete[$info->id] = $info->id . ' - <question>' . $info->title . '</question>';
                } else {
                    $autocomplete[$info->id] = $info->id;
                }
            }
            asort($autocomplete, SORT_NATURAL | SORT_FLAG_CASE);
            return $this->questionHelper->askInput($config->enterProjectText, null, array_values($autocomplete), function ($value) use ($autocomplete): string {
                [$id, ] = explode(' - ', $value);
                if (empty(trim($id))) {
                    throw new InvalidArgumentException('A project ID is required');
                }
                if (!isset($autocomplete[$id]) && !$this->api->getProject($id)) {
                    throw new InvalidArgumentException('Project not found: ' . $id);
                }
                return $id;
            });
        }

        $projectList = [];
        foreach ($projectInfos as $info) {
            $projectList[$info->id] = $this->api->getProjectLabel($info, false);
        }
        asort($projectList, SORT_NATURAL | SORT_FLAG_CASE);

        return $this->questionHelper->choose($projectList, $config->chooseProjectText, null, false);
    }

    /**
     * Offers a choice of environments.
     *
     * @param InputInterface $input
     * @param Project $project
     * @param SelectorConfig $config
     * @param Environment[] $environments
     * @return Environment
     */
    private function offerEnvironmentChoice(InputInterface $input, Project $project, SelectorConfig $config, array $environments): Environment
    {
        if (!$input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: an environment choice cannot be offered.');
        }

        $defaultEnvironmentId = $this->api->getDefaultEnvironment($environments, $project)?->id;

        if (count($environments) > (new Terminal())->getHeight() / 2) {
            $ids = array_keys($environments);
            sort($ids, SORT_NATURAL | SORT_FLAG_CASE);

            $id = $this->questionHelper->askInput($config->enterEnvText, $defaultEnvironmentId, array_keys($environments), function (string $value) use ($environments): string {
                if (!isset($environments[$value])) {
                    throw new \RuntimeException('Environment not found: ' . $value);
                }

                return $value;
            });
        } else {
            $environmentList = [];
            foreach ($environments as $environment) {
                $environmentList[$environment->id] = $this->api->getEnvironmentLabel($environment, false);
            }
            asort($environmentList, SORT_NATURAL | SORT_FLAG_CASE);

            $text = $config->chooseEnvText;
            if ($defaultEnvironmentId) {
                $text .= "\n" . 'Default: <question>' . $defaultEnvironmentId . '</question>';
            }

            $id = $this->questionHelper->choose($environmentList, $text, $defaultEnvironmentId, false);
        }

        return $environments[$id];
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @param bool $suppressErrors Suppress 403 or not found errors.
     *
     * @return Project|false The current project
     *
     * @throws \RuntimeException
     */
    public function getCurrentProject(bool $suppressErrors = false): Project|false
    {
        if (isset($this->currentProject)) {
            return $this->currentProject;
        }
        if (!$projectRoot = $this->getProjectRoot()) {
            return false;
        }

        $project = false;
        $config = $this->localProject->getProjectConfig($projectRoot);
        if ($config) {
            $this->debug('Project "' . $config['id'] . '" is mapped to the current directory');
            try {
                $project = $this->api->getProject($config['id'], $config['host'] ?? null);
            } catch (BadResponseException $e) {
                if ($suppressErrors && in_array($e->getResponse()->getStatusCode(), [403, 404])) {
                    return $this->currentProject = false;
                }
                if ($this->config->has('api.base_url')
                    && $e->getResponse()->getStatusCode() === 401
                    && parse_url($this->config->getStr('api.base_url'), PHP_URL_HOST) !== $e->getRequest()->getUri()->getHost()) {
                    $this->debug('Ignoring 401 error for unrecognized local project hostname: ' . $e->getRequest()->getUri()->getHost());
                    return $this->currentProject = false;
                }
                throw $e;
            }
            if (!$project) {
                if ($suppressErrors) {
                    return $this->currentProject = false;
                }
                throw new ProjectNotFoundException(
                    "Project not found: " . $config['id']
                    . "\nEither you do not have access to the project or it no longer exists.",
                );
            }
        }
        $this->currentProject = $project;

        return $project;
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project|null $expectedProject The expected project.
     * @param bool|null $refresh Whether to refresh the environments or projects
     *                           cache.
     *
     * @throws \Exception
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(?Project $expectedProject = null, ?bool $refresh = null): Environment|false
    {
        if (!($projectRoot = $this->getProjectRoot())
            || !($project = $this->getCurrentProject(true))
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        $this->git->setDefaultRepositoryDir($projectRoot);
        $config = $this->localProject->getProjectConfig($projectRoot);

        // Check if there is a manual mapping set for the current branch.
        if (!empty($config['mapping'])
            && ($currentBranch = $this->git->getCurrentBranch())
            && !empty($config['mapping'][$currentBranch])) {
            $environment = $this->api->getEnvironment($config['mapping'][$currentBranch], $project, $refresh);
            if ($environment) {
                $this->debug('Found mapped environment for branch ' . $currentBranch . ': ' . $this->api->getEnvironmentLabel($environment));
                return $environment;
            } else {
                unset($config['mapping'][$currentBranch]);
                $this->localProject->writeCurrentProjectConfig($config, $projectRoot);
            }
        }

        // Check whether the user has a Git upstream set to a remote environment
        // ID.
        $upstream = $this->git->getUpstream();
        if ($upstream && str_contains($upstream, '/')) {
            [, $potentialEnvironment] = explode('/', $upstream, 2);
            $environment = $this->api->getEnvironment($potentialEnvironment, $project, $refresh);
            if ($environment) {
                $this->debug('Selected environment ' . $this->api->getEnvironmentLabel($environment) . ', based on Git upstream: ' . $upstream);
                return $environment;
            }
        }

        // There is no Git remote set. Fall back to trying the current branch
        // name.
        if (!empty($currentBranch) || ($currentBranch = $this->git->getCurrentBranch())) {
            $environment = $this->api->getEnvironment($currentBranch, $project, $refresh);
            if (!$environment) {
                // Try a sanitized version of the branch name too.
                $currentBranchSanitized = Environment::sanitizeId($currentBranch);
                if ($currentBranchSanitized !== $currentBranch) {
                    $environment = $this->api->getEnvironment($currentBranchSanitized, $project, $refresh);
                }
            }
            if ($environment) {
                $this->debug('Selected environment ' . $this->api->getEnvironmentLabel($environment) . ' based on branch name: ' . $currentBranch);
                return $environment;
            }
        }

        return false;
    }

    /**
     * @return string|false
     */
    public function getProjectRoot(): string|false
    {
        if (!isset($this->projectRoot)) {
            $this->projectRoot = $this->localProject->getProjectRoot();
        }

        return $this->projectRoot;
    }

    /**
     * Add the --project and --host options.
     *
     * @param InputDefinition $inputDefinition
     */
    public function addProjectOption(InputDefinition $inputDefinition): void
    {
        $inputDefinition->addOption(new InputOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL'));
        $inputDefinition->addOption(new HiddenInputOption('host', null, InputOption::VALUE_REQUIRED, 'Deprecated option, no longer used'));
    }

    /**
     * Add the --environment option.
     *
     * @param InputDefinition $inputDefinition
     */
    public function addEnvironmentOption(InputDefinition $inputDefinition): void
    {
        $inputDefinition->addOption(new InputOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID. Use "' . self::DEFAULT_ENVIRONMENT_CODE . '" to select the project\'s default environment.'));
    }

    /**
     * Add the --app option.
     *
     * @param InputDefinition $inputDefinition
     */
    public function addAppOption(InputDefinition $inputDefinition): void
    {
        $inputDefinition->addOption(new InputOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name'));
    }

    /**
     * Add the --app and --worker and --instance options.
     */
    public function addRemoteContainerOptions(InputDefinition $definition): static
    {
        if (!$definition->hasOption('app')) {
            $this->addAppOption($definition);
        }
        if (!$definition->hasOption('worker')) {
            $definition->addOption(new InputOption('worker', null, InputOption::VALUE_REQUIRED, 'A worker name'));
        }
        $definition->addOption(new InputOption('instance', 'I', InputOption::VALUE_REQUIRED, 'An instance ID'));
        return $this;
    }

    /**
     * Find what app or worker container the user wants to select.
     *
     * Needs the --app and --worker options, as applicable.
     *
     * @param Environment $environment
     * @param InputInterface $input
     *   The user input object.
     * @param string|null $appName
     *
     * @return RemoteContainerInterface
     *   A class representing a container that allows SSH access.
     */
    private function selectRemoteContainer(Environment $environment, InputInterface $input, ?string $appName): RemoteContainerInterface
    {
        $includeWorkers = $input->hasOption('worker');
        try {
            $deployment = $this->api->getCurrentDeployment(
                $environment,
                $input->hasOption('refresh') && $input->getOption('refresh'),
            );
        } catch (EnvironmentStateException $e) {
            if ($environment->isActive() && $e->getMessage() === 'Current deployment not found') {
                $appName = $input->hasOption('app') ? $input->getOption('app') : '';

                return new BrokenEnv($environment, $appName);
            }
            throw $e;
        }

        // Validate the --app option, without doing anything with it.
        if ($appName === null) {
            $appName = $input->hasOption('app') ? $input->getOption('app') : null;
        }

        // Handle the --worker option first, as it's more specific.
        $workerOption = $includeWorkers && $input->hasOption('worker') ? $input->getOption('worker') : null;
        if ($workerOption !== null) {
            // Check for a conflict with the --app option.
            if ($appName !== null
                && str_contains((string) $workerOption, '--')
                && stripos((string) $workerOption, $appName . '--') !== 0) {
                throw new InvalidArgumentException(sprintf(
                    'App name "%s" conflicts with worker name "%s"',
                    $appName,
                    $workerOption,
                ));
            }

            // If we have the app name, load the worker directly.
            if (str_contains((string) $workerOption, '--') || $appName !== null) {
                $qualifiedWorkerName = str_contains((string) $workerOption, '--')
                    ? $workerOption
                    : $appName . '--' . $workerOption;
                try {
                    $worker = $deployment->getWorker($qualifiedWorkerName);
                } catch (\InvalidArgumentException) {
                    throw new InvalidArgumentException('Worker not found: ' . $workerOption . ' (in app: ' . $appName . ')');
                }
                $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $worker->name), OutputInterface::VERBOSITY_VERBOSE);

                return new Worker($worker, $environment);
            }

            // If we don't have the app name, find all the possible matching
            // workers, and ask the user to choose.
            $suffix = '--' . $workerOption;
            $suffixLength = strlen($suffix);
            $workerNames = [];
            foreach ($deployment->workers as $worker) {
                if (substr($worker->name, -$suffixLength) === $suffix) {
                    $workerNames[] = $worker->name;
                }
            }
            if (count($workerNames) === 0) {
                throw new InvalidArgumentException('Worker not found: ' . $workerOption);
            }
            if (count($workerNames) === 1) {
                $workerName = reset($workerNames);
                $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $workerName), OutputInterface::VERBOSITY_VERBOSE);

                return new Worker($deployment->getWorker($workerName), $environment);
            }
            if (!$input->isInteractive()) {
                throw new InvalidArgumentException(sprintf(
                    'Ambiguous worker name: %s (matches: %s)',
                    $workerOption,
                    implode(', ', $workerNames),
                ));
            }
            $workerName = $this->questionHelper->choose(
                array_combine($workerNames, $workerNames),
                'Enter a number to choose a worker:',
            );
            $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $workerName), OutputInterface::VERBOSITY_VERBOSE);

            return new Worker($deployment->getWorker($workerName), $environment);
        }
        // Prompt the user to choose between the app(s) or worker(s) that have
        // been found.
        $appNames = $appName !== null
            ? [$appName]
            : array_map(fn(WebApp $app) => $app->name, $deployment->webapps);
        $choices = array_combine($appNames, $appNames);
        $choicesIncludeWorkers = false;
        if ($includeWorkers) {
            $servicesWithSsh = [];
            foreach ($environment->getSshUrls() as $key => $sshUrl) {
                $parts = explode(':', $key, 2);
                $servicesWithSsh[$parts[0]] = $sshUrl;
            }
            foreach ($deployment->workers as $worker) {
                if (!isset($servicesWithSsh[$worker->name])) {
                    // Only include workers in the interactive selection if they
                    // have SSH endpoints. Some Dedicated environments do not have
                    // separate SSH endpoints for workers.
                    continue;
                }
                [$appPart, ] = explode('--', $worker->name, 2);
                if (in_array($appPart, $appNames, true)) {
                    $choices[$worker->name] = $worker->name;
                    $choicesIncludeWorkers = true;
                }
            }
        }
        if (count($choices) === 0) {
            throw new \RuntimeException('Failed to find apps or workers for environment: ' . $environment->id);
        }
        if (count($appNames) === 1) {
            $choice = reset($appNames);
        } elseif ($input->isInteractive()) {
            if ($choicesIncludeWorkers) {
                $text = sprintf(
                    'Enter a number to choose an app or %s worker:',
                    count($choices) === 2 ? 'its' : 'a',
                );
            } else {
                $text = 'Enter a number to choose an app:';
            }
            ksort($choices, SORT_NATURAL);
            $choice = $this->questionHelper->choose($choices, $text);
        } else {
            throw new InvalidArgumentException(
                $includeWorkers
                    ? 'Specifying the --app or --worker is required in non-interactive mode'
                    : 'Specifying the --app is required in non-interactive mode',
            );
        }

        // Match the choice to a worker or app destination.
        if (str_contains((string) $choice, '--')) {
            $this->stdErr->writeln(sprintf('Selected worker: <info>%s</info>', $choice), OutputInterface::VERBOSITY_VERBOSE);
            return new Worker($deployment->getWorker($choice), $environment);
        }

        $this->stdErr->writeln(sprintf('Selected app: <info>%s</info>', $choice), OutputInterface::VERBOSITY_VERBOSE);

        return new App($deployment->getWebApp($choice), $environment);
    }

    /**
     * Adds the --org (-o) organization name option.
     *
     * @param InputDefinition $definition
     * @param bool $includeProjectOption
     *    Adds a --project option which means the organization may be
     *    auto-selected based on the current or specified project.
     */
    public function addOrganizationOptions(InputDefinition $definition, bool $includeProjectOption = false): void
    {
        if ($this->config->getBool('api.organizations')) {
            $definition->addOption(new InputOption('org', 'o', InputOption::VALUE_REQUIRED, 'The organization name (or ID)'));
            if ($includeProjectOption && !$definition->hasOption('project')) {
                $definition->addOption(new InputOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL, which auto-selects the organization if --org is not used'));
            }
        }
    }

    /**
     * Returns the selected organization according to the --org option.
     *
     * @param InputInterface $input
     * @param string $filterByLink
     *    If no organization is specified, this filters the list of the organizations presented by the name of a HAL
     *    link. For example, 'create-subscription' will list organizations under which the user has the permission to
     *    create a subscription.
     * @param string $filterByCapability
     *   If no organization is specified, this filters the list of the organizations presented to those with the given
     *   capability.
     * @param bool $skipCache
     *
     * @return Organization
     * @throws NoOrganizationsException if the user does not have any organizations matching the filter
     * @throws \InvalidArgumentException if no organization is specified
     *@see Selector::addOrganizationOptions()
     *
     * @todo include this in getSelection according to config
     */
    public function selectOrganization(InputInterface $input, string $filterByLink = '', string $filterByCapability = '', bool $skipCache = false): Organization
    {
        if (!$this->config->getBool('api.organizations')) {
            throw new \BadMethodCallException('Organizations are not enabled');
        }

        $explicitProject = $input->hasOption('project') && $input->getOption('project');
        $selection = $explicitProject ? $this->getSelection($input) : new Selection();

        if ($identifier = $input->getOption('org')) {
            // Organization names have to be lower case, while organization IDs are the uppercase ULID format.
            // So it's easy to distinguish one from the other.
            /** @link https://github.com/ulid/spec */
            if (\preg_match('#^[0-9A-HJKMNP-TV-Z]{26}$#', (string) $identifier) === 1) {
                $this->debug('Detected organization ID format (ULID): ' . $identifier);
                $organization = $this->api->getOrganizationById($identifier, $skipCache);
            } else {
                $organization = $this->api->getOrganizationByName($identifier, $skipCache);
            }
            if (!$organization) {
                throw new InvalidArgumentException('Organization not found: ' . $identifier);
            }

            // Check for a conflict between the --org and the --project options.
            if ($explicitProject && $selection->hasProject()) {
                $project = $selection->getProject();
                if ($project->getProperty('organization', true, false) !== $organization->id) {
                    throw new InvalidArgumentException("The project $project->id is not part of the organization $organization->id");
                }
            }

            return $organization;
        }

        if ($explicitProject) {
            $organization = $this->api->getOrganizationById($selection->getProject()->getProperty('organization'), $skipCache);
            if ($organization) {
                $this->stdErr->writeln(\sprintf('Project organization: %s', $this->api->getOrganizationLabel($organization)));
                return $organization;
            }
        } elseif (($currentProject = $this->getCurrentProject(true)) && $currentProject->hasProperty('organization')) {
            $organizationId = $currentProject->getProperty('organization');
            try {
                $organization = $this->api->getOrganizationById($organizationId, $skipCache);
            } catch (BadResponseException $e) {
                $this->debug('Error when fetching project organization: ' . $e->getMessage());
                $organization = false;
            }
            if ($organization) {
                if ($filterByLink === '' || $organization->hasLink($filterByLink)) {
                    if ($this->stdErr->isVerbose()) {
                        $this->ensurePrintedSelection(new Selection(project: $currentProject));
                        $this->stdErr->writeln(\sprintf('Project organization: %s', $this->api->getOrganizationLabel($organization)));
                    }
                    return $organization;
                } elseif ($this->stdErr->isVerbose()) {
                    $this->stdErr->writeln(sprintf(
                        'Not auto-selecting project organization %s (it does not have the link <comment>%s</comment>)',
                        $this->api->getOrganizationLabel($organization, 'comment'),
                        $filterByLink,
                    ));
                }
            }
        }

        $userId = $this->api->getMyUserId();
        $organizations = $this->api->getClient()->listOrganizationsWithMember($userId);

        if (!$input->isInteractive()) {
            throw new \InvalidArgumentException('An organization name or ID (--org) is required.');
        }
        if (!$organizations) {
            throw new NoOrganizationsException('No organizations found.', 0);
        }

        $this->api->sortResources($organizations, 'name');
        $options = [];
        $byId = [];
        $owned = [];
        foreach ($organizations as $organization) {
            if ($filterByLink !== '' && !$organization->hasLink($filterByLink)) {
                continue;
            }
            if ($filterByCapability !== '' && !in_array($filterByCapability, $organization->capabilities, true)) {
                continue;
            }
            $options[$organization->id] = $this->api->getOrganizationLabel($organization, false);
            $byId[$organization->id] = $organization;
            if ($organization->owner_id === $userId) {
                $owned[$organization->id] = $organization;
            }
        }
        if (empty($options)) {
            $message = 'No organizations found.';
            $filters = [];
            if ($filterByLink !== '') {
                $filters[] = sprintf('access to the link "%s"', $filterByLink);
            }
            if ($filterByCapability !== '') {
                $filters[] = sprintf('capability "%s"', $filterByCapability);
            }
            if ($filters) {
                $message = sprintf('No organizations found (filtered by %s).', implode(' and ', $filters));
            }
            throw new NoOrganizationsException($message, count($organizations));
        }
        if (count($byId) === 1) {
            /** @var Organization $organization */
            $organization = reset($byId);
            $this->stdErr->writeln(\sprintf('Selected organization: %s (by default)', $this->api->getOrganizationLabel($organization)));
            return $organization;
        }
        $default = null;
        if (count($owned) === 1) {
            $default = (string) key($owned);

            // Move the default to the top of the list and label it.
            $options = [$default => $options[$default] . ' <info>(default)</info>'] + $options;
        }

        $id = $this->questionHelper->choose($options, 'Enter a number to choose an organization (<fg=cyan>-o</>):', $default);
        return $byId[$id];
    }

    /**
     * Runs autocompletion for Selector options.
     */
    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        if ($input->mustSuggestOptionValuesFor('project')
            || $input->mustSuggestArgumentValuesFor('project')) {
            $suggestions->suggestValues($this->getProjectAutocompletionSuggestions());
        } elseif ($input->mustSuggestOptionValuesFor('environment')
            || $input->mustSuggestArgumentValuesFor('environment')
            || $input->mustSuggestArgumentValuesFor('parent')) {
            $suggestions->suggestValues($this->getEnvironmentAutocompletionSuggestions($input));
        } elseif ($input->mustSuggestOptionValuesFor('app')) {
            $suggestions->suggestValues($this->getAppAutocompletionSuggestions($input));
        }
    }

    /**
     * Get the preferred project for autocompletion.
     *
     * The project is either defined by an ID that the user has specified in
     * the command (via the 'project' argument or '--project' option), or it is
     * determined from the current path.
     *
     * @param CompletionInput $input
     * @return Project|false
     */
    private function getProjectForAutocompletion(CompletionInput $input): Project|false
    {
        if (!$this->api->isLoggedIn()) {
            return false;
        }
        if ($input->hasOption('project')) {
            $id = $input->getOption('project');
        } elseif ($input->hasArgument('project')) {
            $id = $input->getArgument('project');
        } elseif ($input->hasArgument('get')) {
            $id = $input->getArgument('get');
        } else {
            $id = null;
        }
        if ($id !== null) {
            return $this->api->getProject($id, null, false);
        }
        if ($currentProject = $this->getCurrentProject(true)) {
            return $currentProject;
        }
        return false;
    }

    /**
     * @return Suggestion[]
     */
    private function getEnvironmentAutocompletionSuggestions(CompletionInput $input): array
    {
        $project = $this->getProjectForAutocompletion($input);
        if (!$project) {
            return [];
        }
        $environments = $this->api->getEnvironments($project, false, false);
        $environments = $environments ?: $this->api->getEnvironments($project, null, false);
        $suggestions =  array_map(
            function (Environment $e): Suggestion {
                return new Suggestion($e->id, $e->title && $e->title !== $e->id ? $e->title : '');
            },
            $environments,
        );
        if (count($environments) > 1 && $this->api->getDefaultEnvironment($environments, $project, true) !== null) {
            array_unshift($suggestions, new Suggestion(self::DEFAULT_ENVIRONMENT_CODE, 'Default environment'));
        }
        return $suggestions;
    }

    /**
     * @return Suggestion[]
     */
    private function getProjectAutocompletionSuggestions(): array
    {
        if (!$this->api->isLoggedIn()) {
            return [];
        }
        $projects = $this->api->getMyProjects(false) ?: $this->api->getMyProjects();
        return array_map(
            fn(BasicProjectInfo $p): Suggestion => new Suggestion($p->id, $p->title),
            $projects,
        );
    }

    /**
     * @return array<string|Suggestion>
     */
    private function getAppAutocompletionSuggestions(CompletionInput $input): array
    {
        $apps = [];
        if ($projectRoot = $this->getProjectRoot()) {
            $finder = new ApplicationFinder();
            foreach ($finder->findApplications($projectRoot) as $app) {
                $name = $app->getName();
                if ($name !== null) {
                    $apps[] = $name;
                }
            }
        } elseif ($project = $this->getProjectForAutocompletion($input)) {
            $environments = $this->api->getEnvironments($project, null, false);
            if ($environments && ($environment = $this->api->getDefaultEnvironment($environments, $project))) {
                $apps = array_keys($environment->getSshUrls());
            }
        }

        return $apps;
    }
}
