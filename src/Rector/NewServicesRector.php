<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use PhpParser\Node\Arg;
use PhpParser\NodeAbstract;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Io;
use Platformsh\Cli\Service\Login;
use Platformsh\Cli\Service\ProjectSshInfo;
use Platformsh\Cli\Service\ResourcesUtil;
use Platformsh\Cli\Service\SubCommandRunner;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

class NewServicesRector extends AbstractRector
{
    private readonly array $transforms;

    public function __construct(
        private readonly DependencyInjection $di,
    ) {
        $this->transforms = [
            'addResourcesInitOption' => [ResourcesUtil::class, 'addOption', '_'],
            'validateResourcesInitInput' => [ResourcesUtil::class, 'validateInput', '_'],
            'debug' => [Io::class, 'debug', '_'],
            'isTerminal' => [Io::class, 'isTerminal', '_'],
            'warnAboutDeprecatedOptions' => [Io::class, 'warnAboutDeprecatedOptions', '_'],
            'runOtherCommand' => [SubCommandRunner::class, 'run', '_'],
            'addWaitOptions' => [ActivityMonitor::class, 'addWaitOptions', [new Arg(new MethodCall(new Variable('this'), 'getDefinition'))]],
            'shouldWait' => [ActivityMonitor::class, 'shouldWait', '_'],
            'hasExternalGitHost' => [ProjectSshInfo::class, 'hasExternalGitHost', '_'],
            'getNonInteractiveAuthHelp' => [Login::class, 'getNonInteractiveAuthHelp', '_'],
            'finalizeLogin' => [Login::class, 'finalize', '_'],
            'allServices' => [ResourcesUtil::class, 'allServices', '_'],
            'supportsDisk' => [ResourcesUtil::class, 'supportsDisk', '_'],
            'loadNextDeployment' => [ResourcesUtil::class, 'loadNextDeployment', '_'],
            'filterServices' => [ResourcesUtil::class, 'filterServices', '_'],
            'sizeInfo' => [ResourcesUtil::class, 'sizeInfo', '_'],
            'formatChange' => [ResourcesUtil::class, 'formatChange', '_'],
            'formatCPU' => [ResourcesUtil::class, 'formatCPU', '_'],
        ];
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Use various new services instead of old CommandBase methods', [
            new CodeSample(<<<'END'
                    $this->addWaitOptions();
                    $this->runSubCommand('foo');
                END, <<<'END'
                    $this->activityMonitor->addWaitOptions($this->getDefinition());
                    $this->subCommandRunnder->run('foo');
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

        $changed = false;
        $injections = [];

        $this->traverseNodesWithCallable($node, function (NodeAbstract $node) use (&$injections, &$changed) {
            if (!$node instanceof MethodCall || !isset($this->transforms[$node->name->name]) || $this->getName($node->var) !== 'this') {
                return null;
            }
            $changed = true;
            /** @var string|Arg[] $args */
            [$serviceClassName, $methodName, $args] = $this->transforms[$node->name->name];

            // Add the injection.
            $serviceClass = '\\' . $serviceClassName;
            $parts = explode('\\', $serviceClassName);
            $serviceClassBase = end($parts);
            $propertyName = lcfirst($serviceClassBase);
            $injections[$serviceClass] = $propertyName;

            // Replace the method call with a property call.
            $node->var = new PropertyFetch(new Variable('this'), $propertyName);
            $node->name = new Node\Identifier($methodName);

            if ($args !== '_') {
                $node->args = $args;
            }

            return $node;
        });

        if (!$changed) {
            return null;
        }

        $useConstructor = !str_ends_with($node->name->name, 'Base');

        natsort($injections);
        foreach ($injections as $serviceClass => $propertyName) {
            $this->di->addDependencyInjection($node, $propertyName, $serviceClass, $useConstructor);
        }

        return $node;
    }
}
