<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use Doctrine\Common\Cache\CacheProvider;
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Platformsh\Cli\Local;
use Platformsh\Cli\Service;
use Platformsh\Cli\SshCert\Certifier;
use Rector\NodeManipulator\ClassInsertManipulator;
use Rector\PhpAttribute\NodeFactory\PhpAttributeGroupFactory;
use Rector\PhpParser\Node\BetterNodeFinder;
use Rector\Rector\AbstractRector;
use Symfony\Contracts\Service\Attribute\Required;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

class InjectCommandServicesRector extends AbstractRector
{
    private const SERVICE_CLASS_NAMES = [
        'activity_loader' => Service\ActivityLoader::class,
        'activity_monitor' => Service\ActivityMonitor::class,
        'api' => Service\Api::class,
        'app_finder' => Local\ApplicationFinder::class,
        'cache' => CacheProvider::class,
        'certifier' => Certifier::class,
        'config' => Service\Config::class,
        'curl_cli' => Service\CurlCli::class,
        'drush' => Service\Drush::class,
        'file_lock' => Service\FileLock::class,
        'fs' => Service\Filesystem::class,
        'git' => Service\Git::class,
        'git_data_api' => Service\GitDataApi::class,
        'identifier' => Identifier::class,
        'local.build' => Local\LocalBuild::class,
        'local.project' => Local\LocalProject::class,
        'local.dependency_installer' => Local\DependencyInstaller::class,
        'mount' => Service\Mount::class,
        'property_formatter' => Service\PropertyFormatter::class,
        'question_helper' => Service\QuestionHelper::class,
        'remote_env_vars' => Service\RemoteEnvVars::class,
        'relationships' => Service\Relationships::class,
        'rsync' => Service\Rsync::class,
        'self_updater' => Service\SelfUpdater::class,
        'shell' => Service\Shell::class,
        'ssh' => Service\Ssh::class,
        'ssh_config' => Service\SshConfig::class,
        'ssh_diagnostics' => Service\SshDiagnostics::class,
        'ssh_key' => Service\SshKey::class,
        'state' => Service\State::class,
        'table' => Service\Table::class,
        'token_config' => Service\TokenConfig::class,
        'url' => Service\Url::class,
    ];

    private const METHOD_TO_SERVICE = [
        'api' => Service\Api::class,
        'config' => Service\Config::class,
    ];

    public function __construct(
        private readonly BetterNodeFinder       $betterNodeFinder,
        private readonly ClassInsertManipulator $classInsertManipulator,
        private readonly BuilderFactory         $builderFactory,
        private readonly PhpAttributeGroupFactory $phpAttributeGroupFactory
    ) {
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Replace $this->getService() with constructor-injected service', [
            new CodeSample('$foo = $this->getService(\'foo\');', '$foo = $this->foo;'),
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

        // Build a list of classes to inject (property names keyed by class names).
        $injections = [];

        /** @var MethodCall $methodCall */
        foreach ($this->betterNodeFinder->findInstancesOf($node, [MethodCall::class]) as $methodCall) {
            if (isset(self::METHOD_TO_SERVICE[$methodCall->name->name])) {
                $serviceClass = '\\' . self::METHOD_TO_SERVICE[$methodCall->name->name];
                $parts = explode('\\', $serviceClass);
                $serviceClassBase = end($parts);
                $propertyName = lcfirst($serviceClassBase);
                $injections[$serviceClass] = $propertyName;
            }
        }

        /** @var Assign[] $assignments */
        // Note: the betterNodeFinder does not seem to allow modifying the returned nodes.
        $assignments = $this->findAssignmentsRecursively($node->stmts);
        foreach ($assignments as &$assignment) {
            if ($assignment->expr instanceof MethodCall) {
                $method = $assignment->expr;
                if ($method->name->name !== 'getService' || $this->getName($method->var) !== 'this') {
                    continue;
                }
                $firstArg = $method->getArgs()[0];
                if (!$firstArg->value instanceof String_) {
                    throw new \RuntimeException("Unexpected arguments found, line " . $assignment->getStartLine());
                }
                $serviceName = $firstArg->value->value;
                if (!isset(self::SERVICE_CLASS_NAMES[$serviceName])) {
                    throw new \RuntimeException("Class not found for service name $serviceName");
                }
                $serviceClass = '\\' . self::SERVICE_CLASS_NAMES[$serviceName];
                $parts = explode('\\', $serviceClass);
                $serviceClassBase = end($parts);
                $propertyName = lcfirst($serviceClassBase);
                $injections[$serviceClass] = $propertyName;
                $assignment = new Assign($assignment->var, $this->nodeFactory->createPropertyFetch('this', $propertyName));
            }
        }

        $useConstructor = !str_ends_with($node->name->name, 'Base');

        natsort($injections);
        foreach ($injections as $serviceClass => $propertyName) {
            $this->addDependencyInjection($node, $propertyName, $serviceClass, $useConstructor);
        }

        return $node;
    }

    /**
     * @var Node\Stmt[] $stmts
     */
    private function findAssignmentsRecursively(array $stmts): array
    {
        $assignments = [];
        foreach ($stmts as $stmt) {
            if (property_exists($stmt, 'stmts') && is_array($stmt->stmts)) {
                $assignments = array_merge($assignments, $this->findAssignmentsRecursively($stmt->stmts));
                if ($stmt instanceof Node\Stmt\If_) {
                    $assignments = array_merge($assignments, $this->findAssignmentsRecursively($stmt->elseifs));
                    if ($stmt->else !== null) {
                        $assignments = array_merge($assignments, $this->findAssignmentsRecursively($stmt->else->stmts));
                    }
                }
            } elseif ($stmt instanceof Expression) {
                $expr = &$stmt->expr;
                if ($expr instanceof Assign) {
                    if ($expr->expr instanceof MethodCall) {
                        $method = $expr->expr;
                        if ($method->name->name === 'getService') {
                            $assignments[] = &$expr;
                        }
                    }
                }
            }
        }
        return $assignments;
    }

    private function addDependencyInjection(Class_ $classNode, string $propertyName, string $serviceClass, bool $constructor = true): void
    {
        $methodName = $constructor ? '__construct' : 'autowire';
        $method = $this->getOrCreateMethod($classNode, $methodName);

        foreach ($method->params as $existingParam) {
            if ($existingParam->type === $serviceClass) {
                return;
            }
        }

        if ($constructor) {
            $method->params[] = $this->builderFactory->param($propertyName)
                ->setType($serviceClass)
                ->makeReadonly()
                ->makePrivate()
                ->getNode();
        } else {
            $property = $this->builderFactory->property($propertyName)
                ->makePrivate()
                ->makeReadonly()
                ->setType($serviceClass)
                ->getNode();
            array_unshift($classNode->stmts, $property);
            $method->params[] = $this->builderFactory->param($propertyName)
                ->setType($serviceClass)
                ->getNode();
            $method->stmts[] = new Expression(new Assign(
                new PropertyFetch(new Variable('this'), $propertyName),
                new Variable($propertyName)
            ));
        }

        usort($method->params, fn (Param $a, Param $b) => $a->var->name <=> $b->var->name);
    }

    private function getOrCreateMethod(Class_ $classNode, string $name): ClassMethod
    {
        if ($existing = $classNode->getMethod($name)) {
            return $existing;
        }

        $isConstructor = $name === '__construct';

        $methodBuilder = new Method($name);
        $methodBuilder->makePublic();
        if ($name !== '__construct') {
            $methodBuilder->setReturnType('void');
        }

        // Because all the commands are child classes, add a parent constructor call.
        if ($classNode->extends !== null && $name === '__construct') {
            $parentCall = $this->nodeFactory->createStaticCall('parent', '__construct');
            $methodBuilder->addStmt($parentCall);
        }

        $classMethod = $methodBuilder->getNode();

        // Add #[Required] attribute if the method is not the constructor.
        if (!$isConstructor) {
            $attributeGroup = $this->phpAttributeGroupFactory->createFromClass(Required::class);
            $classMethod->attrGroups[] = $attributeGroup;
        }

        // Add the method to the class.
        $this->classInsertManipulator->addAsFirstMethod($classNode, $classMethod);

        return $classMethod;
    }
}
