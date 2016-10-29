<?php

namespace Platformsh\Cli\Command\Project;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\Bot;
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
        return parent::isEnabled()
            && self::$config->has('experimental.enable_create')
            && self::$config->get('experimental.enable_create');
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
        /** @var \Platformsh\Cli\Helper\QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');

        $options = $this->form->resolveOptions($input, $output, $questionHelper);

        $estimate = $this->getEstimate($options['plan'], $options['storage'], $options['environments']);
        if (!$estimate) {
            $costConfirm = "Failed to estimate project cost";
        } else {
            $costConfirm = "The estimated monthly cost of this project is: <comment>{$estimate['total']}</comment>";
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
            self::$config->get('service.name'),
            $subscription->id
        ));

        $this->stdErr->writeln(sprintf(
            "\nThe %s Bot is activating your project\n",
            self::$config->get('service.name')
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
     * Get a cost estimate for the new project.
     *
     * @param string $plan
     * @param int $storage
     * @param int $environments
     *
     * @return array|false
     */
    protected function getEstimate($plan, $storage, $environments)
    {
        $apiUrl = self::$config->get('api.accounts_api_url');
        if (!$parts = parse_url($apiUrl)) {
            throw new \RuntimeException('Failed to parse URL: ' . $apiUrl);
        }
        $baseUrl = $parts['scheme'] . '://' . $parts['host'];
        $estimateUrl = $baseUrl . '/platform/estimate';
        $client = new Client();
        $response = $client->get($estimateUrl, [
            'query' => [
                'plan' => strtoupper('PLATFORM-ENVIRONMENT-' . $plan),
                'storage' => $storage,
                'environments' => $environments,
                'user_licenses' => 1,
            ],
            'exceptions' => false,
        ]);
        if ($response->getStatusCode() != 200) {
            return false;
        }

        return $response->json();
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
        if (self::$config->has('experimental.available_plans')) {
            return self::$config->get('experimental.available_plans');
        }

        return Subscription::$availablePlans;
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
            'options' => Subscription::$availableRegions,
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
