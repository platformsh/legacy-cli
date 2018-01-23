<?php

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Bot;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
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
    public function isEnabled()
    {
        $config = $this->config();

        return parent::isEnabled()
            && $config->isExperimentEnabled('enable_create');
    }

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
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'The total timeout for all API checks', 900);

    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');

        $options = $this->form->resolveOptions($input, $output, $questionHelper);

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

        $subscription = $this->api()->getClient()
            ->createSubscription(
                $options['region'],
                $options['plan'],
                $options['title'],
                $options['storage'] * 1024,
                $options['environments']
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
                try {
                    // The API call will timeout after $checkTimeout seconds.
                    $subscription->refresh(['timeout' => $checkTimeout, 'exceptions' => false]);
                    $lastCheck = time();
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

            if (isset($subscription->project_id)) {
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
        return 0;
    }

    /**
     * Return a list of plans.
     *
     * The default list is in the config `service.available_plans`. This can be
     * overridden by the user config `experimental.available_plans`.
     *
     * @return string[]
     */
    protected function getAvailablePlans()
    {
        $config = $this->config();
        if ($config->has('experimental.available_plans')) {
            return $config->get('experimental.available_plans');
        }

        return $config->get('service.available_plans');
    }

    /**
     * Return a list of regions.
     *
     * The default list is in the config `service.available_regions`. This can
     * be overridden by the user config `experimental.available_regions`.
     *
     * @return string[]
     */
    protected function getAvailableRegions()
    {
        $config = $this->config();
        if ($config->has('experimental.available_regions')) {
            return $config->get('experimental.available_regions');
        }

        return $config->get('service.available_regions');
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
            'allowOther' => true,
          ]),
          'plan' => new OptionsField('Plan', [
            'optionName' => 'plan',
            'description' => 'The subscription plan',
            'options' => $this->getAvailablePlans(),
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
