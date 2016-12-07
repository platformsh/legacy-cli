<?php
namespace Platformsh\Cli\Command\App;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\SshUtil;
use Platformsh\Cli\Util\Util;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AppConfigGetCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('app:config-get')
            ->setDescription('View the configuration of an app')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The configuration property to view');
        $this->addProjectOption();
        $this->addEnvironmentOption();
        $this->addAppOption();
        SshUtil::configureInput($this->getDefinition());
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);
        $shellHelper = $this->getHelper('shell');
        $sshUtil = new SshUtil($input, $output);

        $sshUrl = $this->getSelectedEnvironment()
            ->getSshUrl($this->selectApp($input));
        $args = ['ssh'];
        $args = array_merge($args, $sshUtil->getSshArgs());
        $args[] = $sshUrl;
        $args[] = 'echo $' . self::$config->get('service.env_prefix') . 'APPLICATION';
        $result = $shellHelper->execute($args, null, true);
        $appConfig = json_decode(base64_decode($result), true);
        $value = $appConfig;
        $key = null;

        if ($property = $input->getOption('property')) {
            $parents = explode('.', $property);
            $key = end($parents);
            $value = Util::getNestedArrayValue($appConfig, $parents, $keyExists);
            if (!$keyExists) {
                $this->stdErr->writeln("Configuration property not found: <error>$property</error>");

                return 1;
            }
        }

        $formatter = new PropertyFormatter();
        $formatter->yamlInline = 10;
        $output->writeln($formatter->format($value, $key));

        return 0;
    }
}
