<?php

declare(strict_types=1);

namespace Rector\DeadCode;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\NotEqual;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\Empty_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\If_;
use PHPStan\Type\ArrayType;
use Rector\Core\NodeAnalyzer\ParamAnalyzer;
use Rector\Core\PhpParser\Comparing\NodeComparator;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\NodeTypeResolver\NodeTypeResolver;

final class UselessIfCondBeforeForeachDetector
{
    public function __construct(
        private readonly NodeTypeResolver $nodeTypeResolver,
        private readonly NodeComparator $nodeComparator,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly ParamAnalyzer $paramAnalyzer
    ) {
    }

    /**
     * Matches:
     * !empty($values)
     */
    public function isMatchingNotEmpty(If_ $if, Expr $foreachExpr): bool
    {
        $cond = $if->cond;
        if (! $cond instanceof BooleanNot) {
            return false;
        }

        if (! $cond->expr instanceof Empty_) {
            return false;
        }

        /** @var Empty_ $empty */
        $empty = $cond->expr;

        if (! $this->nodeComparator->areNodesEqual($empty->expr, $foreachExpr)) {
            return false;
        }

        // is array though?
        $arrayType = $this->nodeTypeResolver->getType($empty->expr);
        if (! $arrayType instanceof ArrayType) {
            return false;
        }

        $previousParam = $this->fromPreviousParam($foreachExpr);
        if (! $previousParam instanceof Param) {
            return true;
        }

        if ($this->paramAnalyzer->isNullable($previousParam)) {
            return false;
        }

        return ! $this->paramAnalyzer->hasDefaultNull($previousParam);
    }

    /**
     * Matches:
     * $values !== []
     * $values != []
     * [] !== $values
     * [] != $values
     */
    public function isMatchingNotIdenticalEmptyArray(If_ $if, Expr $foreachExpr): bool
    {
        if (! $if->cond instanceof NotIdentical && ! $if->cond instanceof NotEqual) {
            return false;
        }

        /** @var NotIdentical|NotEqual $notIdentical */
        $notIdentical = $if->cond;

        return $this->isMatchingNotBinaryOp($notIdentical, $foreachExpr);
    }

    private function fromPreviousParam(Expr $expr): ?Node
    {
        return $this->betterNodeFinder->findFirstPreviousOfNode($expr, function (Node $node) use ($expr): bool {
            if (! $node instanceof Param) {
                return false;
            }

            if (! $node->var instanceof Variable) {
                return false;
            }

            return $this->nodeComparator->areNodesEqual($node->var, $expr);
        });
    }

    private function isMatchingNotBinaryOp(NotIdentical | NotEqual $binaryOp, Expr $foreachExpr): bool
    {
        if ($this->isEmptyArrayAndForeachedVariable($binaryOp->left, $binaryOp->right, $foreachExpr)) {
            return true;
        }

        return $this->isEmptyArrayAndForeachedVariable($binaryOp->right, $binaryOp->left, $foreachExpr);
    }

    private function isEmptyArrayAndForeachedVariable(Expr $leftExpr, Expr $rightExpr, Expr $foreachExpr): bool
    {
        if (! $this->isEmptyArray($leftExpr)) {
            return false;
        }

        return $this->nodeComparator->areNodesEqual($foreachExpr, $rightExpr);
    }

    private function isEmptyArray(Expr $expr): bool
    {
        if (! $expr instanceof Array_) {
            return false;
        }

        return $expr->items === [];
    }
}
