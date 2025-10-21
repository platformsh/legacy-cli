<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Selector\Selection;
use Platformsh\Cli\Selector\Selector;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Service\Api;
use Symfony\Contracts\Service\Attribute\Required;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Utils;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Integration;
use Platformsh\Client\Model\Project;
use Platformsh\ConsoleForm\Exception\ConditionalFieldException;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\EmailAddressField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\FileField;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Output\OutputInterface;

abstract class IntegrationCommandBase extends CommandBase
{
    protected const DEFAULT_LIST_LIMIT = 10; // Display a digestible number of activities by default.
    protected const DEFAULT_FIND_LIMIT = 25; // This is the current limit per page of results.

    private Selector $selector;
    private Table $table;
    private QuestionHelper $questionHelper;
    private PropertyFormatter $propertyFormatter;
    private LocalProject $localProject;
    private Api $api;

    private ?Form $form = null;

    /** @var array<string, string> */
    private array $bitbucketAccessTokens = [];

    protected ?Selection $selection = null;

    #[Required]
    public function autowire(Api $api, LocalProject $localProject, PropertyFormatter $propertyFormatter, QuestionHelper $questionHelper, Selector $selector, Table $table): void
    {
        $this->api = $api;
        $this->localProject = $localProject;
        $this->propertyFormatter = $propertyFormatter;
        $this->questionHelper = $questionHelper;
        $this->table = $table;
        $this->selector = $selector;
    }

    protected function selectIntegration(Project $project, ?string $id, bool $interactive): Integration|false
    {
        if (!$id && !$interactive) {
            $this->stdErr->writeln('An integration ID is required.');

            return false;
        } elseif (!$id) {
            $integrations = $project->getIntegrations();
            if (empty($integrations)) {
                $this->stdErr->writeln('No integrations found.');

                return false;
            }
            $choices = [];
            foreach ($integrations as $integration) {
                $choices[$integration->id] = sprintf('%s (%s)', $integration->id, $integration->type);
            }
            $id = $this->questionHelper->choose($choices, 'Enter a number to choose an integration:');
        }

        $integration = $project->getIntegration($id);
        if (!$integration) {
            try {
                /** @var Integration $integration */
                $integration = $this->api->matchPartialId($id, $project->getIntegrations(), 'Integration');
            } catch (\InvalidArgumentException $e) {
                $this->stdErr->writeln($e->getMessage());
                return false;
            }
        }

        return $integration;
    }

    protected function getForm(): Form
    {
        if (!isset($this->form)) {
            $this->form = Form::fromArray($this->getFields());
        }

        return $this->form;
    }

    /**
     * @param ConditionalFieldException $e
     *
     * @return int
     */
    protected function handleConditionalFieldException(ConditionalFieldException $e): int
    {
        $previousValues = $e->getPreviousValues();
        $field = $e->getField();
        $conditions = $field->getConditions();
        if (isset($previousValues['type']) && isset($conditions['type']) && !in_array($previousValues['type'], (array) $conditions['type'])) {
            $this->stdErr->writeln(\sprintf(
                'The option <error>--%s</error> cannot be used with the integration type <comment>%s</comment>.',
                $field->getOptionName(),
                $previousValues['type'],
            ));
            return 1;
        }
        throw $e;
    }

    /**
     * Performs extra logic on values after the form is complete.
     *
     * @param array<string, mixed> $values
     * @param Integration|null $integration
     *
     * @return array<string, mixed>
     */
    protected function postProcessValues(array $values, ?Integration $integration = null): array
    {
        // Find the integration type.
        $type = $values['type'] ?? $integration?->type;

        // Process Bitbucket Server values.
        if ($type === 'bitbucket_server') {
            // Translate bitbucket_url into url.
            if (isset($values['bitbucket_url'])) {
                $values['url'] = $values['bitbucket_url'];
                unset($values['bitbucket_url']);
            }
            // Split bitbucket_server "repository" into project/repository.
            if (isset($values['repository']) && str_contains(substr((string) $values['repository'], 1), '/')) {
                [$values['project'], $values['repository']] = explode('/', (string) $values['repository'], 2);
            }
        }

        // Process syslog integer values.
        foreach (['facility', 'port'] as $key) {
            if (isset($values[$key])) {
                $values[$key] = (int) $values[$key];
            }
        }

        // Process HTTP headers.
        if (isset($values['headers'])) {
            $map = [];
            foreach ($values['headers'] as $header) {
                $parts = explode(':', (string) $header, 2);
                $map[$parts[0]] = isset($parts[1]) ? ltrim($parts[1]) : '';
            }
            $values['headers'] = $map;
        }

        // Ensure prune_branches is sent as false if fetch_branches is also false.
        // TODO remove this when the API default is fixed to no longer need this
        if (isset($values['fetch_branches']) && $values['fetch_branches'] === false && !isset($values['prune_branches'])) {
            $values['prune_branches'] = false;
        }

        return $values;
    }

    /**
     * Returns a list of integration capability information on the selected project, if any.
     *
     * @return array{enabled: bool, config?: array<string, array{enabled: bool}>}
     */
    private function selectedProjectIntegrationCapabilities(): array
    {
        return $this->api
            ->getProjectCapabilities($this->selection->getProject())
            ->integrations;
    }

    /**
     * @return Field[]
     */
    private function getFields(): array
    {
        $allSupportedTypes = [
            'bitbucket',
            'bitbucket_server',
            'github',
            'gitlab',
            'webhook',
            'health.email',
            'health.pagerduty',
            'health.slack',
            'health.webhook',
            'httplog',
            'script',
            'newrelic',
            'splunk',
            'sumologic',
            'syslog',
            'otlplog',
        ];

        return [
            'type' => new OptionsField('Integration type', [
                'optionName' => 'type',
                'description' => 'The integration type',
                'questionLine' => '',
                'options' => $allSupportedTypes,
                'validator' => function ($value) use ($allSupportedTypes): ?string {
                    // If the type isn't supported at all, fall back to the default validator.
                    if (!in_array($value, $allSupportedTypes, true)) {
                        return null;
                    }
                    // If the type is supported, check if it is available on the project.
                    if ($this->selection->hasProject()) {
                        $integrations = $this->selectedProjectIntegrationCapabilities();
                        if (!empty($integrations['enabled']) && empty($integrations['config'][$value]['enabled'])) {
                            return "The integration type '$value' is not available on this project.";
                        }
                    }
                    return null;
                },
                'optionsCallback' => function () use ($allSupportedTypes): array {
                    if ($this->selection->hasProject()) {
                        $integrations = $this->selectedProjectIntegrationCapabilities();
                        if (!empty($integrations['enabled']) && !empty($integrations['config'])) {
                            return array_filter($allSupportedTypes, fn($type): bool => !empty($integrations['config'][$type]['enabled']));
                        }
                    }
                    return $allSupportedTypes;
                },
            ]),
            'base_url' => new UrlField('Base URL', [
                'conditions' => ['type' => ['github', 'gitlab']],
                'description' => 'The base URL of the server installation',
                'required' => false,
                'avoidQuestion' => true,
            ]),
            'bitbucket_url' => new UrlField('Base URL', [
                'conditions' => ['type' => 'bitbucket_server'],
                'optionName' => 'bitbucket-url',
                'description' => 'The base URL of the Bitbucket Server installation',
            ]),
            'username' => new Field('Username', [
                'conditions' =>  ['type' => [
                    'bitbucket_server',
                ]],
                'description' => 'The Bitbucket Server username',
            ]),
            'token' => new Field('Token', [
                'conditions' => ['type' => [
                    'github',
                    'gitlab',
                    'health.slack',
                    'bitbucket_server',
                    'splunk',
                ]],
                'description' => 'An authentication or access token for the integration',
            ]),
            'key' => new Field('OAuth consumer key', [
                'optionName' => 'key',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer key',
                'valueKeys' => ['app_credentials', 'key'],
            ]),
            'secret' => new Field('OAuth consumer secret', [
                'optionName' => 'secret',
                'conditions' => ['type' => [
                    'bitbucket',
                ]],
                'description' => 'A Bitbucket OAuth consumer secret',
                'valueKeys' => ['app_credentials', 'secret'],
            ]),
            'license_key' => new Field('License key', [
                'conditions' => ['type' => [
                    'newrelic',
                ]],
                'description' => 'The New Relic Logs license key',
            ]),
            'project' => new Field('Project', [
                'optionName' => 'server-project',
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'The project (e.g. \'namespace/repo\')',
                'validator' => fn($string): bool => str_contains(substr((string) $string, 1), '/'),
            ]),
            'repository' => new Field('Repository', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'The repository to track (e.g. \'owner/repository\')',
                'questionLine' => 'The repository (e.g. \'owner/repository\')',
                'validator' => fn($string): bool => substr_count((string) $string, '/', 1) === 1,
                'normalizer' => function ($string) {
                    if (preg_match('#^https?://#', $string)) {
                        return parse_url($string, PHP_URL_PATH);
                    }

                    return $string;
                },
            ]),
            'build_merge_requests' => new BooleanField('Build merge requests', [
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'GitLab: build merge requests as environments',
                'questionLine' => 'Build every merge request as an environment',
            ]),
            'build_pull_requests' => new BooleanField('Build pull requests', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                ]],
                'description' => 'Build every pull request as an environment',
            ]),
            'build_draft_pull_requests' => new BooleanField('Build draft pull requests', [
                'conditions' => [
                    'type' => [
                        'github',
                    ],
                    'build_pull_requests' => true,
                ],
            ]),
            'build_pull_requests_post_merge' => new BooleanField('Build pull requests post-merge', [
                'conditions' => [
                    'type' => [
                        'github',
                    ],
                    'build_pull_requests' => true,
                ],
                'default' => false,
                'description' => 'Build pull requests based on their post-merge state',
            ]),
            'build_wip_merge_requests' => new BooleanField('Build WIP merge requests', [
                'conditions' => [
                    'type' => [
                        'gitlab',
                    ],
                    'build_merge_requests' => true,
                ],
                'description' => 'GitLab: build WIP merge requests',
                'questionLine' => 'Build WIP (work in progress) merge requests',
            ]),
            'merge_requests_clone_parent_data' => new BooleanField('Clone data for merge requests', [
                'optionName' => 'merge-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'gitlab',
                    ],
                    'build_merge_requests' => true,
                ],
                'description' => 'GitLab: clone data for merge requests',
                'questionLine' => "Clone the parent environment's data for merge requests",
            ]),
            'pull_requests_clone_parent_data' => new BooleanField('Clone data for pull requests', [
                'optionName' => 'pull-requests-clone-parent-data',
                'conditions' => [
                    'type' => [
                        'github',
                        'bitbucket_server',
                    ],
                    'build_pull_requests' => true,
                ],
                'description' => "Clone the parent environment's data for pull requests",
            ]),
            'resync_pull_requests' => new BooleanField('Re-sync pull requests', [
                'optionName' => 'resync-pull-requests',
                'conditions' => [
                    'type' => [
                        'bitbucket',
                    ],
                    'build_pull_requests' => true,
                ],
                'default' => false,
                'description' => "Re-sync pull request environment data on every build",
            ]),
            'fetch_branches' => new BooleanField('Fetch branches', [
                'conditions' => ['type' => [
                    'bitbucket',
                    'bitbucket_server',
                    'github',
                    'gitlab',
                ]],
                'description' => 'Fetch all branches from the remote (as inactive environments)',
            ]),
            'prune_branches' => new BooleanField('Prune branches', [
                'conditions' => [
                    'type' => [
                        'bitbucket',
                        'bitbucket_server',
                        'github',
                        'gitlab',
                    ],
                    'fetch_branches' => true,
                ],
                'description' => 'Delete branches that do not exist on the remote',
            ]),
            'environment_init_resources' => new OptionsField('Initialization resources', [
                'conditions' => [
                    'type' => [
                        'bitbucket',
                        'bitbucket_server',
                        'github',
                        'gitlab',
                    ],
                ],
                'optionName' => 'resources-init',
                'description' => 'The resources to use when initializing a new service',
                'options' => ['minimum', 'default', 'manual', 'parent'],
                'default' => 'parent',
                'required' => false,
                'avoidQuestion' => true,
            ]),
            'url' => new UrlField('URL', [
                'conditions' => ['type' => [
                    'health.webhook',
                    'httplog',
                    'newrelic',
                    'sumologic',
                    'splunk',
                    'webhook',
                    'otlplog',
                ]],
                'description' => 'The URL or API endpoint for the integration',
            ]),
            'shared_key' => new Field('Shared key', [
                'conditions' => ['type' => [
                    'health.webhook',
                    'webhook',
                ]],
                'description' => 'Webhook: the JWS shared secret key',
                'questionLine' => 'Optionally, enter a JWS shared secret key, for validating webhook requests',
                'required' => false,
            ]),
            'script' => new FileField('Script file', [
                'conditions' => ['type' => [
                    'script',
                ]],
                'optionName' => 'file',
                'allowedExtensions' => ['.js', ''],
                'contentsAsValue' => true,
                'description' => 'The name of a local file that contains the script to upload',
                'normalizer' => function ($value) {
                    if (getenv('HOME') && str_starts_with($value, '~/')) {
                        return getenv('HOME') . '/' . substr($value, 2);
                    }

                    return $value;
                },
            ]),
            'events' => new ArrayField('Events', [
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['*'],
                'description' => 'A list of events to act on, e.g. environment.push',
                'optionName' => 'events',
            ]),
            'states' => new ArrayField('States', [
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['complete'],
                'description' => 'A list of states to act on, e.g. pending, in_progress, complete',
                'optionName' => 'states',
            ]),
            'environments' => new ArrayField('Included environments', [
                'optionName' => 'environments',
                'conditions' => ['type' => [
                    'webhook',
                    'script',
                ]],
                'default' => ['*'],
                'description' => 'The environment IDs to include',
            ]),
            'excluded_environments' => new ArrayField('Excluded environments', [
                'conditions' => ['type' => [
                    'webhook',
                ]],
                'default' => [],
                'description' => 'The environment IDs to exclude',
                'required' => false,
            ]),
            'from_address' => new EmailAddressField('From address', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => '[Optional] Custom From address for alert emails',
                'required' => false,
            ]),
            'recipients' => new ArrayField('Recipients', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The recipient email address(es)',
                'validator' => function ($emails): string|true {
                    $invalid = array_filter($emails, function ($email): bool {
                        // The special placeholders #viewers and #admins are
                        // valid recipients.
                        if (in_array($email, ['#viewers', '#admins'])) {
                            return false;
                        }

                        return !filter_var($email, FILTER_VALIDATE_EMAIL);
                    });
                    if (count($invalid)) {
                        return sprintf('Invalid email address(es): %s', implode(', ', $invalid));
                    }

                    return true;
                },
            ]),
            'channel' => new Field('Channel', [
                'conditions' => ['type' => [
                    'health.slack',
                ]],
                'description' => 'The Slack channel',
            ]),
            'routing_key' => new Field('Routing key', [
                'conditions' => ['type' => [
                    'health.pagerduty',
                ]],
                'description' => 'The PagerDuty routing key',
            ]),
            'category' => new Field('Category', [
                'conditions' => ['type' => 'sumologic'],
                'description' => 'The Sumo Logic category, used for filtering',
                'required' => false,
                'normalizer' => fn($val): string => (string) $val,
            ]),
            'index' => new Field('Index', [
                'conditions' => ['type' => 'splunk'],
                'description' => 'The Splunk index',
            ]),
            'sourcetype' => new Field('Source type', [
                'optionName' => 'sourcetype',
                'conditions' => ['type' => 'splunk'],
                'description' => 'The Splunk event source type',
                'required' => false,
            ]),
            'protocol' => new OptionsField('Protocol', [
                'conditions' => ['type' => ['syslog']],
                'description' => 'Syslog transport protocol',
                'required' => false,
                'default' => 'tls',
                'options' => ['tcp', 'udp', 'tls'],
            ]),
            'host' => new Field('Host', [
                'optionName' => 'syslog-host',
                // N.B. syslog is an actual PHP function name so this is wrapped in extra array brackets, to avoid is_callable() passing
                'conditions' => ['type' => ['syslog']],
                'description' => 'Syslog relay/collector host',
                'autoCompleterValues' => ['localhost'],
            ]),
            'port' => new Field('Port', [
                'optionName' => 'syslog-port',
                'conditions' => ['type' => ['syslog']],
                'description' => 'Syslog relay/collector port',
                'autoCompleterValues' => ['6514'],
                'validator' => fn($value) => is_numeric($value) && $value >= 0 && $value <= 65535 ? true : "Invalid port number: $value",
            ]),
            'facility' => new Field('Facility', [
                'conditions' => ['type' => ['syslog']],
                'description' => 'Syslog facility',
                'default' => 1,
                'required' => false,
                'avoidQuestion' => true,
                'validator' => fn($value) => is_numeric($value) && $value >= 0 && $value <= 23 ? true : "Invalid syslog facility code: $value",
            ]),
            'message_format' => new OptionsField('Message format', [
                'conditions' => ['type' => ['syslog']],
                'description' => 'Syslog message format',
                'options' => ['rfc3164' => 'RFC 3164', 'rfc5424' => 'RFC 5424'],
                'default' => 'rfc5424',
                'required' => false,
                'avoidQuestion' => true,
            ]),
            'auth_mode' => new OptionsField('Authentication mode', [
                'conditions' => ['type' => ['syslog']],
                'optionName' => 'auth-mode',
                'required' => false,
                'options' => ['prefix', 'structured_data'],
                'default' => 'prefix',
            ]),
            'auth_token' => new Field('Authentication token', [
                'conditions' => ['type' => ['syslog']],
                'optionName' => 'auth-token',
                'required' => false,
            ]),
            'tls_verify' => new BooleanField('Verify TLS', [
                'conditions' => ['type' => [
                    'httplog',
                    'newrelic',
                    'splunk',
                    'sumologic',
                    'syslog',
                    'otlplog',
                ]],
                'description' => 'Whether HTTPS certificate verification should be enabled (recommended)',
                'questionLine' => 'Should HTTPS certificate verification be enabled (recommended)',
                'default' => true,
                'required' => false,
                'avoidQuestion' => true,
            ]),
            'headers' => new ArrayField('HTTP header', [
                'optionName' => 'header',
                'conditions' => ['type' => [
                    'httplog',
                    'otlplog',
                ]],
                'description' => 'HTTP header(s) to use in POST requests. Separate names and values with a colon (:).',
                'required' => false,
                // Override the default split pattern (which splits a comma-separated
                // value), as HTTP headers can contain commas.
                'splitPattern' => ArrayField::SPLIT_PATTERN_NEWLINE,
                // As multiple HTTP headers are separated by newlines, but the
                // QuestionHelper reads only one input line, it is not
                // practical to ask for headers interactively.
                'avoidQuestion' => false,
                'validator' => function ($headers): string|true {
                    $uniqueNames = [];
                    foreach ($headers as $header) {
                        $parts = \explode(':', $header, 2);
                        if (isset($uniqueNames[$parts[0]])) {
                            return 'Duplicate header name: ' . $parts[0];
                        }
                        if (!isset($parts[1])) {
                            return 'Invalid header (no value): ' . $header;
                        }
                        $uniqueNames[$parts[0]] = true;
                    }
                    return true;
                },
            ]),
        ];
    }

    protected function displayIntegration(Integration $integration): void
    {
        $info = [];
        foreach ($integration->getProperties() as $property => $value) {
            $info[$property] = $this->propertyFormatter->format($value, $property);
        }
        if ($integration->hasLink('#hook')) {
            $info['hook_url'] = $this->propertyFormatter->format($integration->getLink('#hook'));
        }

        $this->table->renderSimple(array_values($info), array_keys($info));
    }

    /**
     * Obtains an OAuth2 token for Bitbucket from the given app credentials.
     *
     * @param array{key: string, secret: string} $credentials
     */
    protected function getBitbucketAccessToken(array $credentials): string
    {
        if (isset($this->bitbucketAccessTokens[$credentials['key']])) {
            return $this->bitbucketAccessTokens[$credentials['key']];
        }
        $response = $this->api
            ->getExternalHttpClient()
            ->post('https://bitbucket.org/site/oauth2/access_token', [
                'auth' => [$credentials['key'], $credentials['secret']],
                'body' => [
                    'grant_type' => 'client_credentials',
                ],
            ]);

        $data = (array) Utils::jsonDecode((string) $response->getBody(), true);
        if (!isset($data['access_token'])) {
            throw new \RuntimeException('Access token not found in Bitbucket response');
        }

        $this->bitbucketAccessTokens[$credentials['key']] = $data['access_token'];

        return $data['access_token'];
    }

    /**
     * Validates Bitbucket credentials.
     *
     * @param array{key: string, secret: string} $credentials
     */
    protected function validateBitbucketCredentials(array $credentials): true|string
    {
        try {
            $this->getBitbucketAccessToken($credentials);
        } catch (\Exception $e) {
            $message = '<error>Invalid Bitbucket credentials</error>';
            if ($e instanceof BadResponseException && $e->getResponse()->getStatusCode() === 400) {
                $message .= "\n" . 'Ensure that the OAuth consumer key and secret are valid.'
                    . "\n" . 'Additionally, ensure that the OAuth consumer has a callback URL set (even just to <comment>http://localhost</comment>).';
            }

            return $message;
        }

        return true;
    }

    /**
     * Lists validation errors found in an integration.
     *
     * @param array<int|string, string> $errors
     */
    protected function listValidationErrors(array $errors, OutputInterface $output): void
    {
        if (count($errors) === 1) {
            $this->stdErr->writeln('The following error was found:');
        } else {
            $this->stdErr->writeln(sprintf(
                'The following %d errors were found:',
                count($errors),
            ));
        }

        foreach ($errors as $key => $error) {
            if (is_string($key) && strlen($key)) {
                $output->writeln("$key: $error");
            } else {
                $output->writeln($error);
            }
        }
    }

    /**
     * Updates the Git remote URL for the current project.
     */
    protected function updateGitUrl(string $oldGitUrl, Project $project): void
    {
        if (!$this->selector->isProjectCurrent($project)) {
            return;
        }
        $projectRoot = $this->selector->getProjectRoot();
        if (!$projectRoot) {
            return;
        }
        $project->refresh();
        $newGitUrl = $project->getGitUrl();
        if ($newGitUrl === $oldGitUrl) {
            return;
        }
        $this->stdErr->writeln(sprintf('Updating Git remote URL from %s to %s', $oldGitUrl, $newGitUrl));
        $this->localProject->ensureGitRemote($projectRoot, $newGitUrl);
    }
}
