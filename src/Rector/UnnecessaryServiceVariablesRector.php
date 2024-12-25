<?php

declare(strict_types=1);

namespace Platformsh\Cli\Rector;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Type\ObjectType;
use Platformsh\Cli\Local\LocalApplication;
use Platformsh\Cli\Local\LocalBuild;
use Platformsh\Cli\Local\LocalProject;
use Platformsh\Cli\SshCert\Certifier;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

class UnnecessaryServiceVariablesRector extends AbstractRector
{
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Remove unnecessary service variables', [
            new CodeSample(<<<'END'
                    $formatter = $this->propertyFormatter;
                    $output->writeln($formatter->format($value));
                END, <<<'END'
                    $output->writeln($this->propertyFormatter->format($value));
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
        $variableNamesToPropertyFetches = [];

        foreach ($node->stmts as $stmt) {
            if ($stmt instanceof Node\Stmt\ClassMethod) {
                foreach ($stmt->stmts as $key => $methodStmt) {
                    if ($methodStmt instanceof Node\Stmt\Expression
                        && $methodStmt->expr instanceof Assign
                        && $methodStmt->expr->expr instanceof PropertyFetch
                        && $this->isService($methodStmt->expr->expr)) {
                        $variableName = $this->getName($methodStmt->expr->var);
                        $propertyFetch = $methodStmt->expr->expr;
                        $changed = true;
                        $variableNamesToPropertyFetches[$variableName] = $propertyFetch;
                        unset($stmt->stmts[$key]);
                    }
                }
            }
        }

        $this->traverseNodesWithCallable($node->stmts, function (Node $node) use ($variableNamesToPropertyFetches) {
            if ($node instanceof Node\Expr\MethodCall && isset($variableNamesToPropertyFetches[$this->getName($node->var)])
                && $this->isService($node->var)) {
                $node->var = clone $variableNamesToPropertyFetches[$this->getName($node->var)];
                return $node;
            }
            if ($node instanceof Node\Arg && $node->value instanceof Node\Expr\Variable
                && isset($variableNamesToPropertyFetches[$this->getName($node->value)])
                && $this->isService($node->value)) {
                $node->value = clone $variableNamesToPropertyFetches[$this->getName($node->value)];
                return $node;
            }
            return null;
        });

        if (!$changed) {
            return null;
        }

        return $node;
    }

    private function isService(Node $node): bool
    {
        $type = $this->nodeTypeResolver->getType($node);
        if (!$type instanceof ObjectType) {
            return false;
        }
        $className = $type->getClassName();
        if (str_starts_with($className, 'Platformsh\\Cli\\Service\\')) {
            return true;
        }
        if (in_array($className, [LocalProject::class, LocalBuild::class, LocalApplication::class, Certifier::class])) {
            return true;
        }

        return false;
    }
}
