<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Platformsh\Client\Model\Integration;

abstract class IntegrationCommand extends PlatformCommand
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
        return array(
          'type' => new OptionsField('Integration type', [
            'name' => 'Integration type',
            'optionName' => 'type',
            'description' => "The integration type ('github', 'hipchat', or 'webhook')",
            'options' => ['github', 'hipchat', 'webhook'],
          ]),
          'token' => new Field('Token', [
            'conditions' => ['type' => ['github', 'hipchat']],
            'name' => 'Token',
            'description' => 'GitHub or HipChat: An OAuth token for the integration',
            'validator' => function ($string) {
                return base64_decode($string, true) !== false;
            },
          ]),
          'repository' => new Field('Repository', [
            'conditions' => ['type' => 'github'],
            'description' => 'GitHub: the repository to track (in the form \'user/repo\')',
            'validator' => function ($string) {
                return substr_count($string, '/', 1) === 1;
            },
          ]),
          'build_pull_requests' => new BooleanField('Build pull requests', [
            'conditions' => ['type' => 'github'],
            'description' => 'GitHub: build pull requests as environments',
          ]),
          'fetch_branches' => new BooleanField('Fetch branches', [
            'conditions' => ['type' => 'github'],
            'description' => 'GitHub: sync all branches to Platform.sh',
          ]),
          'room' => new Field('Hipchat room ID', [
            'conditions' => ['type' => 'hipchat'],
            'validator' => 'is_numeric',
            'optionName' => 'room',
            'name' => 'HipChat room ID',
          ]),
          'events' => new ArrayField('Events to report', [
            'conditions' => ['type' => 'hipchat'],
            'optionName' => 'events',
            'default' => ['*'],
            'description' => 'HipChat: events to report',
          ]),
          'states' => new ArrayField('States to report', [
            'conditions' => ['type' => 'hipchat'],
            'optionName' => 'states',
            'name' => 'States to report',
            'default' => ['complete'],
            'description' => 'HipChat: states to report, e.g. complete,in_progress',
          ]),
          'url' => new UrlField('URL', [
            'conditions' => ['type' => 'webhook'],
            'description' => 'Generic webhook: a URL to receive JSON data',
          ]),
        );
    }

    /**
     * @param Integration $integration
     *
     * @return string
     */
    protected function formatIntegrationData(Integration $integration)
    {
        $properties = $integration->getProperties();
        $output = '';
        if ($properties['type'] == 'github') {
            $payloadUrl = $integration->hasLink('#hook') ? $integration->getLink('#hook', true) : '[unknown]';
            $output = "Repository: " . $properties['repository']
              . "\nBuild PRs: " . ($properties['build_pull_requests'] ? 'yes' : 'no')
              . "\nFetch branches: " . ($properties['fetch_branches'] ? 'yes' : 'no')
              . "\nPayload URL: " . $payloadUrl;
        } elseif ($properties['type'] == 'bitbucket') {
            $payloadUrl = $integration->hasLink('#hook') ? $integration->getLink('#hook', true) : '[unknown]';
            $output = "Repository: " . $properties['repository']
              . "\nFetch branches: " . ($properties['fetch_branches'] ? 'yes' : 'no')
              . "\nPrune branches: " . (!empty($properties['prune_branches']) ? 'yes' : 'no')
              . "\nPayload URL: " . $payloadUrl;
        } elseif ($properties['type'] == 'hipchat') {
            $output = "Room ID: " . $properties['room']
              . "\nEvents: " . implode(', ', $properties['events'])
              . "\nStates: " . implode(', ', $properties['states']);
        } elseif ($properties['type'] == 'webhook') {
            $output = "URL: " . $properties['url'];
        }

        return $output;
    }

}
