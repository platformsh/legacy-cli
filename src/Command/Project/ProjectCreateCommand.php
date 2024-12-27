<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Project;

use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\SubCommandRunner;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\QuestionHelper;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Bot;
use Platformsh\Cli\Console\ProgressMessage;
use Platformsh\Cli\Exception\NoOrganizationsException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Util\OsUtil;
use Platformsh\Cli\Util\Sort;
use Platformsh\Client\Model\Organization\Organization;
use Platformsh\Client\Model\Project;
use Platformsh\Client\Model\Region;
use Platformsh\Client\Model\SetupOptions;
use Platformsh\Client\Model\Subscription;
use Platformsh\Client\Model\Subscription\SubscriptionOptions;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:create', description: 'Create a new project', aliases: ['create'])]
class ProjectCreateCommand extends CommandBase
{
    /** @var string[]|null */
    private ?array $plansCache = null;
    /** @var Region[]|null */
    private ?array $regionsCache = null;

    public function __construct(private readonly Api $api, private readonly Config $config, private readonly Git $git, private readonly Io $io, private readonly LocalProject $localProject, private readonly QuestionHelper $questionHelper, private readonly Selector $selector, private readonly SubCommandRunner $subCommandRunner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->selector->addOrganizationOptions($this->getDefinition());

        Form::fromArray($this->getFields())->configureInputDefinition($this->getDefinition());

        $this->addOption('set-remote', null, InputOption::VALUE_NONE, 'Set the new project as the remote for the local project directory. This is the default if no remote is already set.');
        $this->addOption('no-set-remote', null, InputOption::VALUE_NONE, 'Do not set the new project as the remote');

        $this->addHiddenOption('check-timeout', null, InputOption::VALUE_REQUIRED, 'The API timeout while checking the project status', 30)
            ->addHiddenOption('timeout', null, InputOption::VALUE_REQUIRED, 'The total timeout for all API checks (0 to disable the timeout)', 900);

        $this->setHelp(
            <<<EOF
                Use this command to create a new project.

                An interactive form will be presented with the available options. If the
                command is run non-interactively (with --yes), the form will not be displayed,
                and the --region option will be required.

                A project subscription will be requested, and then checked periodically (every
                3 seconds) until the project has been activated, or until the process times
                out (15 minutes by default).

                If known, the project ID will be output to STDOUT. All other output will be sent
                to STDERR.
                EOF,
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationsEnabled = $this->config->getBool('api.organizations');

        // Check if the user needs phone verification before creating a project.
        if (!$organizationsEnabled) {
            $needsVerify = $this->api->checkUserVerification();
            if ($needsVerify['state'] && !$this->requireVerification($needsVerify['type'], '', $input)) {
                return 1;
            }
        }

        // Identify an organization that should own the project.
        $organization = null;
        $setupOptions = null;
        if ($this->config->getBool('api.organizations')) {
            try {
                $organization = $this->selector->selectOrganization($input, 'create-subscription');
            } catch (NoOrganizationsException $e) {
                $this->stdErr->writeln('You do not yet own nor belong to an organization in which you can create a project.');
                if ($e->getTotalNumOrgs() === 0 && $input->isInteractive() && $this->config->isCommandEnabled('organization:create') && $this->questionHelper->confirm('Do you want to create an organization now?')) {
                    if ($this->subCommandRunner->run('organization:create') !== 0) {
                        return 1;
                    }
                    $organization = $this->selector->selectOrganization($input, 'create-subscription');
                } else {
                    return 1;
                }
            }

            if (!$this->checkCanCreate($organization, $input)) {
                return 1;
            }

            $this->stdErr->writeln('Creating a project under the organization ' . $this->api->getOrganizationLabel($organization));
            $this->stdErr->writeln('');

            $setupOptions = $organization->getSetupOptions();
        }

        // Validate the --set-remote option.
        $setRemote = (bool) $input->getOption('set-remote');
        $projectRoot = $this->selector->getProjectRoot();
        $gitRoot = $projectRoot !== false ? $projectRoot : $this->git->getRoot();
        if ($setRemote && $gitRoot === false) {
            $this->stdErr->writeln('The <error>--set-remote</error> option can only be used inside a Git repository directory.');
            $this->stdErr->writeln('Use <info>git init<info> to create a repository.');

            return 1;
        }

        $form = Form::fromArray($this->getFields($setupOptions));
        $options = $form->resolveOptions($input, $output, $this->questionHelper);

        if ($gitRoot !== false && !$input->getOption('no-set-remote')) {
            try {
                $currentProject = $this->selector->getCurrentProject();
            } catch (ProjectNotFoundException) {
                $currentProject = false;
            } catch (BadResponseException $e) {
                if ($e->getResponse()->getStatusCode() === 403) {
                    $currentProject = false;
                } else {
                    throw $e;
                }
            }

            $this->stdErr->writeln('Local Git repository detected: <info>' . $gitRoot . '</info>');
            if ($currentProject) {
                $this->stdErr->writeln(sprintf('The remote project is currently: %s', $this->api->getProjectLabel($currentProject, 'comment')));
            }
            $this->stdErr->writeln('');

            if ($setRemote) {
                $this->stdErr->writeln(sprintf('The new project <info>%s</info> will be set as the remote for this repository directory.', $options['title']));
            } elseif ($currentProject) {
                $setRemote = $this->questionHelper->confirm(sprintf(
                    'Switch the remote project for this repository directory from <comment>%s</comment> to the new project <comment>%s</comment>?',
                    $this->api->getProjectLabel($currentProject, false),
                    $options['title'],
                ), false);
            } else {
                $setRemote = $this->questionHelper->confirm(sprintf(
                    'Set the new project <info>%s</info> as the remote for this repository directory?',
                    $options['title'],
                ));
            }
            $this->stdErr->writeln('');
        }

        $options_custom = null;
        if ($options['init_repo'] !== null) {
            $this->stdErr->writeln('The project will be initialized with the repository URL: <info>' . $options['init_repo'] . '</info>');
            $this->stdErr->writeln('');
            $options_custom = [];
            $options_custom['initialize']['repository'] = $options['init_repo'];
        }

        $estimate = $this->api
            ->getClient()
            ->getSubscriptionEstimate($options['plan'], (int) $options['storage'] * 1024, (int) $options['environments'], 1, null, $organization?->id);
        $costConfirm = sprintf(
            'The estimated monthly cost of this project is: <comment>%s</comment>',
            $estimate['total'],
        );
        if ($this->config->has('service.pricing_url')) {
            $costConfirm .= sprintf(
                "\nPricing information: <comment>%s</comment>",
                $this->config->getStr('service.pricing_url'),
            );
        }
        $costConfirm .= "\n\nAre you sure you want to continue?";
        if (!$this->questionHelper->confirm($costConfirm)) {
            return 1;
        }

        $subscription = $this->api->getClient()
            ->createSubscription(SubscriptionOptions::fromArray([
                'organization_id' => $organization?->id,
                'project_title' => $options['title'],
                'project_region' => $options['region'],
                'default_branch' => $options['default_branch'],
                'plan' => $options['plan'],
                'storage' => (int) $options['storage'] * 1024,
                'environments' => (int) $options['environments'],
                'options_custom' => $options_custom,
                'options_url' => null,
            ]));

        $this->api->clearProjectsCache();

        $this->stdErr->writeln(sprintf(
            'Your %s project has been requested (subscription ID: <comment>%s</comment>)',
            $this->config->getStr('service.name'),
            $subscription->id,
        ));

        $this->stdErr->writeln(sprintf(
            "\nThe %s Bot is activating your project\n",
            $this->config->getStr('service.name'),
        ));

        $bot = new Bot($this->stdErr);
        $timedOut = false;
        $start = $lastCheck = time();
        $checkInterval = 3;
        $checkTimeout = $this->getTimeOption($input, 'check-timeout', 1, 3600);
        $totalTimeout = $this->getTimeOption($input, 'timeout', 0, 3600);
        while ($subscription->isPending() && !$timedOut) {
            $bot->render();
            // Attempt to check the subscription every $checkInterval seconds.
            // This also waits $checkInterval seconds before the first check,
            // which allows the server a little more leeway to act on the
            // initial request.
            if (time() - $lastCheck >= $checkInterval) {
                $lastCheck = time();
                try {
                    // The API call will timeout after $checkTimeout seconds.
                    $subscription->refresh(['timeout' => $checkTimeout]);
                } catch (ConnectException $e) {
                    if (str_contains($e->getMessage(), 'timed out')) {
                        $this->io->debug($e->getMessage());
                    } else {
                        throw $e;
                    }
                } catch (BadResponseException $e) {
                    if (in_array($e->getResponse()->getStatusCode(), [502, 503, 524])) {
                        $this->io->debug($e->getMessage());
                    } else {
                        throw $e;
                    }
                }
            }
            usleep(200000);
            // Check the total timeout.
            $timedOut = $totalTimeout && time() - $start > $totalTimeout;
        }

        $this->stdErr->writeln('');

        if (!$subscription->isActive()) {
            if ($timedOut) {
                $this->stdErr->writeln('<error>The project failed to activate on time</error>');
            } else {
                $this->stdErr->writeln('<error>The project failed to activate</error>');
            }

            if (!empty($subscription->project_id)) {
                $output->writeln($subscription->project_id);
            }

            $this->stdErr->writeln(sprintf('View your active projects with: <info>%s project:list</info>', $this->config->getStr('application.executable')));

            return 1;
        }

        $project = $this->waitForProject($subscription, $totalTimeout, $start);
        if (!$project) {
            return 1;
        }

        $this->stdErr->writeln("The project is now ready!");
        $output->writeln($subscription->project_id);
        $this->stdErr->writeln('');

        $this->stdErr->writeln("  Region: <info>{$subscription->project_region}</info>");
        $this->stdErr->writeln("  Project ID: <info>{$subscription->project_id}</info>");
        $this->stdErr->writeln("  Project title: <info>{$subscription->project_title}</info>");
        $this->stdErr->writeln('');

        $this->stdErr->writeln(sprintf("  Console URL: <info>%s</info>", $this->api->getConsoleURL($project)));

        $this->stdErr->writeln("  Git URL: <info>{$project->getGitUrl()}</info>");

        if ($setRemote && $gitRoot !== false) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Setting the remote project for this repository to: %s',
                $this->api->getProjectLabel($project),
            ));

            $localProject = $this->localProject;
            $localProject->mapDirectory($gitRoot, $project);
        }

        if ($gitRoot === false) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('To clone the project locally, run: <info>%s get %s</info>', $this->config->getStr('application.executable'), OsUtil::escapeShellArg($project->id)));
        }

        return 0;
    }

    private function waitForProject(Subscription $subscription, int|float $totalTimeout, float $start): Project|false
    {
        $progressMessage = new ProgressMessage($this->stdErr);
        $checkInterval = 1;
        $lastCheck = time();
        $progressMessage->show('Loading project information...');
        while (true) {
            if (time() - $lastCheck >= $checkInterval) {
                $lastCheck = time();
                try {
                    $project = $this->api->getProject($subscription->project_id);
                    if ($project !== false) {
                        $progressMessage->done();
                        return $project;
                    } else {
                        $this->io->debug(sprintf('Project not found: %s (retrying)', $subscription->project_id));
                    }
                } catch (ConnectException $e) {
                    if (str_contains($e->getMessage(), 'timed out')) {
                        $this->io->debug($e->getMessage());
                    } else {
                        throw $e;
                    }
                } catch (BadResponseException $e) {
                    if (in_array($e->getResponse()->getStatusCode(), [403, 502, 524])) {
                        $this->io->debug(sprintf('Received status code %d from project: %s (retrying)', $e->getResponse()->getStatusCode(), $subscription->project_id));
                    } else {
                        throw $e;
                    }
                }
                usleep(200000);
            }
            if ($totalTimeout && time() - $start > $totalTimeout) {
                $progressMessage->done();
                $this->stdErr->writeln(sprintf('The subscription is active but the project <error>%s</error> could not be fetched.', $subscription->project_id));
                $this->stdErr->writeln('The project may be accessible momentarily. Otherwise, please contact support.');
                return false;
            }
        }
    }

    /**
     * Checks the organization /can-create API before creating a project.
     *
     * This will show whether billing changes or verification are needed.
     */
    private function checkCanCreate(Organization $organization, InputInterface $input): bool
    {
        $canCreate = $this->api->checkCanCreate($organization);
        if ($canCreate['can_create']) {
            return true;
        }
        if ($canCreate['required_action']) {
            $consoleUrl = $this->config->getStr('service.console_url');
            if ($consoleUrl && $canCreate['required_action']['action'] === 'billing_details') {
                $this->stdErr->writeln($canCreate['message']);
                $this->stdErr->writeln('');
                $this->stdErr->writeln('View or update billing details at:');
                $this->stdErr->writeln(sprintf('<info>%s/%s/-/billing</info>', rtrim($consoleUrl, '/'), $organization->name));
                return false;
            }
            if ($consoleUrl && $canCreate['required_action']['action'] === 'ticket') {
                $this->stdErr->writeln($canCreate['message']);
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please open the following URL in a browser to create a ticket:');
                $this->stdErr->writeln(sprintf('<info>%s/support</info>', rtrim($consoleUrl, '/')));
                return false;
            }
            if ($canCreate['required_action']['action'] === 'verification') {
                return $this->requireVerification($canCreate['required_action']['type'], $canCreate['message'], $input);
            }
        }
        $this->stdErr->writeln($canCreate['message']);
        return false;
    }

    /**
     * Requires phone or support verification.
     */
    private function requireVerification(string $type, string $message, InputInterface $input): bool
    {
        if ($type === 'phone') {
            $this->stdErr->writeln('Phone number verification is required before creating a project.');
            if ($input->isInteractive()) {
                $this->stdErr->writeln('');
                $exitCode = $this->subCommandRunner->run('auth:verify-phone-number');
                if ($exitCode === 0) {
                    $this->stdErr->writeln('');
                    return true;
                }
            } elseif ($this->config->has('service.console_url')) {
                $url = $this->config->getStr('service.console_url') . '/-/phone-verify';
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please open the following URL in a browser to verify your phone number:');
                $this->stdErr->writeln(sprintf('<info>%s</info>', $url));
                return false;
            }
        } elseif ($type === 'credit-card') {
            $this->stdErr->writeln('Credit card verification is required before creating a project.');
            if ($this->config->has('service.console_url')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please use Console to create your first project:');
                $this->stdErr->writeln(sprintf('<info>%s</info>', $this->config->getStr('service.console_url')));
            }
        } elseif ($type === 'support' || $type === 'ticket') {
            $this->stdErr->writeln('Verification via a support ticket is required before creating a project.');
            if ($this->config->has('service.console_url')) {
                $url = $this->config->getStr('service.console_url') . '/support';
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please open the following URL in a browser to create a ticket:');
                $this->stdErr->writeln(sprintf('<info>%s</info>', $url));
            }
        } else {
            $this->stdErr->writeln($message);
        }
        return false;
    }

    /**
     * Return a list of plans.
     *
     * @param SetupOptions|null $setupOptions
     *
     * @return string[]
     *   A list of plan machine names.
     */
    protected function getAvailablePlans(?SetupOptions $setupOptions = null): array
    {
        if (isset($setupOptions)) {
            return $setupOptions->plans;
        }
        if ($this->plansCache !== null) {
            return $this->plansCache;
        }
        $plans = [];
        foreach ($this->api->getClient()->getPlans() as $plan) {
            $plans[] = $plan->name;
        }
        return $this->plansCache = $plans;
    }

    /**
     * Picks a default plan from a list.
     *
     * @param string[] $availablePlans
     */
    protected function getDefaultPlan(array $availablePlans): ?string
    {
        if (count($availablePlans) === 1) {
            return reset($availablePlans);
        }
        if (in_array('development', $availablePlans)) {
            return 'development';
        }
        return null;
    }

    /**
     * Return a list of regions.
     *
     * @param SetupOptions|null $setupOptions
     *
     * @return array<string, string>
     *   A list of region names, mapped to option names.
     */
    protected function getAvailableRegions(?SetupOptions $setupOptions = null): array
    {
        $regions = $this->regionsCache !== null
            ? $this->regionsCache
            : $this->regionsCache = $this->api->getClient()->getRegions();
        $available = [];
        if (isset($setupOptions)) {
            $available = $setupOptions->regions;
        } else {
            foreach ($regions as $region) {
                if ($region->available) {
                    $available[] = $region->id;
                }
            }
        }

        \usort($available, Sort::compareDomains(...));

        $options = [];
        foreach ($available as $id) {
            foreach ($regions as $region) {
                if ($region->id === $id) {
                    $options[$id] = $this->regionInfo($region);
                    continue 2;
                }
            }
            $options[$id] = $id;
        }

        return $options;
    }

    /**
     * Outputs a short description of a region, including its location and carbon intensity.
     */
    private function regionInfo(Region $region): string
    {
        $green = !empty($region->environmental_impact['green']);
        if (!empty($region->datacenter['location'])) {
            $info = $green ? '<fg=green>' . $region->datacenter['location'] . '</>' : $region->datacenter['location'];
        } else {
            $info = $region->id;
        }
        if (!empty($region->provider['name'])) {
            $info .= ' ' . \sprintf('(%s)', $region->provider['name']);
        }
        if (!empty($region->environmental_impact['carbon_intensity'])) {
            $format = $green ? ' [<options=bold;fg=green>%d</> gC02eq/kWh]' : ' [%d gC02eq/kWh]';
            $info .= ' ' . \sprintf($format, $region->environmental_impact['carbon_intensity']);
        }

        return $info;
    }

    /**
     * Returns a list of ConsoleForm form fields for this command.
     *
     * @return Field[]
     */
    protected function getFields(?SetupOptions $setupOptions = null): array
    {
        return [
            'title' => new Field('Project title', [
                'optionName' => 'title',
                'description' => 'The initial project title',
                'questionLine' => '',
                'default' => 'Untitled Project',
            ]),
            'region' => new OptionsField('Region', [
                'optionName' => 'region',
                'description' => trim("The region where the project will be hosted.\n" . $this->config->getStr('messages.region_discount')),
                'optionsCallback' => fn() => $this->getAvailableRegions($setupOptions),
                'allowOther' => true,
            ]),
            'plan' => new OptionsField('Plan', [
                'optionName' => 'plan',
                'description' => 'The subscription plan',

                // The field starts with an empty list of plans. Then when it is
                // initialized during "resolveOptions", replace the list of plans
                // and set a default if possible. If the organization setup options
                // have been supplied ($setupOptions is not null) then that plans
                // list will be used.
                'optionsCallback' => fn() => $this->getAvailablePlans($setupOptions),
                'defaultCallback' => fn() => $this->getDefaultPlan($this->getAvailablePlans($setupOptions)),

                'allowOther' => true,
                'avoidQuestion' => true,
            ]),
            'environments' => new Field('Environments', [
                'optionName' => 'environments',
                'description' => 'The number of environments',
                'default' => 3,
                'validator' => fn($value): bool => is_numeric($value) && $value > 0 && $value < 50,
                'avoidQuestion' => true,
            ]),
            'storage' => new Field('Storage', [
                'description' => 'The amount of storage per environment, in GiB',
                'default' => 5,
                'validator' => fn($value): bool => is_numeric($value) && $value > 0 && $value < 1024,
                'avoidQuestion' => true,
            ]),
            'default_branch' => new Field('Default branch', [
                'description' => 'The default Git branch name for the project (the production environment)',
                'required' => false,
                'default' => 'main',
            ]),
            'init_repo' => new UrlField('Initialize repository', [
                'optionName' => 'init-repo',
                'description' => 'URL of a Git repository to use for initialization. A GitHub path such as "platformsh-templates/nuxtjs" can be used.',
                'required' => false,
                'avoidQuestion' => true,
                'normalizer' => function (string $url): string {
                    // Provide GitHub as a default.
                    if (str_starts_with($url, 'github.com')) {
                        return 'https://github.com' . substr($url, 10);
                    }
                    if (!str_contains($url, '//') && preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $url)) {
                        return 'https://github.com/' . $url;
                    }
                    return $url;
                },
                'validator' => function ($url): string|true {
                    if (!str_starts_with($url, 'https://') && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                        return 'The initialize repository URL must start with "https://".';
                    }
                    $response = $this->api->getExternalHttpClient()->get($url, ['http_errors' => false]);
                    $code = $response->getStatusCode();
                    if ($code >= 400) {
                        return sprintf('The initialize repository URL "%s" returned status code %d. The repository must be public.', $url, $code);
                    }
                    return true;
                },
            ]),
        ];
    }

    /**
     * Gets a numeric option value while ensuring it's a reasonable number.
     *
     * @param InputInterface $input
     * @param string $optionName
     * @param int $min
     * @param int $max
     *
     * @return float|int
     */
    private function getTimeOption(InputInterface $input, string $optionName, int $min = 0, int $max = 3600): float|int
    {
        $value = $input->getOption($optionName);
        if ($value <= $min) {
            $value = $min;
        } elseif ($value > $max) {
            $value = $max;
        }

        return $value;
    }
}
