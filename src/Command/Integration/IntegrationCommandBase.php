<?php
namespace Platformsh\Cli\Command\Integration;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\ConsoleForm\Field\ArrayField;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Field\UrlField;
use Platformsh\ConsoleForm\Form;
use Platformsh\Client\Model\Integration;

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
        $info = [];
        if ($properties['type'] == 'github') {
            $info["Repository"] = $properties['repository'];
            $info["Build PRs"] = $properties['build_pull_requests'] ? 'yes' : 'no';
            $info["Fetch branches"] = $properties['fetch_branches'] ? 'yes' : 'no';
            $info["Payload URL"] = $integration->hasLink('#hook') ? $integration->getLink('#hook', true) : '[unknown]';
        } elseif ($properties['type'] == 'bitbucket') {
            $info["Repository"] = $properties['repository'];
            $info["Fetch branches"] = $properties['fetch_branches'] ? 'yes' : 'no';
            $info["Prune branches"] = $properties['prune_branches'] ? 'yes' : 'no';
            $info["Payload URL"] = $integration->hasLink('#hook') ? $integration->getLink('#hook', true) : '[unknown]';
        } elseif ($properties['type'] == 'hipchat') {
            $info["Room ID"] = $properties['room'];
            $info["Events"] = implode(', ', $properties['events']);
            $info["States"] = implode(', ', $properties['states']);
        } elseif ($properties['type'] == 'webhook') {
            $info["URL"] = $properties['url'];
        }

        $output = '';
        foreach ($info as $label => $value) {
            $output .= "$label: $value\n";
        }

        return rtrim($output);
    }

}
