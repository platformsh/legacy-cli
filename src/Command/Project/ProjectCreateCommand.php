<?php

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Console\Bot;
use Platformsh\Client\Model\Subscription;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
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
            && $config->has('experimental.enable_create')
            && $config->get('experimental.enable_create');
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
            'The estimated monthly cost of this project is: <comment>%s</comment>'
            . "\n\n"
            . 'Are you sure you want to continue?',
            $estimate['total']
        );
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
        $start = time();
        while ($subscription->isPending() && time() - $start < 300) {
            $bot->render();
            if (!isset($lastCheck) || time() - $lastCheck >= 2) {
                try {
                    $subscription->refresh(['timeout' => 5, 'exceptions' => false]);
                    $lastCheck = time();
                } catch (ConnectException $e) {
                    if (strpos($e->getMessage(), 'timed out') !== false) {
                        $this->stdErr->writeln('<warning>' . $e->getMessage() . '</warning>');
                    } else {
                        throw $e;
                    }
                }
            }
        }
        $this->stdErr->writeln("");

        if (!$subscription->isActive()) {
            $this->stdErr->writeln("<error>The project failed to activate</error>");
            return 1;
        }

        $this->stdErr->writeln("The project is now ready!");
        $this->stdErr->writeln("  Region: <info>{$subscription->project_region}</info>");
        $this->stdErr->writeln("  Project ID: <info>{$subscription->project_id}</info>");
        $this->stdErr->writeln("  Project title: <info>{$subscription->project_title}</info>");
        $this->stdErr->writeln("  URL: <info>{$subscription->project_ui}</info>");
        return 0;
    }

    /**
     * Return a list of plans.
     *
     * The default list (from the API client) can be overridden by user config.
     *
     * @return string[]
     */
    protected function getAvailablePlans()
    {
        $config = $this->config();
        if ($config->has('experimental.available_plans')) {
            return $config->get('experimental.available_plans');
        }

        return Subscription::$availablePlans;
    }

    /**
     * Return a list of regions.
     *
     * The default list (from the API client) can be overridden by user config.
     *
     * @return string[]
     */
    protected function getAvailableRegions()
    {
        $config = $this->config();
        if ($config->has('experimental.available_regions')) {
            return $config->get('experimental.available_regions');
        }

        return Subscription::$availableRegions;
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
            'default' => 'Untitled Project',
          ]),
          'region' => new OptionsField('Region', [
            'optionName' => 'region',
            'description' => 'The region where the project will be hosted',
            'options' => $this->getAvailableRegions(),
          ]),
          'plan' => new OptionsField('Plan', [
            'optionName' => 'plan',
            'description' => 'The subscription plan',
            'options' => $this->getAvailablePlans(),
            'default' => 'development',
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
}
