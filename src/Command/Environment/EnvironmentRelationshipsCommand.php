<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\PlatformCommand;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\CacheUtil;
use Platformsh\Cli\Util\RelationshipsUtil;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentRelationshipsCommand extends PlatformCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
          ->setName('environment:relationships')
          ->setAliases(array('relationships'))
          ->setDescription('List an environment\'s relationships')
          ->addArgument('environment', InputArgument::OPTIONAL, 'The environment')
          ->addOption('property', null, InputOption::VALUE_REQUIRED, 'The relationship property to view')
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

        $app = $input->getOption('app');
        $environment = $this->getSelectedEnvironment();

        $cacheKey = implode('-', ['relationships', $environment->id . $environment->project . $app]);
        $cache = CacheUtil::getCache();
        $relationships = $cache->fetch($cacheKey);
        if (empty($relationships) || $input->getOption('refresh')) {
            $util = new RelationshipsUtil($this->stdErr);
            $sshUrl = $environment->getSshUrl();
            $relationships = $util->getRelationships($sshUrl);
            if (empty($relationships)) {
                $this->stdErr->writeln('No relationships found');
                return 1;
            }
            $cache->save($cacheKey, $relationships, 3600);
        }

        if ($property = $input->getOption('property')) {
            $parents = explode('.', $property);
            $result = self::getNestedArrayValue($relationships, $parents, $key_exists);
            if (!$key_exists) {
                $this->stdErr->writeln("Relationship property found: <error>$property</error>");

                return 1;
            }
            $formatter = new PropertyFormatter();
            $output->writeln($formatter->format($result, end($parents)));
            return 0;
        }

        foreach ($relationships as $key => $relationship) {
            foreach ($relationship as $delta => $info) {
                $output->writeln("<comment>$key:$delta:</comment>");
                foreach ($info as $prop => $value) {
                    if (is_scalar($value)) {
                        $propString = str_pad("$prop", 10, " ");
                        $output->writeln("<info>  $propString: $value</info>");
                    }
                }
            }
        }

        return 0;
    }

    /**
     * Get a nested value in an array.
     *
     * @see Copied from \Drupal\Component\Utility\NestedArray::getValue()
     *
     * @param array $array
     * @param array $parents
     * @param bool  $key_exists
     *
     * @return mixed
     */
    protected static function &getNestedArrayValue(array &$array, array $parents, &$key_exists = NULL)
    {
        $ref = &$array;
        foreach ($parents as $parent) {
            if (is_array($ref) && array_key_exists($parent, $ref)) {
                $ref = &$ref[$parent];
            }
            else {
                $key_exists = FALSE;
                $null = NULL;
                return $null;
            }
        }
        $key_exists = TRUE;

        return $ref;
    }
}
