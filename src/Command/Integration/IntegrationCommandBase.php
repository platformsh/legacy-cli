<?php
namespace Platformsh\Cli\Command\Integration;

use GuzzleHttp\Exception\GuzzleException;
use function GuzzleHttp\json_decode;
use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Integration;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\EmailAddressField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;

abstract class IntegrationCommandBase extends CommandBase
{
    /** @var Form */
    private $form;

    /**
     * @return Form
     */
    protected function getForm()
    {
        if (!isset($this->form)) {
            $this->form = Form::fromArray($this->getFields());
        }

        return $this->form;
    }

    /**
     * @return Field[]
     */
    private function getFields()
    {
        return [
            'type' => new OptionsField('Integration type', [
                'optionName' => 'type',
                'description' => 'The integration type',
                'questionLine' => '',
                'options' => [
                    'github',
                    'gitlab',
                    'hipchat',
                    'webhook',
                    'health.email',
                    'health.pagerduty',
                    'health.slack',
                ],
            ]),
            'token' => new Field('Token', [
                'conditions' => ['type' => [
                    'github',
                    'gitlab',
                    'hipchat',
                    'health.slack',
                ]],
                'description' => 'An OAuth token for the integration',
            ]),
            'base_url' => new UrlField('Base URL', [
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'The base URL of the GitLab installation',
            ]),
            'project' => new Field('Project', [
                'optionName' => 'gitlab-project',
                'conditions' => ['type' => [
                    'gitlab',
                ]],
                'description' => 'The GitLab project (e.g. \'namespace/repo\')',
                'validator' => function ($string) {
                    return substr_count($string, '/', 1) === 1;
                },
            ]),
            'repository' => new Field('Repository', [
                'conditions' => ['type' => [
                    'github',
                ]],
                'description' => 'GitHub: the repository to track (e.g. \'user/repo\' or \'https://github.com/user/repo\')',
                'questionLine' => 'The GitHub repository (e.g. \'user/repo\' or \'https://github.com/user/repo\')',
                'validator' => function ($string) {
                    return substr_count($string, '/', 1) === 1;
                },
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
                    'github',
                ]],
                'description' => 'GitHub: build pull requests as environments',
                'questionLine' => 'Build every pull request as an environment',
            ]),
            'build_pull_requests_post_merge' => new BooleanField('Build pull requests post-merge', [
              'conditions' => [
                'type' => [
                  'github',
                ],
                'build_pull_requests' => true,
              ],
              'default' => false,
              'description' => 'GitHub: build pull requests based on their post-merge state',
              'questionLine' => 'Build pull requests based on their post-merge state',
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
                    ],
                    'build_pull_requests' => true,
                ],
                'description' => 'GitHub: clone data for pull requests',
                'questionLine' => "Clone the parent environment's data for pull requests",
            ]),
            'fetch_branches' => new BooleanField('Fetch branches', [
                'conditions' => ['type' => [
                    'github',
                    'gitlab',
                ]],
                'description' => 'Fetch all branches (as inactive environments)',
            ]),
            'room' => new Field('HipChat room ID', [
                'conditions' => ['type' => [
                    'hipchat',
                ]],
                'validator' => 'is_numeric',
                'optionName' => 'room',
                'questionLine' => 'What is the HipChat room ID (numeric)?',
            ]),
            'url' => new UrlField('URL', [
                'conditions' => ['type' => [
                    'webhook',
                ]],
                'description' => 'Generic webhook: a URL to receive JSON data',
                'questionLine' => 'What is the webhook URL (to which JSON data will be posted)?',
            ]),
            'events' => new ArrayField('Events to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['*'],
                'description' => 'A list of events to report, e.g. environment.push',
                'optionName' => 'events',
            ]),
            'states' => new ArrayField('States to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['complete'],
                'description' => 'A list of states to report, e.g. pending, in_progress, complete',
                'optionName' => 'states',
            ]),
            'environments' => new ArrayField('Included environments', [
                'optionName' => 'environments',
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => ['*'],
                'description' => 'The environment IDs to include',
            ]),
            'excluded_environments' => new ArrayField('Excluded environments', [
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => [],
                'description' => 'The environment IDs to exclude',
                'required' => false,
            ]),
            'from_address' => new EmailAddressField('From address', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The From address for alert emails',
                'default' => $this->config()->getWithDefault('service.default_from_address', null),
            ]),
            'recipients' => new ArrayField('Recipients', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The recipient email address(es)',
                'validator' => function ($emails) {
                    $invalid = array_filter($emails, function ($email) {
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
                'description' => 'The Slack channel (beginning with the #)',
                'validator' => function ($string) {
                    return strpos($string, '#') === 0;
                },
            ]),
            'routing_key' => new Field('Routing key', [
                'conditions' => ['type' => [
                    'health.pagerduty',
                ]],
                'description' => 'The PagerDuty routing key',
            ]),
        ];
    }

    /**
     * @param \Platformsh\Client\Model\Integration $integration
     */
    protected function ensureHooks(Integration $integration)
    {
        if ($integration->type === 'github') {
            $hooksApiUrl = sprintf('https://api.github.com/repos/%s/hooks', $integration->getProperty('repository'));
            $requestOptions = [
                'auth' => false,
                'headers' => [
                    'Accept' => 'application/vnd.github.v3+json',
                    'Authorization' => 'token ' . $integration->getProperty('token'),
                ],
            ];
            $payload = [
                'name' => 'web',
                'config' => [
                    'url' => $integration->getLink('#hook'),
                    'content_type' => 'json',
                ],
                'events' => ['*'],
                'active' => true,
            ];
            $repoName = $integration->getProperty('repository');
        } elseif ($integration->type === 'gitlab') {
            $baseUrl = rtrim($integration->getProperty('base_url'), '/');
            $hooksApiUrl = sprintf('%s/api/v4/projects/%s/hooks', $baseUrl, rawurlencode($integration->getProperty('project')));
            $requestOptions = [
                'auth' => false,
                'headers' => [
                    'Accept' => 'application/json',
                    'Private-Token' => $integration->getProperty('token'),
                ],
            ];
            $payload = [
                'url' => $integration->getLink('#hook'),
                'push_events' => true,
                'merge_requests_events' => true,
            ];
            $repoName = $baseUrl . '/' . $integration->getProperty('project');
        } else {
            return;
        }

        $client = $this->api()->getHttpClient();

        $this->stdErr->writeln(sprintf(
            'Checking webhook configuration on the repository: <info>%s</info>',
            $repoName
        ));

        try {
            $hooks = json_decode($client->request('get', $hooksApiUrl, $requestOptions)->getBody()->getContents(), true);
            $hook = $this->findWebHook($integration, $hooks);
            if (!$hook) {
                $this->stdErr->writeln('  Creating new webhook');
                $client->request('post', $hooksApiUrl, ['json' => $payload] + $requestOptions);
                $this->stdErr->writeln('  Webhook created successfully');
            }
            elseif ($this->arraysDiffer($hook, $payload)) {
                // The GitLab API requires PUT for editing project hooks. The
                // GitHub API requires PATCH.
                $method = $integration->type === 'gitlab' ? 'put' : 'patch';
                $hookApiUrl = $hooksApiUrl . '/' . rawurlencode($hook['id']);

                $this->stdErr->writeln('  Updating webhook');
                $client->request($method, $hookApiUrl, ['json' => $payload] + $requestOptions);
                $this->stdErr->writeln('  Webhook updated successfully');
            }
            else {
                $this->stdErr->writeln('  Valid configuration found');
            }
        } catch (GuzzleException $e) {
            $this->stdErr->writeln('');
            $this->stdErr->writeln('  <comment>Failed to read or write webhooks:</comment>');
            $this->stdErr->writeln('  ' . $e->getMessage());
            $this->stdErr->writeln(sprintf(
                "\n  Please ensure a webhook exists manually.\n  Hook URL: %s",
                $integration->getLink('#hook')
            ));
        }
    }

    /**
     * Checks if $array2 has any values missing or different from $array1.
     *
     * Runs recursively for multidimensional arrays.
     *
     * @param array $array1
     * @param array $array2
     *
     * @return bool
     */
    private function arraysDiffer(array $array1, array $array2)
    {
        foreach ($array2 as $property => $value) {
            if (!array_key_exists($property, $array1)) {
                return true;
            }
            if (is_array($value)) {
                if (!is_array($array1[$property])) {
                    return true;
                }
                if ($array1[$property] != $value && $this->arraysDiffer($array1[$property], $value)) {
                    return true;
                }
                continue;
            }
            if ($array1[$property] != $value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find if a valid webhook exists in a service's hooks list.
     *
     * @param \Platformsh\Client\Model\Integration $integration
     * @param array                                $jsonResult
     *
     * @return array|false
     */
    private function findWebHook(Integration $integration, array $jsonResult)
    {
        $type = $integration->type;
        $hookUrl = $integration->getLink('#hook');
        foreach ($jsonResult as $hook) {
            if ($type === 'github' && $hook['config']['url'] === $hookUrl) {
                return $hook;
            }
            if ($type === 'gitlab' && $hook['url'] === $hookUrl) {
                return $hook;
            }
        }

        return false;
    }

    /**
     * @param Integration     $integration
     */
    protected function displayIntegration(Integration $integration)
    {
        /** @var \Platformsh\Cli\Service\Table $table */
        $table = $this->getService('table');
        /** @var \Platformsh\Cli\Service\PropertyFormatter $formatter */
        $formatter = $this->getService('property_formatter');

        $info = [];
        foreach ($integration->getProperties() as $property => $value) {
            $info[$property] = $formatter->format($value, $property);
        }
        if ($integration->hasLink('#hook')) {
            $info['hook_url'] = $formatter->format($integration->getLink('#hook'));
        }

        $table->renderSimple(array_values($info), array_keys($info));
    }
}
