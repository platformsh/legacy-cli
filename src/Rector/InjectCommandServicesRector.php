<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use Platformsh\Cli\Service\ActivityLoader;
use Platformsh\Cli\Service\ActivityMonitor;
use Platformsh\Cli\Service\Api;
use Platformsh\Cli\Local\ApplicationFinder;
use Platformsh\Cli\Service\Config;
use Platformsh\Cli\Service\CurlCli;
use Platformsh\Cli\Service\Drush;
use Platformsh\Cli\Service\FileLock;
use Platformsh\Cli\Service\Filesystem;
use Platformsh\Cli\Service\Git;
use Platformsh\Cli\Service\GitDataApi;
use Platformsh\Cli\Service\Identifier;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\Local\DependencyInstaller;
use Platformsh\Cli\Service\Mount;
use Platformsh\Cli\Service\PropertyFormatter;
use Platformsh\Cli\Service\QuestionHelper;
use Platformsh\Cli\Service\RemoteEnvVars;
use Platformsh\Cli\Service\Relationships;
use Platformsh\Cli\Service\Rsync;
use Platformsh\Cli\Service\SelfUpdater;
use Platformsh\Cli\Service\Shell;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Cli\Service\SshConfig;
use Platformsh\Cli\Service\SshDiagnostics;
use Platformsh\Cli\Service\SshKey;
use Platformsh\Cli\Service\State;
use Platformsh\Cli\Service\Table;
use Platformsh\Cli\Service\TokenConfig;
use Platformsh\Cli\Service\Url;
use PhpParser\Node\Stmt\If_;
use Doctrine\Common\Cache\CacheProvider;
use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
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
        'activity_loader' => ActivityLoader::class,
        'activity_monitor' => ActivityMonitor::class,
        'api' => Api::class,
        'app_finder' => ApplicationFinder::class,
        'cache' => CacheProvider::class,
        'certifier' => Certifier::class,
        'config' => Config::class,
        'curl_cli' => CurlCli::class,
        'drush' => Drush::class,
        'file_lock' => FileLock::class,
        'fs' => Filesystem::class,
        'git' => Git::class,
        'git_data_api' => GitDataApi::class,
        'identifier' => Identifier::class,
        'local.build' => LocalBuild::class,
        'local.project' => LocalProject::class,
        'local.dependency_installer' => DependencyInstaller::class,
        'mount' => Mount::class,
        'property_formatter' => PropertyFormatter::class,
        'question_helper' => QuestionHelper::class,
        'remote_env_vars' => RemoteEnvVars::class,
        'relationships' => Relationships::class,
        'rsync' => Rsync::class,
        'self_updater' => SelfUpdater::class,
        'shell' => Shell::class,
        'ssh' => Ssh::class,
        'ssh_config' => SshConfig::class,
        'ssh_diagnostics' => SshDiagnostics::class,
        'ssh_key' => SshKey::class,
        'state' => State::class,
        'table' => Table::class,
        'token_config' => TokenConfig::class,
        'url' => Url::class,
    ];

    private const METHOD_TO_SERVICE = [
        'api' => Api::class,
        'config' => Config::class,
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
                if ($stmt instanceof If_) {
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

        usort($method->params, fn (Param $a, Param $b): int => $a->var->name <=> $b->var->name);
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
