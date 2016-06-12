<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\RelationshipsUtil;
use Platformsh\Cli\Util\Util;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends CommandBase
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('environment:relationships')
            ->setAliases(['relationships'])
            ->setDescription('Show an environment\'s relationships')
            ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The relationship property to view')
            ->addOption('refresh', null, InputOption::VALUE_REQUIRED, 'Whether to refresh the relationships', '0');
        $this->addProjectOption()
             ->addEnvironmentOption()
             ->addAppOption();
        $this->addExample("View all the current environment's relationships");
        $this->addExample("View the 'master' environment's relationships", 'master');
        $this->addExample("View the 'master' environment's database port", 'master --property database.0.port');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input);

        $app = $this->selectApp($input);
        $environment = $this->getSelectedEnvironment();

        $cacheKey = implode('-', ['relationships', $environment->id . $environment->project . $app]);
        $cache = $this->api()->getCache();
        $relationships = $cache->fetch($cacheKey);
        if (empty($relationships) || $input->getOption('refresh')) {
            $util = new RelationshipsUtil($this->stdErr);
            $sshUrl = $environment->getSshUrl($app);
            $relationships = $util->getRelationships($sshUrl);
            if (empty($relationships)) {
                $this->stdErr->writeln('No relationships found');
                return 1;
            }
            $cache->save($cacheKey, $relationships, 3600);
        }

        $value = $relationships;
        $key = null;

        if ($property = $input->getOption('property')) {
            $parents = explode('.', $property);
            $key = end($parents);
            $value = Util::getNestedArrayValue($relationships, $parents, $keyExists);
            if (!$keyExists) {
                $this->stdErr->writeln("Relationship property not found: <error>$property</error>");

                return 1;
            }
        }

        $formatter = new PropertyFormatter();
        $formatter->yamlInline = 10;
        $output->writeln($formatter->format($value, $key));

        return 0;
    }
}
