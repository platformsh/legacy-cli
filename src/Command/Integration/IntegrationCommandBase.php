<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Client\Model\Integration;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
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
                ]],
                'description' => 'An OAuth token for the integration',
                'validator' => function ($string) {
                    return base64_decode($string, true) !== false;
                },
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
                ]],
                'default' => ['*'],
                'description' => 'Generic webhook: the environments relevant to the hook',
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
