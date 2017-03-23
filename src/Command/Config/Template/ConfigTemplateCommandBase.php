<?php

/**
 * @file
 * Base class for a command that configures a project using a set of templates.
 */

namespace Platformsh\Cli\Command\Config\Template;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

abstract class ConfigTemplateCommandBase extends CommandBase
{
    /** @var Form */
    private $form;

    /** @var string|null */
    private $repoRoot;

    /** @var false|null|\Platformsh\Cli\Local\LocalApplication */
    private static $currentApplication;

    /** @var \Twig_Environment */
    private $engine;

    protected $services = [];

    protected $relationships = [];

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
        $currentApp = $this->getCurrentApplication();

        $global = [];
        $global['application_name'] = new Field('Application name', [
            'optionName' => 'name',
            'default' => $currentApp && $currentApp->getName() ? $currentApp->getName() : 'app',
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

    /**
     * @return false|\Platformsh\Cli\Local\LocalApplication
     */
    protected function getCurrentApplication()
    {
        if (!isset(self::$currentApplication)) {
            self::$currentApplication = false;
            if ($repoRoot = $this->getRepositoryRoot()) {
                $apps = LocalApplication::getApplications($repoRoot);
                self::$currentApplication = count($apps) === 1 ? reset($apps) : false;
            }
        }

        return self::$currentApplication;
    }

    /**
     * @return string|false
     */
    protected function getRepositoryRoot()
    {
        if (isset($this->repoRoot)) {
            return $this->repoRoot;
        }
        $this->repoRoot = $this->getProjectRoot();
        if (!$this->repoRoot) {
            /** @var \Platformsh\Cli\Service\Git $git */
            $git = $this->getService('git');
            $this->repoRoot = $git->getRoot();
        }

        return $this->repoRoot;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $repoRoot = $this->getRepositoryRoot();
        if (!$repoRoot) {

        }

        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        $options = $this->form->resolveOptions($input, $output, $questionHelper);

        $directory = isset($options['directory'])
            ? $repoRoot . '/' . $options['directory']
            : $repoRoot;

        unset($options['directory'], $options['subdir']);

        $options['services'] = $this->services;
        $options['relationships'] = $this->relationships;

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
     * @param array  $parameters
     *
     * @return string
     */
    protected function renderTemplate($template, array $parameters)
    {
        if (!$this->engine) {
            /** @var \Doctrine\Common\Cache\CacheProvider $cache */
            $cache = $this->getService('cache');
            $options = [
                'debug' => true,
                'cache' => false,
                'strict_variables' => true,
                'autoescape' => false,
            ];
            $this->engine = new \Twig_Environment(new Loader($cache), $options);
        }

        return $this->engine->render($template, $parameters);
    }

    /**
     * Get a template.
     *
     * @param string $type The template type (e.g. 'app', 'routes', or
     *                     'services').
     *
     * @return string The template URL or filename.
     */
    protected function getTemplate($type)
    {
        $key = $this->getKey();
        $candidates = [
            CLI_ROOT . "/resources/templates/$key.$type.yaml.twig",
            CLI_ROOT . "/resources/templates/$type.yaml.twig",
        ];
        foreach ($candidates as $candidate) {
            if (file_exists($candidate)) {
                return $candidate;
            }
        }
        throw new \RuntimeException("No template found for type $type");
    }
}
