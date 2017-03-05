<?php

/**
 * @file
 * Base class for a command that configures a project using a set of templates.
 */

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

abstract class ConfigTemplateCommandBase extends CommandBase
{
    /** @var Form */
    private $form;

    /**
     * @return string
     */
    abstract protected function getKey();

    /**
     * @return string
     */
    abstract protected function getLabel();

    /**
     * @return Field[]
     */
    abstract protected function getFields();

    /**
     * @param Field[] $fields
     *
     * @return Field[]
     */
    private function addGlobalFields(array $fields)
    {
        $global = [];
        $global['application_name'] = new Field('Application name', [
            'optionName' => 'name',
            'default' => 'app',
            'validator' => function ($value) {
                return preg_match('/^[a-z0-9-]+$/', $value)
                    ? true
                    : 'The application name can only consist of lower-case letters and numbers.';
            },
        ]);

        return array_merge($global, $fields);
    }

    protected function configure()
    {
        $this->setName('config:generate:' . $this->getKey());
        $this->setDescription('Configure a project with the ' . $this->getLabel() . ' template');
        $this->form = Form::fromArray($this->addGlobalFields($this->getFields()));
        $this->form->configureInputDefinition($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        // The project root is a Git repository, as we assume there are no
        // config files yet.
        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $projectRoot = $git->getRoot(null, true);

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $options = $this->form->resolveOptions($input, $output, $questionHelper);

        $directory = isset($options['directory'])
            ? $projectRoot . '/' . $options['directory']
            : $projectRoot;

        unset($options['directory'], $options['subdir']);

        $fs = new Filesystem();
        foreach ($this->getTemplateTypes() as $templateType => $destination) {
            $this->stdErr->writeln('Creating file: ' . $destination);
            $destinationAbsolute = $directory . '/' . $destination;
            if (file_exists($destinationAbsolute) && !$questionHelper->confirm('The destination file already exists. Overwrite?')) {
                continue;
            }
            $template = $this->getTemplate($templateType);
            $content = $this->renderTemplate($template, $options);
            $fs->dumpFile($destinationAbsolute, $content);
        }

        return 0;
    }

    /**
     * @return array An array of destination filenames, keyed by template type.
     */
    protected function getTemplateTypes()
    {
        return [
            'app' => $this->config()->get('service.app_config_file'),
            'routes' => $this->config()->get('service.project_config_dir') . '/routes.yaml',
            'services' => $this->config()->get('service.project_config_dir') . '/services.yaml',
        ];
    }

    /**
     * @param string $template
     * @param array  $options
     *
     * @todo switch this to use Twig or similar
     *
     * @return string
     */
    protected function renderTemplate($template, array $options)
    {
        $replace = [];
        foreach ($options as $key => $value) {
            $replace['{' . $key . '}'] = $value;
        }

        return strtr($template, $replace);
    }

    /**
     * Get a template.
     *
     * @param string $type The template type (e.g. 'app', 'routes', or
     *                     'services').
     *
     * @return string The template contents.
     */
    protected function getTemplate($type)
    {
        $key = $this->getKey();
        $candidates = [
            CLI_ROOT . "/resources/templates/$key.$type.template.yaml",
            CLI_ROOT . "/resources/templates/$type.template.yaml",
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return (string) file_get_contents($candidate);
            }
        }
        throw new \RuntimeException("No template found for type $type");
    }
}
