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
            ->setAliases(['init'])
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
        // The project root is a Git repository, as we assume there are no
        // config files yet.
        $projectRoot = $this->findTopDirectoryContaining('.git');

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

        $this->makeRoutingYaml($projectRoot, $appConfig['name']);

        return 0;
    }

    /**
     * Generates a stock routing.yaml file.
     *
     * @param $projectRoot
     * @param $applicationName
     */
    protected function makeRoutingYaml($projectRoot, $applicationName)
    {
        mkdir($projectRoot . '/.platform');

        $routingYaml = <<<END
# The routing.yaml file describes how an incoming URL is going
# to be processed by Platform.sh.  With the defaults below, all requests to
# The the domain name configured in the UI will pass through to the application
# and all requests to the www. prefix will be redirected to the bare domain.
# To reverse that behavior, simply swap the definitions.
#
# See https://docs.platform.sh/user_guide/reference/routes-yaml.html for more information.

"http://{default}/":
  type: upstream
  upstream: "{$applicationName}:http"
"http://www.{default}/":
  type: redirect
  to: "http://{default}/"

END;

        file_put_contents($projectRoot . '/.platform/routing.yaml', $routingYaml);
    }

    /**
     * Find the highest level directory that contains a file.
     *
     * @param string $file
     *   The filename to look for.
     * @param callable $callback
     *   A callback to validate the directory when found. Accepts one argument
     *   (the directory path). Return true to use the directory, or false to
     *   continue traversing upwards.
     *
     * @return string|false
     *   The path to the directory, or false if the file is not found.
     */
    protected static function findTopDirectoryContaining($file, callable $callback = null)
    {
        static $roots = [];
        $cwd = getcwd();
        if ($cwd === false) {
            return false;
        }
        if (isset($roots[$cwd][$file])) {
            return $roots[$cwd][$file];
        }

        $roots[$cwd][$file] = false;
        $root = &$roots[$cwd][$file];

        $currentDir = $cwd;
        while (!$root) {
            if (file_exists($currentDir . '/' . $file)) {
                if ($callback === null || $callback($currentDir)) {
                    $root = $currentDir;
                    break;
                }
            }

            // The file was not found, go one directory up.
            $levelUp = dirname($currentDir);
            if ($levelUp === $currentDir || $levelUp === '.') {
                break;
            }
            $currentDir = $levelUp;
        }

        return $root;
    }
}
