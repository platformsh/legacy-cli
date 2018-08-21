<?php
declare(strict_types=1);

namespace Platformsh\Cli\Service;

use Platformsh\Cli\Console\Selection;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Deployment\WebApp;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Service allowing the user to select the current project, environment and app.
 */
class Selector
{
    private $identifier;
    private $config;
    private $stdErr;
    private $api;
    private $localProject;
    private $questionHelper;
    private $git;

    /** @var Project|null */
    private $currentProject;

    /** @var string|null */
    private $projectRoot;

    /** @var string */
    private $envArgName = 'environment';

    public function __construct(
        OutputInterface $output,
        Identifier $identifier,
        Config $config,
        Api $api,
        LocalProject $localProject,
        QuestionHelper $questionHelper,
        Git $git
    ) {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $this->identifier = $identifier;
        $this->config = $config;
        $this->api = $api;
        $this->localProject = $localProject;
        $this->questionHelper = $questionHelper;
        $this->git = $git;
    }

    public function setEnvArgName($envArgName = 'environment')
    {
        $this->envArgName = $envArgName;
    }

    /**
     * Prints a message if debug output is enabled.
     *
     * @todo refactor this
     *
     * @param string $message
     */
    private function debug($message)
    {
        $this->stdErr->writeln('<options=reverse>DEBUG</> ' . $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Processes and validates input to select the project, environment and app.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param bool                                            $envNotRequired
     *
     * @return Selection
     */
    public function getSelection(InputInterface $input, $envNotRequired = false)
    {
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
        $envPrefix = $this->config->get('service.env_prefix');
        if ($projectId === null && getenv($envPrefix . 'PROJECT')) {
            $projectId = getenv($envPrefix . 'PROJECT');
            $this->stdErr->writeln(sprintf(
                'Project ID read from environment variable %s: %s',
                $envPrefix . 'PROJECT',
                $projectId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        // Select the project.
        $project = $this->selectProject($input, $projectId, $projectHost);

        // Select the environment.
        $envOptionName = 'environment';
        $environment = null;
        if ($project && $input->hasArgument($this->envArgName) && $input->getArgument($this->envArgName)) {
            if ($input->hasOption($envOptionName) && $input->getOption($envOptionName)) {
                throw new InvalidArgumentException(
                    sprintf(
                        'You cannot use both the <%s> argument and the --%s option',
                        $this->envArgName,
                        $envOptionName
                    )
                );
            }
            $argument = $input->getArgument($this->envArgName);
            if (is_array($argument) && count($argument) == 1) {
                $argument = $argument[0];
            }
            if (!is_array($argument)) {
                $this->debug('Selecting environment based on input argument');
                $environment = $this->selectEnvironment($input, $project, $argument);
            }
        } elseif ($project && $input->hasOption($envOptionName)) {
            $environmentId = $input->getOption($envOptionName) ?: $environmentId;
            $environment = $this->selectEnvironment($input, $project, $environmentId, !$envNotRequired);
        }

        // Select the app.
        $appName = null;
        if ($input->hasOption('app') && !$input->getOption('app')) {
            // An app ID might be provided from the parsed project URL.
            if (isset($result) && isset($result['appId'])) {
                $appName = $result['appId'];
                $this->debug(sprintf(
                    'App name identified as: %s',
                    $input->getOption('app')
                ));
            }
            // Or from an environment variable.
            elseif (getenv($envPrefix . 'APPLICATION_NAME')) {
                $appName = getenv($envPrefix . 'APPLICATION_NAME');
                $this->stdErr->writeln(sprintf(
                    'App name read from environment variable %s: %s',
                    $envPrefix . 'APPLICATION_NAME',
                    $input->getOption('app')
                ), OutputInterface::VERBOSITY_VERBOSE);
            } elseif ($input->getOption('app')) {
                $appName = $input->getOption('app');
            } elseif ($environment !== null) {
                $appName = $this->selectApp($environment, $input);
            }
        }

        return new Selection($project, $environment, $appName);
    }


    /**
     * Select the project for the user, based on input or the environment.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string                                          $projectId
     * @param string                                          $host
     *
     * @return Project
     */
    private function selectProject(InputInterface $input, $projectId = null, $host = null)
    {
        if (!empty($projectId)) {
            $project = $this->api->getProject($projectId, $host);
            if (!$project) {
                throw new InvalidArgumentException($this->getProjectNotFoundMessage($projectId));
            }

            $this->debug('Selected project: ' . $project->id);

            return $project;
        }

        $project = $this->getCurrentProject();
        if (!$project && $input->isInteractive()) {
            $projects = $this->api->getProjects();
            if (count($projects) > 0 && count($projects) < 25) {
                $this->debug('No project specified: offering a choice...');
                $projectId = $this->offerProjectChoice($input, $projects);

                return $this->selectProject($input, $projectId);
            }
        }
        if (!$project) {
            throw new RootNotFoundException(
                "Could not determine the current project."
                . "\n\nSpecify it using --project, or go to a project directory."
            );
        }

        return $project;
    }

    /**
     * Format an error message about a not-found project.
     *
     * @param string $projectId
     *
     * @return string
     */
    private function getProjectNotFoundMessage($projectId)
    {
        $message = 'Specified project not found: ' . $projectId;
        if ($projects = $this->api->getProjects()) {
            $message .= "\n\nYour projects are:";
            $limit = 8;
            foreach (array_slice($projects, 0, $limit) as $project) {
                $message .= "\n    " . $project->id;
                if ($project->title) {
                    $message .= ' - ' . $project->title;
                }
            }
            if (count($projects) > $limit) {
                $message .= "\n    ...";
                $message .= "\n\n    List projects with: " . $this->config->get('application.executable') . ' project:list';
            }
        }

        return $message;
    }

    /**
     * Select the current environment for the user.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Project                                         $project
     *   The project, or null if no project is selected.
     * @param string|null                                     $environmentId
     *   The environment ID specified by the user, or null to auto-detect the
     *   environment.
     * @param bool                                            $required
     *   Whether it's required to have an environment.
     *
     * @return Environment|null
     */
    private function selectEnvironment(InputInterface $input, Project $project, $environmentId = null, $required = true)
    {
        $envPrefix = $this->config->get('service.env_prefix');
        if ($environmentId === null && getenv($envPrefix . 'BRANCH')) {
            $environmentId = getenv($envPrefix . 'BRANCH');
            $this->stdErr->writeln(sprintf(
                'Environment ID read from environment variable %s: %s',
                $envPrefix . 'BRANCH',
                $environmentId
            ), OutputInterface::VERBOSITY_VERBOSE);
        }

        if (!empty($environmentId)) {
            $environment = $this->api->getEnvironment($environmentId, $project, null, true);
            if (!$environment) {
                throw new InvalidArgumentException('Specified environment not found: ' . $environmentId);
            }

            $this->debug('Selected environment: ' . $this->api->getEnvironmentLabel($environment));
            return $environment;
        }

        if ($environment = $this->getCurrentEnvironment($project)) {
            return $environment;
        }

        if ($required && $input->isInteractive()) {
            $this->debug('No environment specified: offering a choice...');
            return $this->offerEnvironmentChoice($input, $this->api->getEnvironments($project));
        }

        if ($required) {
            if ($this->getProjectRoot()) {
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
     * Find the name of the app the user wants to use for an SSH command.
     *
     * @param Environment $environment
     *   The environment.
     * @param InputInterface $input
     *   The user input object.
     *
     * @return string|null
     *   The application name, or null if it could not be found.
     */
    private function selectApp(Environment $environment, InputInterface $input)
    {
        try {
            $apps = array_map(function (WebApp $app) {
                return $app->name;
            }, $this->api->getCurrentDeployment($environment)->webapps);
            if (!count($apps)) {
                return null;
            }
        } catch (EnvironmentStateException $e) {
            if (!$e->getEnvironment()->isActive()) {
                throw new EnvironmentStateException(
                    sprintf('Could not find applications: the environment "%s" is not currently active.', $e->getEnvironment()->id),
                    $e->getEnvironment()
                );
            }
            throw $e;
        }

        $this->debug('Found app(s): ' . implode(',', $apps));
        if (count($apps) === 1) {
            $appName = reset($apps);
        } elseif ($input->isInteractive()) {
            $choices = array_combine($apps, $apps);
            $appName = $this->questionHelper->choose($choices, 'Enter a number to choose an app:');
        }

        return !empty($appName) ? $appName : null;
    }

    /**
     * Offer the user an interactive choice of projects.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Project[]                                       $projects
     * @param string                                          $text
     *
     * @return string
     *   The chosen project ID.
     */
    public function offerProjectChoice(InputInterface $input, array $projects, $text = 'Enter a number to choose a project:')
    {
        if (!$input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: a project choice cannot be offered.');
        }

        $projectList = [];
        foreach ($projects as $project) {
            $projectList[$project->id] = $this->api->getProjectLabel($project, false);
        }

        $id = $this->questionHelper->choose($projectList, $text, null, false);

        $this->stdErr->writeln('');

        return $id;
    }

    /**
     * Offers a choice of environments.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param Environment[]                                   $environments
     *
     * @return Environment
     */
    private function offerEnvironmentChoice(InputInterface $input, array $environments)
    {
        if (!$input->isInteractive()) {
            throw new \BadMethodCallException('Not interactive: an environment choice cannot be offered.');
        }

        $default = $this->api->getDefaultEnvironmentId($environments);

        $id = $this->questionHelper->askInput('Environment ID', $default, array_keys($environments), function ($value) use ($environments) {
            if (!isset($environments[$value])) {
                throw new \RuntimeException('Environment not found: ' . $value);
            }

            return $value;
        });

        $this->stdErr->writeln('');

        return $environments[$id];
    }

    /**
     * Get the current project if the user is in a project directory.
     *
     * @throws \RuntimeException
     *
     * @return Project|false The current project
     */
    public function getCurrentProject()
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
            $project = $this->api->getProject($config['id'], isset($config['host']) ? $config['host'] : null);
            if (!$project) {
                throw new ProjectNotFoundException(
                    "Project not found: " . $config['id']
                    . "\nEither you do not have access to the project or it no longer exists."
                );
            }
            $this->debug('Project ' . $config['id'] . ' is mapped to the current directory');
        }
        $this->currentProject = $project;

        return $project;
    }

    /**
     * Get the current environment if the user is in a project directory.
     *
     * @param Project $expectedProject The expected project.
     * @param bool|null $refresh Whether to refresh the environments or projects
     *                           cache.
     *
     * @return Environment|false The current environment.
     */
    public function getCurrentEnvironment(Project $expectedProject = null, $refresh = null)
    {
        if (!($projectRoot = $this->getProjectRoot())
            || !($project = $this->getCurrentProject())
            || ($expectedProject !== null && $expectedProject->id !== $project->id)) {
            return false;
        }

        $this->git->setDefaultRepositoryDir($this->getProjectRoot());
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
        if ($upstream && strpos($upstream, '/') !== false) {
            list(, $potentialEnvironment) = explode('/', $upstream, 2);
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
    public function getProjectRoot()
    {
        if (!isset($this->projectRoot)) {
            $this->debug('Finding the project root');
            $this->projectRoot = $this->localProject->getProjectRoot();
            $this->debug(
                $this->projectRoot
                    ? 'Project root found: ' . $this->projectRoot
                    : 'Project root not found'
            );
        }

        return $this->projectRoot;
    }

    /**
     * Add the --project and --host options.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addProjectOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('project', 'p', InputOption::VALUE_REQUIRED, 'The project ID or URL'));
        $inputDefinition->addOption(new InputOption('host', null, InputOption::VALUE_REQUIRED, "The project's API hostname"));
    }

    /**
     * Add the --environment option.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addEnvironmentOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('environment', 'e', InputOption::VALUE_REQUIRED, 'The environment ID'));
    }

    /**
     * Add the --app option.
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addAppOption(InputDefinition $inputDefinition)
    {
        $inputDefinition->addOption(new InputOption('app', 'A', InputOption::VALUE_REQUIRED, 'The remote application name'));
    }

    /**
     * Add all selection options (project, environment and app).
     *
     * @param \Symfony\Component\Console\Input\InputDefinition $inputDefinition
     */
    public function addAllOptions(InputDefinition $inputDefinition)
    {
        $this->addProjectOption($inputDefinition);
        $this->addEnvironmentOption($inputDefinition);
        $this->addAppOption($inputDefinition);
    }
}
