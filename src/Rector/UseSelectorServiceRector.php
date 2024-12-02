<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\NodeAbstract;
use Platformsh\Cli\Selector\Selector;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use Platformsh\Cli\Selector\SelectorConfig;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

class UseSelectorServiceRector extends AbstractRector
{
    private const DEFINITION_METHODS = [
        'addProjectOption' => 'addProjectOption',
        'addEnvironmentOption' => 'addEnvironmentOption',
        'addAppOption' => 'addAppOption',
    ];

    private const SELECTOR_METHODS = [
        'getProjectRoot' => 'getProjectRoot',
        'getCurrentProject' => 'getCurrentProject',
    ];

    private const SELECTION_METHODS = [
        'getSelectedProject' => 'getProject',
        'hasSelectedProject' => 'hasProject',
        'getSelectedEnvironment' => 'getEnvironment',
        'hasSelectedEnvironment' => 'hasEnvironment',
    ];

    public function __construct(
        private readonly DependencyInjection $di,
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Use Selector service instead of old CommandBase methods', [
            new CodeSample(<<<'END'
                $this->validateInput($input);
                $project = $this->getSelectedProject();
                $environment = $this->getSelectedEnvironment();
                $projectRoot = $this->getProjectRoot();
                $currentProject = $this->getCurrentProject();
            END, <<<'END'
                $selection = $this->selector->getSelection($input);
                $project = $selection->getProject();
                $environment = $this->getSelectedEnvironment();
                $projectRoot = $this->selector->getProjectRoot();
                $currentProject = $this->selector->getCurrentProject();
            END),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!($node instanceof Class_ && str_contains($node->name->name, 'Command'))) {
            return null;
        }

        $injectSelector = false;

        $this->traverseNodesWithCallable($node, function (NodeAbstract $node) use (&$injectSelector) {
            if (!$node instanceof MethodCall) {
                return null;
            }
            if ($node->name->name === 'validateInput') {
                $injectSelector = true;
                $args = [new Node\Arg(new Variable('input'))];
                if (count($node->args) > 1) {
                    // Add SelectorConfig.
                    $selectorConfigArgs = [];
                    if (isset($node->args[1])) {
                        $selectorConfigArgs[] = new Arg(new Expr\BooleanNot($node->args[1]->value), false, false, [], new Node\Identifier('envRequired'));
                    }
                    if (isset($node->args[2])) {
                        $selectorConfigArgs[] = new Arg($node->args[2]->value, false, false, [], new Node\Identifier('selectDefaultEnv'));
                    }
                    if (isset($node->args[3])) {
                        $selectorConfigArgs[] = new Arg($node->args[3]->value, false, false, [], new Node\Identifier('detectCurrentEnv'));
                    }
                    $args[] = new Arg(new Node\Expr\New_(new Node\Name('\\' . SelectorConfig::class), $selectorConfigArgs));
                }
                return new Assign(
                    new Variable('selection'),
                    new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), 'getSelection', $args),
                );
            }
            if ($node->var instanceof MethodCall && isset(self::DEFINITION_METHODS[$node->name->name])) {
                // Chained method calls.
                $injectSelector = true;
                $node->args = [new Arg(new MethodCall(new Variable('this'), 'getDefinition'))];
                return $node;
            }
            if ($node->var->name === 'this') {
                if (isset(self::SELECTION_METHODS[$node->name->name])) {
                    $injectSelector = true;
                    return new MethodCall(new Variable('selection'), self::SELECTION_METHODS[$node->name->name]);
                }
                if (isset(self::DEFINITION_METHODS[$node->name->name])) {
                    $injectSelector = true;
                    return new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), self::DEFINITION_METHODS[$node->name->name], [
                        new Arg(new MethodCall(new Variable('this'), 'getDefinition')),
                    ]);
                }
                if (isset(self::SELECTOR_METHODS[$node->name->name])) {
                    $injectSelector = true;
                    return new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), self::SELECTOR_METHODS[$node->name->name]);
                }
            }
            return null;
        });

        if ($injectSelector) {
            $useConstructor = !str_ends_with($node->name->name, 'Base');
            $this->di->addDependencyInjection($node, 'selector', '\\' . Selector::class, $useConstructor);
            return $node;
        }

        return null;
    }
}
