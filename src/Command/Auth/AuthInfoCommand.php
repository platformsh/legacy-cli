<?php
namespace Platformsh\Cli\Command\Auth;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Util\PropertyFormatter;
use Platformsh\Cli\Util\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class AuthInfoCommand extends CommandBase
{
    protected function configure()
    {
        $this
            ->setName('auth:info')
            ->setDescription('Display your account information')
            ->addOption('property', 'P', InputOption::VALUE_REQUIRED, 'The account property to view')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'Whether to refresh the cache');
        Table::addFormatOption($this->getDefinition());
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $info = $this->api()->getMyAccount((bool) $input->getOption('refresh'));
        $formatter = new PropertyFormatter($input);
        $propertyWhitelist = ['id', 'display_name', 'username', 'mail', 'has_key'];
        $info = array_intersect_key($info, array_flip($propertyWhitelist));

        if ($property = $input->getOption('property')) {
            if (!isset($info[$property])) {
                throw new \InvalidArgumentException('Property not found: ' . $property);
            }
            $output->writeln($formatter->format($info[$property], $property));

            return 0;
        }

        $values = [];
        foreach ($propertyWhitelist as $property) {
            if (isset($info[$property])) {
                $values[] = $formatter->format($info[$property], $property);
            }
        }
        $table = new Table($input, $output);
        $table->renderSimple($values, $propertyWhitelist);

        return 0;
    }
}
