<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Helper\QuestionHelper;
use Platformsh\ConsoleForm\Field\BooleanField;
use Platformsh\ConsoleForm\Field\Field;
use Platformsh\ConsoleForm\Field\OptionsField;
use Platformsh\ConsoleForm\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Yaml\Yaml;

class AppInitCommand extends CommandBase
{
    protected $local = true;

    /** @var Form */
    protected $form;

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this->setName('app:init')
            ->setDescription('Create an application in the local repository');
        $this->form = Form::fromArray($this->getFields());
        $this->form->configureInputDefinition($this->getDefinition());
    }

    /**
     * @return array
     */
    protected function getFields()
    {
        return [
            'name' => new Field('Application name', [
                'optionName' => 'name',
                'validator' => function ($value) {
                    return preg_match('/^[a-z0-9-]+$/', $value)
                        ? true
                        : 'The application name can only consist of lower-case letters and numbers.';
                },
            ]),
            'type' => new OptionsField('Application type', [
                'optionName' => 'type',
                'options' => [
                    'php:5.6',
                    'php:7.0',
                    'hhvm:3.8',
                ],
                'default' => 'php:7.0',
            ]),
            'subdir' => new BooleanField('Create the application in a subdirectory', [
                'optionName' => 'subdir',
                'default' => false,
            ]),
            'directory' => new Field('Directory name', [
                'conditions' => ['subdir' => true],
                'optionName' => 'directory-name',
                'defaultCallback' => function (array $previousValues) {
                    return $previousValues['name'];
                },
                'normalizer' => function ($value) {
                    return trim($value, '/');
                },
            ]),
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$projectRoot = $this->getProjectRoot()) {
            throw new RootNotFoundException();
        }

        /** @var QuestionHelper $questionHelper */
        $questionHelper = $this->getHelper('question');
        $options = $this->form->resolveOptions($input, $output, $questionHelper);

        $configFile = self::$config->get('service.app_config_file');

        if (isset($options['directory'])) {
            $configFileAbsolute = sprintf('%s/%s/%s', $projectRoot, $options['directory'], $configFile);
        }
        else {
            $configFileAbsolute = sprintf('%s/%s', $projectRoot, $configFile);
        }

        $this->stdErr->writeln('Creating config file: ' . $configFileAbsolute);

        if (file_exists($configFileAbsolute) && !$questionHelper->confirm('The config file already exists. Overwrite?')) {
            return 1;
        }

        $appConfig = $options;
        unset($appConfig['directory'], $appConfig['subdir']);

        $fs = new Filesystem();
        $fs->dumpFile($configFileAbsolute, Yaml::dump($appConfig, 10));

        return 0;
    }
}
