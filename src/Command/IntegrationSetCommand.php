<?php

namespace CommerceGuys\Platform\Cli\Command;

use CommerceGuys\Platform\Cli\Model\Integration;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\OptionsResolver\OptionsResolver;

class IntegrationSetCommand extends PlatformCommand
{

    protected $values = array();

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('integration:set')
          ->addArgument('id', InputArgument::OPTIONAL, 'The ID of the integration to modify (leave blank to create a new integration)')
          ->setDescription('Add an integration to the project, or modify an integration');
        $this->setUpOptions();
        $this->addProjectOption();
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (!$this->validateInput($input, $output)) {
            return 1;
        }

        $client = $this->getPlatformClient($this->project['endpoint']);

        $id = $input->getArgument('id');
        if ($id) {
            $integration = Integration::get($id, 'integrations', $client);
            if (!$integration) {
                $output->writeln("Integration not found: <error>$id</error>");
                return 1;
            }
            $this->values = $integration->getProperties();
            if (!$this->validateOptions($input, $output)) {
                return 1;
            }
            $integration->update($this->values);
            $output->writeln("Integration <info>$id</info> (<info>{$this->values['type']}</info>) updated");
        }
        elseif (!$this->validateOptions($input, $output)) {
            return 1;
        }
        else {
            $integration = Integration::create($this->values, 'integrations', $client);
            $id = $integration->id();
            $output->writeln("Integration <info>$id</info> created for <info>{$this->values['type']}</info>");
        }

        /** @var Integration $integration */
        $output->writeln($integration->formatData());

        return 0;
    }

    /**
     * @inheritdoc
     */
    protected function validateOptions(InputInterface $input, OutputInterface $output)
    {
        $valid = true;
        try {
            $options = $this->values;
            unset($options['id']);
            $options['type'] = strtolower(isset($options['type']) ? $options['type'] : $input->getOption('type'));
            $userSpecifiedOptions = $input->getOptions();
            foreach ($this->getOptions() as $name => $definition) {
                $optionName = str_replace('_', '-', $name);
                if (isset($userSpecifiedOptions[$optionName])) {
                    $options[$name] = $userSpecifiedOptions[$optionName];
                }
            }
            $resolver = new OptionsResolver();
            $this->setUpResolver($resolver, $options['type']);
            $this->values = $resolver->resolve($options);
        }
        catch (\LogicException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            $valid = false;
        }
        return $valid;
    }

    protected function setUpOptions()
    {
        foreach ($this->getOptions() as $name => $option) {
            $type = empty($option['required']) ? InputOption::VALUE_OPTIONAL : InputOption::VALUE_REQUIRED;
            $description = empty($option['description']) ? ucfirst($name) : $option['description'];
            $this->addOption(str_replace('_', '-', $name), null, $type, $description);
        }
    }

    /**
     * @param OptionsResolver $resolver
     * @param string          $integrationType
     */
    protected function setUpResolver(OptionsResolver $resolver, $integrationType)
    {
        $defined = array();
        $defaults = array();
        $options = $this->getOptions();
        foreach ($options as $name => $option) {
            if ($integrationType && !empty($option['for types']) && !in_array($integrationType, $option['for types'])) {
                unset($options[$name]);
                continue;
            }
            if (!empty($option['default'])) {
                $defaults[$name] = $option['default'];
            }
            else {
                $defined[] = $name;
            }
        }
        $resolver->setDefined($defined);
        $resolver->setDefaults($defaults);
        $required = array();
        $normalizers = array();
        foreach ($options as $name => $option) {
            if (!empty($option['required']) && (empty($option['for types']) || $integrationType)) {
                $required[] = $name;
            }
            if (!empty($option['normalizer'])) {
                $normalizers[$name] = $option['normalizer'];
            }
            if (!empty($option['type'])) {
                $resolver->setAllowedTypes($name, $option['type']);
            }
            if (!empty($option['validator'])) {
                $resolver->setAllowedValues($name, $option['validator']);
            }
            elseif (!empty($option['options'])) {
                $resolver->setAllowedValues($name, $option['options']);
            }
        }
        $resolver->setRequired($required);
        $resolver->setNormalizers($normalizers);
    }

    /**
     * @return array
     *   An array of options, each option being an array containing any of the
     *   keys 'for types', 'required', 'type', 'description', 'default',
     *   'normalizer', and 'validator'.
     */
    protected function getOptions()
    {
        $arrayNormalizer = function ($options, $value) {
            if (!is_array($value)) {
                $value = explode(',', $value);
            }
            return $value;
        };
        $boolNormalizer = function ($options, $value) {
            return !in_array($value, array('false', '0', 0), true);
        };
        $boolOptions = array(true, false, '1', '0', 'true', 'false');
        return array(
          'type' => array(
            'required' => true,
            'description' => "The integration type (HipChat or GitHub)",
            'validator' => function($value) {
                return in_array($value, array('hipchat', 'github'));
            },
          ),
          'token' => array(
            'for types' => array('github', 'hipchat'),
            'required' => true,
            'description' => 'An OAuth token for the integration',
            'validator' => function ($string) {
                return base64_decode($string, true) !== false;
            },
          ),
          'repository' => array(
            'for types' => array('github'),
            'required' => true,
            'description' => 'GitHub: the repository to track',
            'validator' => function ($string) {
                return (bool) preg_match('#^[\w\-_]+/[\w\-_]+$#', $string);
            },
          ),
          'build_pull_requests' => array(
            'for types' => array('github'),
            'default' => true,
            'normalizer' => $boolNormalizer,
            'description' => 'GitHub: track pull requests',
            'options' => $boolOptions,
          ),
          'fetch_branches' => array(
            'for types' => array('github'),
            'default' => true,
            'normalizer' => $boolNormalizer,
            'description' => 'GitHub: track branches',
            'options' => $boolOptions,
          ),
          'room' => array(
            'for types' => array('hipchat'),
            'required' => true,
            'validator' => function ($value) {
                return is_numeric($value);
            },
            'description' => 'HipChat: the room ID',
          ),
          'events' => array(
            'for types' => array('hipchat'),
            'default' => '*',
            'description' => 'HipChat: events to report',
            'normalizer' => $arrayNormalizer,
          ),
          'states' => array(
            'for types' => array('hipchat'),
            'default' => 'complete',
            'description' => 'HipChat: states to report, e.g. complete,in_progress',
            'normalizer' => $arrayNormalizer,
          ),
        );
    }

}
