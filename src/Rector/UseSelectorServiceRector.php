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
        'addOrganizationOptions' => 'addOrganizationOptions',
        'addRemoteContainerOptions' => 'addRemoteContainerOptions',
    ];

    private const SELECTOR_METHODS = [
        'getProjectRoot' => 'getProjectRoot',
        'getCurrentProject' => 'getCurrentProject',
        'validateOrganizationInput' => 'selectOrganization',
        'selectedProjectIsCurrent' => 'isProjectCurrent',
    ];

    private const SELECTION_METHODS = [
        'getSelectedProject' => 'getProject',
        'hasSelectedProject' => 'hasProject',
        'getSelectedEnvironment' => 'getEnvironment',
        'hasSelectedEnvironment' => 'hasEnvironment',
    ];

    public function __construct(
        private readonly DependencyInjection $di,
    ) {}

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
            if ($node->name->name === 'validateInput' && $this->getName($node->var) === 'this') {
                $injectSelector = true;
                $args = [new Node\Arg(new Variable('input'))];
                if (count($node->args) > 1) {
                    // Add SelectorConfig.
                    $selectorConfigArgs = [];
                    if (isset($node->args[1])) {
                        $envNotRequired = $node->args[1]->value;

                        // Translate the envNotRequired positional argument to an envRequired named argument.
                        $val = null;
                        if ($envNotRequired instanceof Expr\ConstFetch) {
                            // envRequired is the default, so only set it if it's disabled.
                            if ($this->getName($envNotRequired) === 'true') {
                                $val = new Expr\ConstFetch(new Node\Name('false'));
                            }
                        } elseif ($envNotRequired instanceof Expr\BinaryOp\NotIdentical) {
                            $val = new Expr\BinaryOp\Identical($envNotRequired->left, $envNotRequired->right);
                        } elseif ($envNotRequired instanceof Expr\BinaryOp\Identical) {
                            $val = new Expr\BinaryOp\NotIdentical($envNotRequired->left, $envNotRequired->right);
                        } else {
                            $val = new Expr\BooleanNot($envNotRequired);
                        }

                        if ($val !== null) {
                            $selectorConfigArgs[] = new Arg($val, false, false, [], new Node\Identifier('envRequired'));
                        }
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
            if (isset(self::DEFINITION_METHODS[$node->name->name]) && ($node->var instanceof MethodCall || $this->getName($node->var) === 'this')) {
                $injectSelector = true;
                $hasGetDefinitionArg = function (array $args): bool {
                    /** @var Arg $arg */
                    foreach ($args as $arg) {
                        if ($arg->value instanceof MethodCall && $arg->value->name->name === 'getDefinition') {
                            return true;
                        }
                    }
                    return false;
                };
                if ($node->var instanceof MethodCall) {
                    $node->var = new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), $node->var->name->name);
                    if (!$hasGetDefinitionArg($node->var->args)) {
                        $node->var->args = array_merge([new Arg(new MethodCall(new Variable('this'), 'getDefinition'))], $node->var->args);
                    }
                    $node->name = new Node\Identifier(self::DEFINITION_METHODS[$node->name->name]);
                    if (!$hasGetDefinitionArg($node->args)) {
                        $node->args = array_merge([new Arg(new MethodCall(new Variable('this'), 'getDefinition'))], $node->args);
                    }
                    return $node;
                }
                return new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), self::DEFINITION_METHODS[$node->name->name], array_merge([
                    new Arg(new MethodCall(new Variable('this'), 'getDefinition')),
                ], $node->args));
            }
            if ($this->getName($node->var) === 'this') {
                if (isset(self::SELECTION_METHODS[$node->name->name])) {
                    $injectSelector = true;
                    return new MethodCall(new Variable('selection'), self::SELECTION_METHODS[$node->name->name]);
                }
                if (isset(self::SELECTOR_METHODS[$node->name->name])) {
                    $injectSelector = true;
                    return new MethodCall(new PropertyFetch(new Variable('this'), 'selector'), self::SELECTOR_METHODS[$node->name->name], $node->args);
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
