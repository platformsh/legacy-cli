<?php

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Bot;
use Platformsh\Cli\Exception\NoOrganizationsException;
use Platformsh\Cli\Exception\ProjectNotFoundException;
use Platformsh\Cli\Service\Api;
use Platformsh\Client\Model\Region;
use Platformsh\Client\Model\SetupOptions;
use Platformsh\Client\Model\Subscription\SubscriptionOptions;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCreateCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('project:create')
          ->setAliases(['create'])
          ->setDescription('Create a new project');

        $this->addOrganizationOptions();

        Form::fromArray($this->getFields())->configureInputDefinition($this->getDefinition());

        $this->addOption('set-remote', null, InputOption::VALUE_NONE, 'Set the new project as the remote for this repository (default)');
        $this->addOption('no-set-remote', null, InputOption::VALUE_NONE, 'Do not set the new project as the remote for this repository');

        $this->addOption('check-timeout', null, InputOption::VALUE_REQUIRED, 'The API timeout while checking the project status', 30)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The total timeout for all API checks (0 to disable the timeout)', 900);

        $this->setHelp(<<<EOF
Use this command to create a new project.

An interactive form will be presented with the available options. But if the
command is run non-interactively (with --yes), the form will not be displayed,
and the --region option will be required.

A project subscription will be requested, and then checked periodically (every 3
seconds) until the project has been activated, or until the process times out
(after 15 minutes by default).

If known, the project ID will be output to STDOUT. All other output will be sent
to STDERR.
EOF
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // Check if the user needs phone verification before creating a project.
        $needsVerify = $this->api()->checkUserVerification();
        if ($needsVerify['state']) {
            if ($needsVerify['type'] === 'phone') {
                $this->stdErr->writeln('Phone number verification is required before creating a project.');
                if ($input->isInteractive()) {
                    $this->stdErr->writeln('');
                    $exitCode = $this->runOtherCommand('auth:verify-phone-number');
                    if ($exitCode !== 0) {
                        return 1;
                    }
                    $this->stdErr->writeln('');
                } else if ($this->config()->has('service.console_url')) {
                    $this->stdErr->writeln('');
                    $url = $this->config()->get('service.console_url') . '/-/phone-verify';
                    $this->stdErr->writeln('Please open the following URL in a browser to verify your phone number:');
                    $this->stdErr->writeln(sprintf('<info>%s</info>', $url));
                    return 1;
                }
            } else {
                $this->stdErr->writeln('Verification via Support is required before creating a project.');
                if ($this->config()->has('service.console_url')) {
                    $url = $this->config()->get('service.console_url') . '/support';
                    $this->stdErr->writeln('Please open the following URL in a browser to open a ticket with Support:');
                    $this->stdErr->writeln(sprintf('<info>%s</info>', $url));
                }
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
                if ($input->isInteractive() && $this->config()->isCommandEnabled('organization:create') && $questionHelper->confirm('Do you want to create an organization now?')) {
                    if ($this->runOtherCommand('organization:create') !== 0) {
                        return 1;
                    }
                    $organization = $this->validateOrganizationInput($input, 'create-subscription');
                } else {
                    return 1;
                }
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
            $this->stdErr->writeln('The <error>--set-remote</error> option can only be used inside a Git repository.');
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

            $this->stdErr->writeln('Git repository detected: <info>' . $gitRoot . '</info>');
            if ($currentProject) {
                $this->stdErr->writeln(sprintf('The remote project is currently: %s', $this->api()->getProjectLabel($currentProject, 'comment')));
            }
            $this->stdErr->writeln('');

            if ($setRemote) {
                $this->stdErr->writeln(sprintf('The new project <info>%s</info> will be set as the remote for this repository.', $options['title']));
            } elseif ($currentProject) {
                $setRemote = $questionHelper->confirm(sprintf(
                    'Switch the remote project for this repository from <comment>%s</comment> to the new project <comment>%s</comment>?',
                    $this->api()->getProjectLabel($currentProject, false),
                    $options['title']
                ), false);
            } else {
                $setRemote = $questionHelper->confirm(sprintf(
                    'Set the new project <info>%s</info> as the remote for this repository?',
                    $options['title']
                ));
            }
            $this->stdErr->writeln('');
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
                }
            }
            // Check the total timeout.
            $timedOut = $totalTimeout ? time() - $start > $totalTimeout : false;
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

        $this->stdErr->writeln("The project is now ready!");
        $output->writeln($subscription->project_id);
        $this->stdErr->writeln('');

        $this->stdErr->writeln("  Region: <info>{$subscription->project_region}</info>");
        $this->stdErr->writeln("  Project ID: <info>{$subscription->project_id}</info>");
        $this->stdErr->writeln("  Project title: <info>{$subscription->project_title}</info>");
        $this->stdErr->writeln("  URL: <info>{$subscription->project_ui}</info>");

        $project = $this->api()->getProject($subscription->project_id);
        if ($project !== false) {
            $this->stdErr->writeln("  Git URL: <info>{$project->getGitUrl()}</info>");

            // Temporary workaround for the default environment's title.
            /** @todo remove this from API version 12 */
            if ($project->default_branch !== 'master') {
                try {
                    $env = $project->getEnvironment($project->default_branch);
                    if ($env->title === 'Master') {
                        $prev = $env->title;
                        $new = $project->default_branch;
                        $this->debug(\sprintf('Updating the title of environment %s from %s to %s', $env->id, $prev, $new));
                        $env->update(['title' => $new]);
                    }
                } catch (\Exception $e) {
                    $this->debug('Error: ' . $e->getMessage());
                }
            }
        }

        if ($setRemote && $gitRoot !== false && $project !== false) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln(sprintf(
                'Setting the remote project for this repository to: %s',
                $this->api()->getProjectLabel($project)
            ));

            /** @var \Platformsh\Cli\Local\LocalProject $localProject */
            $localProject = $this->getService('local.project');
            $localProject->mapDirectory($gitRoot, $project);
        }

        return 0;
    }

    /**
     * Return a list of plans.
     *
     * The default list is in the config `service.available_plans`. This is
     * replaced at runtime by an API call.
     *
     * @param bool $runtime
     * @param SetupOptions|null $setupOptions
     *
     * @return array
     *   A list of plan machine names.
     */
    protected function getAvailablePlans($runtime = false, SetupOptions $setupOptions = null)
    {
        if (isset($setupOptions)) {
            return $setupOptions->plans;
        }
        if (!$runtime) {
            return (array) $this->config()->get('service.available_plans');
        }

        $plans = [];
        foreach ($this->api()->getClient()->getPlans() as $plan) {
            $plans[] = $plan->name;
        }
        return $plans;
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
        $regions = $this->api()->getClient()->getRegions();
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

        \usort($available, [Api::class, 'compareDomains']);

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
        if (!empty($region->datacenter['location'])) {
            $info = $region->datacenter['location'];
        } else {
            $info = $region->id;
        }
        if (!empty($region->provider['name'])) {
            $info .= \sprintf(' (<fg=cyan>%s</>)', $region->provider['name']);
        }
        if (!empty($region->environmental_impact['carbon_intensity'])) {
            $info .= \sprintf(' [<fg=green>%s</> gC02eq/kWh]', $region->environmental_impact['carbon_intensity']);
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
            'description' => 'The region where the project will be hosted',
            'optionsCallback' => function () use ($setupOptions) {
                return $this->getAvailableRegions($setupOptions);
            },
            'allowOther' => true,
          ]),
          'plan' => new OptionsField('Plan', [
            'optionName' => 'plan',
            'description' => 'The subscription plan',
            'options' => $this->getAvailablePlans(),
            'optionsCallback' => function () use ($setupOptions) {
                return $this->getAvailablePlans(true, $setupOptions);
            },
            'default' => $this->getDefaultPlan($this->getAvailablePlans(false, $setupOptions)),
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
