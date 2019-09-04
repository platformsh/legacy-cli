<?php

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Bot;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProjectCreateCommand extends CommandBase
{
    /** @var Form */
    protected $form;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('project:create')
          ->setAliases(['create'])
          ->setDescription('Create a new project');

        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());

        $this->addOption('check-timeout', null, InputOption::VALUE_REQUIRED, 'The API timeout while checking the project status', 30)
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The total timeout for all API checks (0 to disable the timeout)', 900)
            ->addOption('template', null, InputOption::VALUE_OPTIONAL, 'Choose a starting template or provide a url of one.', false);

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
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $options = $this->form->resolveOptions($input, $output, $questionHelper);
        $template = $input->getOption('template');
        
        if ($template !== false) {
            if (empty(parse_url($template, PHP_URL_PATH))) {
                $temp_provided = true;
            }
            else {
                $temp_provided = false;
            }
            $this->template_form = Form::fromArray($this->getTemplateFields($temp_provided));
            $template_options = $this->template_form->resolveOptions($input, $output, $questionHelper);
        }

        $estimate = $this->api()
            ->getClient()
            ->getSubscriptionEstimate($options['plan'], $options['storage'], $options['environments'], 1);

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

        // Grab the url of the yaml file.
        if (!empty($template_options['template_url_from_catalog'])) {
            $options['catalog'] = $template_options['template_url_from_catalog'];
        }
        else if (!empty($template_options['template_url'])) {
            $options['catalog'] = $template_options['template_url'];
        }
        
        $subscription = $this->api()->getClient()
            ->createSubscription(
                $options['region'],
                $options['plan'],
                $options['title'],
                $options['storage'] * 1024,
                $options['environments'],
                $options['catalog']
            );

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
                    $subscription->refresh(['timeout' => $checkTimeout, 'exceptions' => false]);
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

        if ($template_options['initialize']) {
            // Make sure nothing happens if the all important subscription info
            // is not available for some reason.
            $project = $this->api()->getProject($subscription->project_id);
            if (!empty($project)) {
                $environment = $this->api()->getEnvironment('master', $project);
                if (isset($subscription->project_options['initialize']['profile']) && isset( $subscription->project_options['initialize']['repository'])) {
                    $environment->initialize($subscription->project_options['initialize']['profile'], $subscription->project_options['initialize']['repository']);
                    $this->api()->clearEnvironmentsCache($environment->project);
                    $this->stdErr->writeln("The project has been initialized and is ready!");
                }
                else {
                    $this->stdErr->writeln("The project could not be initialized at this time due to missing profile and repository information.");
                }
            }
        }
        else {
            $this->stdErr->writeln("The project is now ready!");
        }

        $output->writeln($subscription->project_id);
        $this->stdErr->writeln('');

        if (!empty($subscription->project_options['initialize'])) {
            $this->stdErr->writeln("  Template: <info>{$subscription->project_options[initialize][repository] }</info>");
        }
        $this->stdErr->writeln("  Region: <info>{$subscription->project_region}</info>");
        $this->stdErr->writeln("  Project ID: <info>{$subscription->project_id}</info>");
        $this->stdErr->writeln("  Project title: <info>{$subscription->project_title}</info>");
        $this->stdErr->writeln("  URL: <info>{$subscription->project_ui}</info>");

        return 0;
    }

    /**
     * Return a list of plans.
     *
     * The default list is in the config `service.available_plans`. This is
     * replaced at runtime by an API call.
     *
     * @param bool $runtime
     *
     * @return array
     */
    protected function getAvailablePlans($runtime = false)
    {
        static $plans;
        if (is_array($plans)) {
            return $plans;
        }

        if (!$runtime) {
            return (array) $this->config()->get('service.available_plans');
        }

        $plans = [];
        foreach ($this->api()->getClient()->getPlans() as $plan) {
            if ($plan->hasProperty('price', false)) {
                $plans[$plan->name] = sprintf('%s (%s)', $plan->label, $plan->price->__toString());
            } else {
                $plans[$plan->name] = $plan->label;
            }
        }

        return $plans;
    }

    /**
     * Return a list of regions.
     *
     * The default list is in the config `service.available_regions`. This is
     * replaced at runtime by an API call.
     *
     * @param bool $runtime
     *
     * @return array
     */
    protected function getAvailableRegions($runtime = false)
    {
        if ($runtime) {
            $regions = [];
            foreach ($this->api()->getClient()->getRegions() as $region) {
                if ($region->available) {
                    $regions[$region->id] = $region->label;
                }
            }
        } else {
            $regions = (array) $this->config()->get('service.available_regions');
        }

        return $regions;
    }

    /**
     * Return the catalog.
     *
     * The default list is in the config `service.catalog`. This is
     * replaced at runtime by an API call.
     *
     * @param bool $runtime
     *
     * @return array
     */
    protected function getDefaultCatalog($runtime = false)
    {
        if ($runtime) {
            $catalog = [];
            $catalog_items = $this->api()->getClient()->getCatalog()->getData();
            if (!empty($catalog_items)) {
                foreach ($catalog_items as $item) {
                    if (isset($item['info']) && isset($item['template'])) {
                        $catalog[$item['template']] = $item['info']['name'];
                    }
                }
            }
            $catalog['empty'] = 'Empty Project';
        } else {
            $catalog = (array) $this->config()->get('service.catalog');
        }

        if (empty($catalog)) {
            // Should we throw an error here? Do we want to kill the process?
        }

        return $catalog;
    }

    /**
     * Returns a list of ConsoleForm form fields for this command.
     *
     * @return Field[]
     */
    protected function getFields()
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
              'options' => $this->getAvailableRegions(),
              'optionsCallback' => function () {
                  return $this->getAvailableRegions(true);
              },
            ]),
            'plan' => new OptionsField('Plan', [
              'optionName' => 'plan',
              'description' => 'The subscription plan',
              'options' => $this->getAvailablePlans(),
              'optionsCallback' => function () {
                  return $this->getAvailablePlans(true);
              },
              'default' => in_array('development', $this->getAvailablePlans()) ? 'development' : null,
              'allowOther' => true,
            ]),
            'environments' => new Field('Environments', [
              'optionName' => 'environments',
              'description' => 'The number of environments',
              'default' => 3,
              'validator' => function ($value) {
                  return is_numeric($value) && $value > 0 && $value < 50;
              },
            ]),
            'storage' => new Field('Storage', [
              'description' => 'The amount of storage per environment, in GiB',
              'default' => 5,
              'validator' => function ($value) {
                  return is_numeric($value) && $value > 0 && $value < 1024;
              },
            ]),
          ];
    }

     /**
     * Returns a list of ConsoleForm form fields for this command.
     *
     * @return Field[]
     */
    protected function getTemplateFields($url_provided)
    {
        $fields = [];
        
        if (!$url_provided) {
            $fields['template_option'] = new OptionsField('Template Options', [
                'optionName' => 'template_option',
                'options' => [
                    'Provide your own template url.',
                    'Choose a template from the catalog.',
                    'No template at this time.',
                ],
                'description' => 'Choose a template, provide a url or choose not to use one.',
                'includeAsOption' => false,
            ]);
    
            $fields['template_url_from_catalog'] = new OptionsField('Template (from a catalog)', [
            'optionName' => 'template_url_from_catalog',
            'conditions' => [
                'template_option' => [
                    'Choose a template from the catalog.'
                ],
            ],
            'description' => 'The template from which to create your project or your own blank project.',
            'options' => $this->getDefaultCatalog(),
            'asChoice' => FALSE,
            'optionsCallback' => function () {
                return $this->getDefaultCatalog(true);
                },
            ]);

            $fields['template_url'] = new UrlField('Template URL', [
            'optionName' => 'template_url',
            'conditions' => [
                'template_option' => [
                    'Provide your own template url.'
                ],
            ],
            'description' => 'The template url',
            'questionLine' => 'What is the URL of the template?',
            ]);  
        }
        $fields['initialize'] = new BooleanField('Initialize', [
        'optionName' => 'initialized',
        'conditions' => [
            'template_option' => [
                'Provide your own template url.',
                'Choose a template from the catalog.',
            ],
        ],
        'description' => 'Initialize this project from a template?',
        'questionLine' => 'Initialize this project from a template?',
        ]);

        return $fields;
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
