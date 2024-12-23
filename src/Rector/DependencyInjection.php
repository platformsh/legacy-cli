<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use PhpParser\Builder\Method;
use PhpParser\BuilderFactory;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use Rector\NodeManipulator\ClassInsertManipulator;
use Rector\PhpAttribute\NodeFactory\PhpAttributeGroupFactory;
use Rector\PhpParser\Node\NodeFactory;
use Symfony\Contracts\Service\Attribute\Required;

readonly class DependencyInjection
{
    public function __construct(
        private ClassInsertManipulator   $classInsertManipulator,
        private BuilderFactory           $builderFactory,
        private PhpAttributeGroupFactory $phpAttributeGroupFactory,
        private NodeFactory              $nodeFactory,
    ) {}

    public function addDependencyInjection(Class_ $classNode, string $propertyName, string $serviceClass, bool $constructor = true): void
    {
        $methodName = $constructor ? '__construct' : 'autowire';
        $method = $this->getOrCreateMethod($classNode, $methodName);

        foreach ($method->params as $existingParam) {
            if ($existingParam->var->name === $propertyName) {
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
                ->setType($serviceClass)
                ->getNode();
            array_unshift($classNode->stmts, $property);
            $method->params[] = $this->builderFactory->param($propertyName)
                ->setType($serviceClass)
                ->getNode();
            $method->stmts[] = new Expression(new Assign(
                new PropertyFetch(new Variable('this'), $propertyName),
                new Variable($propertyName),
            ));
        }

        usort($method->params, fn(Param $a, Param $b): int => $a->var->name <=> $b->var->name);
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
