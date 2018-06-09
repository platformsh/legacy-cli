<?php
declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    use HasExamplesTrait;

    /** @var OutputInterface|null */
    protected $stdErr;

    protected $runningViaMulti = false;

    /**
     * @see self::setHiddenAliases()
     *
     * @var array
     */
    private $hiddenAliases = [];

    /**
     * @inheritdoc
     */
    protected function initialize(InputInterface $input, OutputInterface $output)
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param array $hiddenAliases
     *
     * @return CommandBase
     */
    protected function setHiddenAliases(array $hiddenAliases): CommandBase
    {
        $this->hiddenAliases = $hiddenAliases;
        $this->setAliases(array_merge($this->getAliases(), $hiddenAliases));

        return $this;
    }

    /**
     * Get aliases that should be visible in help.
     *
     * @return array
     */
    public function getVisibleAliases(): array
    {
        return array_diff($this->getAliases(), $this->hiddenAliases);
    }

    /**
     * Print a message if debug output is enabled.
     *
     * @param string $message
     */
    protected function debug(string $message): void
    {
        $this->labeledMessage('DEBUG', $message, OutputInterface::VERBOSITY_DEBUG);
    }

    /**
     * Print a message with a label.
     *
     * @param string $label
     * @param string $message
     * @param int    $options
     */
    private function labeledMessage(string $label, string $message, int $options = 0): void
    {
        if (isset($this->stdErr)) {
            $this->stdErr->writeln('<options=reverse>' . strtoupper($label) . '</> ' . $message, $options);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function setRunningViaMulti(bool $runningViaMulti = true): void
    {
        $this->runningViaMulti = $runningViaMulti;
    }

    /**
     * @param resource|int $descriptor
     *
     * @return bool
     */
    protected function isTerminal($descriptor): bool
    {
        return !function_exists('posix_isatty') || posix_isatty($descriptor);
    }
}
