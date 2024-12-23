<?php

declare(strict_types=1);

namespace Platformsh\Cli\Command;

use Platformsh\Cli\Console\CompleterInterface;
use Platformsh\Cli\Console\HiddenInputOption;
use Platformsh\Cli\Service\Config;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Completion\CompletionInput;
use Symfony\Component\Console\Completion\CompletionSuggestions;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\Service\Attribute\Required;

abstract class CommandBase extends Command implements MultiAwareInterface
{
    public const STABILITY_STABLE = 'STABLE';
    public const STABILITY_BETA = 'BETA';
    public const STABILITY_DEPRECATED = 'DEPRECATED';

    protected OutputInterface $stdErr;

    /**
     * Sets whether the command is hidden in the list.
     *
     * The AsCommand attribute has the 'hidden' property, which allows for
     * hiding lazily-loaded commands. However, it prevents the commands being
     * accessed via abbreviations. Additionally some commands are dynamically
     * hidden (by overriding isHidden()), so they must be fully loaded anyway
     * before rendering the list.
     *
     * @var bool
     */
    protected bool $hiddenInList = false;

    protected string $stability = self::STABILITY_STABLE;
    protected bool $canBeRunMultipleTimes = true;
    protected bool $runningViaMulti = false;

    /**
     * @var string[]
     * @see self::setHiddenAliases()
     */
    private array $hiddenAliases = [];

    /**
     * The command synopsis.
     * @var array<string, string>
     */
    private array $synopsis = [];

    private ?Config $config = null;

    /** @var CompleterInterface[] */
    private array $completers = [];

    /** @var array<array{'commandline': string, 'description': string}> */
    private array $examples = [];

    public function __construct()
    {
        $this->stdErr = new NullOutput();
        parent::__construct();
    }

    #[Required]
    public function setConfig(Config $config): void
    {
        $this->config = $config;
    }

    /**
     * {@inheritdoc}
     *
     * This intentionally ignores the 'hidden' argument of Symfony's AsCommand
     * attribute, because that is already checked during lazy command loading.
     */
    public function isHidden(): bool
    {
        return $this->hiddenInList
            || !in_array($this->stability, [self::STABILITY_STABLE, self::STABILITY_BETA])
            || $this->config->isCommandHidden($this->getName());
    }

    protected function initialize(InputInterface $input, OutputInterface $output): void
    {
        $this->stdErr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    protected function interact(InputInterface $input, OutputInterface $output): void
    {
        // Work around a bug in Console which means the default command's input
        // is always considered to be interactive.
        // TODO check if this is still needed
        if ($this->getName() === 'welcome'
            && isset($GLOBALS['argv'])
            && array_intersect($GLOBALS['argv'], ['-n', '--no', '-y', '---yes'])) {
            $input->setInteractive(false);
        }
    }

    /**
     * Adds a hidden command option.
     *
     * @param int-mask-of<InputOption::*>|null $mode
     */
    protected function addHiddenOption(string $name, string|null $shortcut = null, ?int $mode = null, string $description = '', mixed $default = null): static
    {
        $this->getDefinition()->addOption(new HiddenInputOption($name, $shortcut, $mode, $description, $default));

        return $this;
    }

    /**
     * Add aliases that should be hidden from help.
     *
     * @see parent::setAliases()
     *
     * @param string[] $hiddenAliases
     *
     * @return static
     */
    protected function setHiddenAliases(array $hiddenAliases): static
    {
        $this->hiddenAliases = $hiddenAliases;
        $this->setAliases(array_merge($this->getAliases(), $hiddenAliases));

        return $this;
    }

    /**
     * Get aliases that should be visible in help.
     *
     * @return string[]
     */
    public function getVisibleAliases(): array
    {
        return array_diff($this->getAliases(), $this->hiddenAliases);
    }

    /**
     * {@inheritdoc}
     *
     * Overrides the default method so that the description is not repeated
     * twice.
     */
    public function getProcessedHelp(): string
    {
        $help = $this->getHelp();
        if ($help === '') {
            return $help;
        }
        $name = $this->getName();

        $placeholders = ['%command.name%', '%command.full_name%'];
        $replacements = [$name, $this->config->getStr('application.executable') . ' ' . $name];

        return str_replace($placeholders, $replacements, $help);
    }

    /**
     * {@inheritdoc}
     */
    public function canBeRunMultipleTimes(): bool
    {
        return $this->canBeRunMultipleTimes;
    }

    public function setRunningViaMulti(bool $runningViaMulti = true): void
    {
        $this->runningViaMulti = $runningViaMulti;
    }

    /**
     * {@inheritdoc}
     */
    public function getSynopsis($short = false): string
    {
        $key = $short ? 'short' : 'long';

        if (!isset($this->synopsis[$key])) {
            $definition = clone $this->getDefinition();
            $definition->setOptions(array_filter($definition->getOptions(), fn(InputOption $opt): bool => !$opt instanceof HiddenInputOption));

            $this->synopsis[$key] = trim(sprintf(
                '%s %s %s',
                $this->config->getStr('application.executable'),
                $this->getPreferredName(),
                $definition->getSynopsis($short),
            ));
        }

        return $this->synopsis[$key];
    }

    /**
     * Returns the preferred command name for use in help.
     *
     * @return string
     */
    public function getPreferredName(): string
    {
        if ($visibleAliases = $this->getVisibleAliases()) {
            return reset($visibleAliases);
        }
        return $this->getName();
    }

    public function isEnabled(): bool
    {
        return $this->config->isCommandEnabled($this->getName());
    }

    /**
     * {@inheritDoc}
     */
    public function getDescription(): string
    {
        $description = parent::getDescription();

        if ($this->stability !== self::STABILITY_STABLE) {
            $tag = $this->stability === self::STABILITY_DEPRECATED ? '<fg=black;bg=yellow>' : '<fg=white;bg=red>';
            $prefix = $tag . strtoupper($this->stability) . '</> ';
            $description = $prefix . $description;
        }

        return $description;
    }

    protected function addCompleter(CompleterInterface $completer): self
    {
        $this->completers[] = $completer;
        return $this;
    }

    public function complete(CompletionInput $input, CompletionSuggestions $suggestions): void
    {
        parent::complete($input, $suggestions);
        foreach ($this->completers as $completer) {
            $completer->complete($input, $suggestions);
        }
    }

    protected function addExample(string $description, string $commandline = ''): self
    {
        $this->examples[] = ['commandline' => $commandline, 'description' => $description];

        return $this;
    }

    /**
     * @return array<array{'commandline': string, 'description': string}>
     */
    public function getExamples(): array
    {
        return $this->examples;
    }
}
