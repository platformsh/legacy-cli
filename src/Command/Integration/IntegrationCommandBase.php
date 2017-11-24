<?php
namespace Platformsh\Cli\Command\Integration;

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
        $types = [
            'github',
            'hipchat',
            'webhook',
            'health.email',
            'health.pagerduty',
            'health.slack',
        ];

        return [
            'type' => new OptionsField('Type', [
                'optionName' => 'type',
                'description' => 'The integration type (\'' . implode('\', \'', $types) . '\')',
                'options' => $types,
            ]),
            'token' => new Field('Token', [
                'conditions' => ['type' => [
                    'github',
                    'hipchat',
                    'health.slack',
                ]],
                'description' => 'An OAuth token for the integration',
            ]),
            'repository' => new Field('Repository', [
                'conditions' => ['type' => [
                    'github',
                ]],
                'description' => 'GitHub: the repository to track (the URL, e.g. \'https://github.com/user/repo\')',
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
            'build_pull_requests' => new BooleanField('Build pull requests', [
                'conditions' => ['type' => [
                    'github',
                ]],
                'description' => 'GitHub: build pull requests as environments',
            ]),
            'build_pull_requests_post_merge' => new BooleanField('Build pull requests post-merge', [
              'conditions' => ['type' => [
                'github',
              ]],
              'description' => 'GitHub: build pull requests based on their post-merge state',
            ]),
            'pull_requests_clone_parent_data' => new BooleanField('Clone data for pull requests', [
                'optionName' => 'pull-requests-clone-parent-data',
                'conditions' => ['type' => [
                    'github',
                ]],
                'description' => 'GitHub: clone data for pull requests',
            ]),
            'fetch_branches' => new BooleanField('Fetch branches', [
                'conditions' => ['type' => [
                    'github',
                ]],
                'description' => 'GitHub: sync all branches',
            ]),
            'room' => new Field('HipChat room ID', [
                'conditions' => ['type' => [
                    'hipchat',
                ]],
                'validator' => 'is_numeric',
                'optionName' => 'room',
            ]),
            'url' => new UrlField('URL', [
                'conditions' => ['type' => [
                    'webhook',
                ]],
                'description' => 'Generic webhook: a URL to receive JSON data',
            ]),
            'events' => new ArrayField('Events to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['*'],
                'description' => 'Events to report, e.g. environment.push',
                'optionName' => 'events',
            ]),
            'states' => new ArrayField('States to report', [
                'conditions' => ['type' => [
                    'hipchat',
                    'webhook',
                ]],
                'default' => ['complete'],
                'description' => 'States to report, e.g. pending, in_progress, complete',
                'optionName' => 'states',
            ]),
            'environments' => new ArrayField('Environments', [
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => ['*'],
                'description' => 'The environments to include',
            ]),
            'excluded_environments' => new ArrayField('Excluded environments', [
                'conditions' => ['type' => [
                    'webhook',
                    'hipchat',
                ]],
                'default' => [],
                'description' => 'The environments to exclude',
            ]),
            'from_address' => new EmailAddressField('From address', [
                'conditions' => ['type' => [
                    'health.email',
                ]],
                'description' => 'The From address for alert emails',
                'default' => $this->config()->has('service.default_from_address')
                    ? $this->config()->get('service.default_from_address')
                    : null,
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
