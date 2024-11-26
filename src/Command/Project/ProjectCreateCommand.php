<?php

namespace Platformsh\Cli\Command\Project;

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
use Platformsh\Client\Model\Region;
use Platformsh\Client\Model\SetupOptions;
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
    private $plansCache;
    private $regionsCache;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->addOrganizationOptions();

        Form::fromArray($this->getFields())->configureInputDefinition($this->getDefinition());

        $this->addOption('set-remote', null, InputOption::VALUE_NONE, 'Set the new project as the remote for the local project directory. This is the default if no remote is already set.');
        $this->addOption('no-set-remote', null, InputOption::VALUE_NONE, 'Do not set the new project as the remote');

        $this->addHiddenOption('check-timeout', null, InputOption::VALUE_REQUIRED, 'The API timeout while checking the project status', 30)
            ->addHiddenOption('timeout', null, InputOption::VALUE_REQUIRED, 'The total timeout for all API checks (0 to disable the timeout)', 900);

        $this->setHelp(<<<EOF
Use this command to create a new project.

An interactive form will be presented with the available options. If the
command is run non-interactively (with --yes), the form will not be displayed,
and the --region option will be required.

A project subscription will be requested, and then checked periodically (every
3 seconds) until the project has been activated, or until the process times
out (15 minutes by default).

If known, the project ID will be output to STDOUT. All other output will be sent
to STDERR.
EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $organizationsEnabled = $this->config()->getWithDefault('api.organizations', false);

        // Check if the user needs phone verification before creating a project.
        if (!$organizationsEnabled) {
            $needsVerify = $this->api()->checkUserVerification();
            if ($needsVerify['state'] && !$this->requireVerification($needsVerify['type'], '', $input)) {
                return 1;
            }
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        // Identify an organization that should own the project.
        $organization = null;
        $setupOptions = null;
        if ($this->config()->getWithDefault('api.organizations', false)) {
            try {
                $organization = $this->validateOrganizationInput($input, 'create-subscription');
            } catch (NoOrganizationsException $e) {
                $this->stdErr->writeln('You do not yet own nor belong to an organization in which you can create a project.');
                if ($e->getTotalNumOrgs() === 0 && $input->isInteractive() && $this->config()->isCommandEnabled('organization:create') && $questionHelper->confirm('Do you want to create an organization now?')) {
                    if ($this->runOtherCommand('organization:create') !== 0) {
                        return 1;
                    }
                    $organization = $this->validateOrganizationInput($input, 'create-subscription');
                } else {
                    return 1;
                }
            }

            if (!$this->checkCanCreate($organization, $input)) {
                return 1;
            }

            $this->stdErr->writeln('Creating a project under the organization ' . $this->api()->getOrganizationLabel($organization));
            $this->stdErr->writeln('');

            $setupOptions = $organization->getSetupOptions();
        }

        // Validate the --set-remote option.
        $setRemote = (bool) $input->getOption('set-remote');
        $projectRoot = $this->getProjectRoot();
        $gitRoot = $projectRoot !== false ? $projectRoot : $git->getRoot();
        if ($setRemote && $gitRoot === false) {
            $this->stdErr->writeln('The <error>--set-remote</error> option can only be used inside a Git repository directory.');
            $this->stdErr->writeln('Use <info>git init<info> to create a repository.');

            return 1;
        }

        $form = Form::fromArray($this->getFields($setupOptions));
        $options = $form->resolveOptions($input, $output, $questionHelper);

        if ($gitRoot !== false && !$input->getOption('no-set-remote')) {
            try {
                $currentProject = $this->getCurrentProject();
            } catch (ProjectNotFoundException $e) {
                $currentProject = false;
            } catch (BadResponseException $e) {
                if ($e->getResponse() && $e->getResponse()->getStatusCode() === 403) {
                    $currentProject = false;
                } else {
                    throw $e;
                }
            }

            $this->stdErr->writeln('Local Git repository detected: <info>' . $gitRoot . '</info>');
            if ($currentProject) {
                $this->stdErr->writeln(sprintf('The remote project is currently: %s', $this->api()->getProjectLabel($currentProject, 'comment')));
            }
            $this->stdErr->writeln('');

            if ($setRemote) {
                $this->stdErr->writeln(sprintf('The new project <info>%s</info> will be set as the remote for this repository directory.', $options['title']));
            } elseif ($currentProject) {
                $setRemote = $questionHelper->confirm(sprintf(
                    'Switch the remote project for this repository directory from <comment>%s</comment> to the new project <comment>%s</comment>?',
                    $this->api()->getProjectLabel($currentProject, false),
                    $options['title']
                ), false);
            } else {
                $setRemote = $questionHelper->confirm(sprintf(
                    'Set the new project <info>%s</info> as the remote for this repository directory?',
                    $options['title']
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

        $estimate = $this->api()
            ->getClient()
            ->getSubscriptionEstimate($options['plan'], (int) $options['storage'] * 1024, (int) $options['environments'], 1, null, $organization ? $organization->id : null);
        $costConfirm = sprintf(
            'The estimated monthly cost of this project is: <comment>%s</comment>',
            $estimate['total']
        );
        if ($this->config()->has('service.pricing_url')) {
            $costConfirm .= sprintf(
                "\nPricing information: <comment>%s</comment>",
                $this->config()->get('service.pricing_url')
            );
        }
        $costConfirm .= "\n\nAre you sure you want to continue?";
        if (!$questionHelper->confirm($costConfirm)) {
            return 1;
        }

        $subscription = $this->api()->getClient()
            ->createSubscription(SubscriptionOptions::fromArray([
                'organization_id' => $organization ? $organization->id : null,
                'project_title' => $options['title'],
                'project_region' => $options['region'],
                'default_branch' => $options['default_branch'],
                'plan' => $options['plan'],
                'storage' => (int) $options['storage'] * 1024,
                'environments' => (int) $options['environments'],
                'options_custom' => $options_custom,
            ]));

        $this->api()->clearProjectsCache();

        $this->stdErr->writeln(sprintf(
            'Your %s project has been requested (subscription ID: <comment>%s</comment>)',
            $this->config()->get('service.name'),
            $subscription->id
        ));

        $this->stdErr->writeln(sprintf(
            "\nThe %s Bot is activating your project\n",
            $this->config()->get('service.name')
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
                    if (strpos($e->getMessage(), 'timed out') !== false) {
                        $this->debug($e->getMessage());
                    } else {
                        throw $e;
                    }
                } catch (BadResponseException $e) {
                    if ($e->getResponse() && in_array($e->getResponse()->getStatusCode(), [502, 503, 524])) {
                        $this->debug($e->getMessage());
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

            $this->stdErr->writeln(sprintf('View your active projects with: <info>%s project:list</info>', $this->config()->get('application.executable')));

            return 1;
        }

        $progressMessage = new ProgressMessage($this->stdErr);
        $checkInterval = 1;
        $lastCheck = time();
        $progressMessage->show('Loading project information...');
        $project = false;
        while (true) {
            if (time() - $lastCheck >= $checkInterval) {
                $lastCheck = time();
                try {
                    $project = $this->api()->getProject($subscription->project_id);
                    if ($project !== false) {
                        break;
                    } else {
                        $this->debug(sprintf('Project not found: %s (retrying)', $subscription->project_id));
                    }
                } catch (ConnectException $e) {
                    if (strpos($e->getMessage(), 'timed out') !== false) {
                        $this->debug($e->getMessage());
                    } else {
                        throw $e;
                    }
                } catch (BadResponseException $e) {
                    if ($e->getResponse() && in_array($e->getResponse()->getStatusCode(), [403, 502, 524])) {
                        $this->debug(sprintf('Received status code %d from project: %s (retrying)', $e->getResponse()->getStatusCode(), $subscription->project_id));
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
                return 1;
            }
        }
        $progressMessage->done();

        $this->stdErr->writeln("The project is now ready!");
        $output->writeln($subscription->project_id);
        $this->stdErr->writeln('');

        $this->stdErr->writeln("  Region: <info>{$subscription->project_region}</info>");
        $this->stdErr->writeln("  Project ID: <info>{$subscription->project_id}</info>");
        $this->stdErr->writeln("  Project title: <info>{$subscription->project_title}</info>");
        $this->stdErr->writeln("  URL: <info>{$subscription->project_ui}</info>");

        $this->stdErr->writeln("  Git URL: <info>{$project->getGitUrl()}</info>");

        if ($setRemote && $gitRoot !== false) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Setting the remote project for this repository to: %s',
                $this->api()->getProjectLabel($project)
            ));

            /** @var \Platformsh\Cli\Local\LocalProject $localProject */
            $localProject = $this->getService('local.project');
            $localProject->mapDirectory($gitRoot, $project);
        }

        if ($gitRoot === false) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf('To clone the project locally, run: <info>%s get %s</info>', $this->config()->get('application.executable'), OsUtil::escapeShellArg($project->id)));
        }

        return 0;
    }

    /**
     * Checks the organization /can-create API before creating a project.
     *
     * This will show whether billing changes or verification are needed.
     *
     * @param Organization $organization
     * @param InputInterface $input
     * @return bool
     */
    private function checkCanCreate(Organization $organization, InputInterface $input)
    {
        $canCreate = $this->api()->checkCanCreate($organization);
        if ($canCreate['can_create']) {
            return true;
        }
        if ($canCreate['required_action']) {
            $consoleUrl = $this->config()->getWithDefault('service.console_url', '');
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
     *
     * @param string $type
     * @param string $message
     * @param InputInterface $input
     * @return bool True if verification succeeded, false otherwise.
     */
    private function requireVerification($type, $message, InputInterface $input)
    {
        if ($type === 'phone') {
            $this->stdErr->writeln('Phone number verification is required before creating a project.');
            if ($input->isInteractive()) {
                $this->stdErr->writeln('');
                $exitCode = $this->runOtherCommand('auth:verify-phone-number');
                if ($exitCode === 0) {
                    $this->stdErr->writeln('');
                    return true;
                }
            } elseif ($this->config()->has('service.console_url')) {
                $url = $this->config()->get('service.console_url') . '/-/phone-verify';
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please open the following URL in a browser to verify your phone number:');
                $this->stdErr->writeln(sprintf('<info>%s</info>', $url));
                return false;
            }
        } elseif ($type === 'credit-card') {
            $this->stdErr->writeln('Credit card verification is required before creating a project.');
            if ($this->config()->has('service.console_url')) {
                $this->stdErr->writeln('');
                $this->stdErr->writeln('Please use Console to create your first project:');
                $this->stdErr->writeln(sprintf('<info>%s</info>', $this->config()->get('service.console_url')));
            }
        } elseif ($type === 'support' || $type === 'ticket') {
            $this->stdErr->writeln('Verification via a support ticket is required before creating a project.');
            if ($this->config()->has('service.console_url')) {
                $url = $this->config()->get('service.console_url') . '/support';
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
     * @return array
     *   A list of plan machine names.
     */
    protected function getAvailablePlans(SetupOptions $setupOptions = null)
    {
        if (isset($setupOptions)) {
            return $setupOptions->plans;
        }
        if ($this->plansCache !== null) {
            return $this->plansCache;
        }
        $plans = [];
        foreach ($this->api()->getClient()->getPlans() as $plan) {
            $plans[] = $plan->name;
        }
        return $this->plansCache = $plans;
    }

    /**
     * Picks a default plan from a list.
     *
     * @param string[] $availablePlans
     * @return string|null
     */
    protected function getDefaultPlan($availablePlans)
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
    protected function getAvailableRegions(SetupOptions $setupOptions = null)
    {
        $regions = $this->regionsCache !== null
            ? $this->regionsCache
            : $this->regionsCache = $this->api()->getClient()->getRegions();
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

        \usort($available, [Sort::class, 'compareDomains']);

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
     *
     * @param Region $region
     *
     * @return string
     */
    private function regionInfo(Region $region)
    {
        $green = !empty($region->environmental_impact['green']);
        if (!empty($region->datacenter['location'])) {
            $info = $green ? '<fg=green>' . $region->datacenter['location'] . '</>' : $region->datacenter['location'];
        } else {
            $info = $region->id;
        }
        if (!empty($region->provider['name'])) {
            $info .= ' ' .\sprintf('(%s)', $region->provider['name']);
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
    protected function getFields(SetupOptions $setupOptions = null)
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
            'description' => trim("The region where the project will be hosted.\n" . $this->config()->getWithDefault('messages.region_discount', '')),
            'optionsCallback' => function () use ($setupOptions) {
                return $this->getAvailableRegions($setupOptions);
            },
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
            'optionsCallback' => function () use ($setupOptions) {
                return $this->getAvailablePlans($setupOptions);
            },
            'defaultCallback' => function () use ($setupOptions) {
                return $this->getDefaultPlan($this->getAvailablePlans($setupOptions));
            },

            'allowOther' => true,
            'avoidQuestion' => true,
          ]),
          'environments' => new Field('Environments', [
            'optionName' => 'environments',
            'description' => 'The number of environments',
            'default' => 3,
            'validator' => function ($value) {
                return is_numeric($value) && $value > 0 && $value < 50;
            },
            'avoidQuestion' => true,
          ]),
          'storage' => new Field('Storage', [
            'description' => 'The amount of storage per environment, in GiB',
            'default' => 5,
            'validator' => function ($value) {
                return is_numeric($value) && $value > 0 && $value < 1024;
            },
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
            'normalizer' => function ($url) {
                // Provide GitHub as a default.
                if (strpos($url, 'github.com') === 0) {
                    return 'https://github.com' . substr($url, 10);
                }
                if (strpos($url, '//') === false && preg_match('#^[a-z0-9-]+/[a-z0-9-]+$#', $url)) {
                    return 'https://github.com/' . $url;
                }
                return $url;
            },
            'validator' => function ($url) {
                if (strpos($url, 'https://') !== 0 && parse_url($url, PHP_URL_SCHEME) !== 'https') {
                    return 'The initialize repository URL must start with "https://".';
                }
                $response = $this->api()->getExternalHttpClient()->get($url, ['exceptions' => false]);
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
     * Get a numeric option value while ensuring it's a reasonable number.
     *
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param string                                          $optionName
     * @param int                                             $min
     * @param int                                             $max
     *
     * @return float|int
     */
    private function getTimeOption(InputInterface $input, $optionName, $min = 0, $max = 3600)
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
