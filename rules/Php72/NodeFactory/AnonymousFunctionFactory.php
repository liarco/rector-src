<?php

declare(strict_types=1);

namespace Rector\Php72\NodeFactory;

use Nette\Utils\Strings;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\ClosureUse;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\LNumber;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType;
use PHPStan\Reflection\FunctionVariantWithPhpDocs;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\Php\PhpMethodReflection;
use PHPStan\Type\MixedType;
use PHPStan\Type\VoidType;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\PhpParser\Comparing\NodeComparator;
use Rector\Core\PhpParser\Node\BetterNodeFinder;
use Rector\Core\PhpParser\Node\NodeFactory;
use Rector\Core\PhpParser\Parser\SimplePhpParser;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\Node\AttributeKey;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Rector\StaticTypeMapper\StaticTypeMapper;
use Symplify\Astral\NodeTraverser\SimpleCallableNodeTraverser;

final class AnonymousFunctionFactory
{
    /**
     * @var string
     * @see https://regex101.com/r/jkLLlM/2
     */
    private const DIM_FETCH_REGEX = '#(\\$|\\\\|\\x0)(?<number>\d+)#';

    public function __construct(
        private readonly NodeNameResolver $nodeNameResolver,
        private readonly BetterNodeFinder $betterNodeFinder,
        private readonly NodeFactory $nodeFactory,
        private readonly StaticTypeMapper $staticTypeMapper,
        private readonly SimpleCallableNodeTraverser $simpleCallableNodeTraverser,
        private readonly SimplePhpParser $simplePhpParser,
        private readonly NodeComparator $nodeComparator
    ) {
    }

    /**
     * @param Param[] $params
     * @param Stmt[] $stmts
     */
    public function create(
        array $params,
        array $stmts,
        Identifier | Name | NullableType | UnionType | ComplexType | null $returnTypeNode,
        bool $static = false
    ): Closure {
        $useVariables = $this->createUseVariablesFromParams($stmts, $params);

        $anonymousFunctionNode = new Closure();
        $anonymousFunctionNode->params = $params;

        if ($static) {
            $anonymousFunctionNode->static = $static;
        }

        foreach ($useVariables as $useVariable) {
            $anonymousFunctionNode = $this->applyNestedUses($anonymousFunctionNode, $useVariable);
            $anonymousFunctionNode->uses[] = new ClosureUse($useVariable);
        }

        if ($returnTypeNode instanceof Node) {
            $anonymousFunctionNode->returnType = $returnTypeNode;
        }

        $anonymousFunctionNode->stmts = $stmts;
        return $anonymousFunctionNode;
    }

    public function createFromPhpMethodReflection(PhpMethodReflection $phpMethodReflection, Expr $expr): ?Closure
    {
        /** @var FunctionVariantWithPhpDocs $functionVariantWithPhpDoc */
        $functionVariantWithPhpDoc = ParametersAcceptorSelector::selectSingle($phpMethodReflection->getVariants());

        $anonymousFunction = new Closure();
        $newParams = $this->createParams($functionVariantWithPhpDoc->getParameters());

        $anonymousFunction->params = $newParams;

        $innerMethodCall = $this->createInnerMethodCall($phpMethodReflection, $expr, $newParams);
        if ($innerMethodCall === null) {
            return null;
        }

        if (! $functionVariantWithPhpDoc->getReturnType() instanceof MixedType) {
            $returnType = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode(
                $functionVariantWithPhpDoc->getReturnType(),
                TypeKind::RETURN()
            );
            $anonymousFunction->returnType = $returnType;
        }

        // does method return something?
        if (! $functionVariantWithPhpDoc->getReturnType() instanceof VoidType) {
            $anonymousFunction->stmts[] = new Return_($innerMethodCall);
        } else {
            $anonymousFunction->stmts[] = new Expression($innerMethodCall);
        }

        if ($expr instanceof Variable && ! $this->nodeNameResolver->isName($expr, 'this')) {
            $anonymousFunction->uses[] = new ClosureUse($expr);
        }

        return $anonymousFunction;
    }

    public function createAnonymousFunctionFromString(Expr $expr): ?Closure
    {
        if (! $expr instanceof String_) {
            // not supported yet
            throw new ShouldNotHappenException();
        }

        $phpCode = '<?php ' . $expr->value . ';';
        $contentStmts = $this->simplePhpParser->parseString($phpCode);

        $anonymousFunction = new Closure();

        $firstNode = $contentStmts[0] ?? null;
        if (! $firstNode instanceof Expression) {
            return null;
        }

        $stmt = $firstNode->expr;

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($stmt, function (Node $node): Node {
            if (! $node instanceof String_) {
                return $node;
            }

            $match = Strings::match($node->value, self::DIM_FETCH_REGEX);
            if ($match === null) {
                return $node;
            }

            $matchesVariable = new Variable('matches');

            return new ArrayDimFetch($matchesVariable, new LNumber((int) $match['number']));
        });

        $anonymousFunction->stmts[] = new Return_($stmt);
        $anonymousFunction->params[] = new Param(new Variable('matches'));

        return $anonymousFunction;
    }

    /**
     * @param ClosureUse[] $uses
     * @return ClosureUse[]
     */
    private function cleanClosureUses(array $uses): array
    {
        $uniqueUses = [];
        foreach ($uses as $use) {
            if (! is_string($use->var->name)) {
                continue;
            }

            $variableName = $use->var->name;
            if (array_key_exists($variableName, $uniqueUses)) {
                continue;
            }

            $uniqueUses[$variableName] = $use;
        }

        return array_values($uniqueUses);
    }

    private function applyNestedUses(Closure $anonymousFunctionNode, Variable $useVariable): Closure
    {
        $parent = $this->betterNodeFinder->findParentType($useVariable, Closure::class);

        if ($parent instanceof Closure) {
            $paramNames = $this->nodeNameResolver->getNames($parent->params);

            if ($this->nodeNameResolver->isNames($useVariable, $paramNames)) {
                return $anonymousFunctionNode;
            }
        }

        $anonymousFunctionNode = clone $anonymousFunctionNode;
        while ($parent instanceof Closure) {
            $parentOfParent = $this->betterNodeFinder->findParentType($parent, Closure::class);

            $uses = [];
            while ($parentOfParent instanceof Closure) {
                $uses = $this->collectUsesEqual($parentOfParent, $uses, $useVariable);
                $parentOfParent = $this->betterNodeFinder->findParentType($parentOfParent, Closure::class);
            }

            $uses = array_merge($parent->uses, $uses);
            $uses = $this->cleanClosureUses($uses);
            $parent->uses = $uses;

            $parent = $this->betterNodeFinder->findParentType($parent, Closure::class);
        }

        return $anonymousFunctionNode;
    }

    /**
     * @param ClosureUse[] $uses
     * @return ClosureUse[]
     */
    private function collectUsesEqual(Closure $closure, array $uses, Variable $useVariable): array
    {
        foreach ($closure->params as $param) {
            if ($this->nodeComparator->areNodesEqual($param->var, $useVariable)) {
                $uses[] = new ClosureUse($param->var);
            }
        }

        return $uses;
    }

    /**
     * @param Node[] $nodes
     * @param Param[] $paramNodes
     * @return Variable[]
     */
    private function createUseVariablesFromParams(array $nodes, array $paramNodes): array
    {
        $paramNames = [];
        foreach ($paramNodes as $paramNode) {
            $paramNames[] = $this->nodeNameResolver->getName($paramNode);
        }

        $variableNodes = $this->betterNodeFinder->findInstanceOf($nodes, Variable::class);

        /** @var Variable[] $filteredVariables */
        $filteredVariables = [];
        $alreadyAssignedVariables = [];
        foreach ($variableNodes as $variableNode) {
            // "$this" is allowed
            if ($this->nodeNameResolver-> isName($variableNode, 'this')) {
                continue;
            }

            $variableName = $this->nodeNameResolver->getName($variableNode);
            if ($variableName === null) {
                continue;
            }

            if (in_array($variableName, $paramNames, true)) {
                continue;
            }

            $parentNode = $variableNode->getAttribute(AttributeKey::PARENT_NODE);
            if (
                $parentNode instanceof Assign
                || $parentNode instanceof Foreach_
                || $parentNode instanceof Param
            ) {
                $alreadyAssignedVariables[] = $variableName;
            }

            if ($this->nodeNameResolver->isNames($variableNode, $alreadyAssignedVariables)) {
                continue;
            }

            $filteredVariables[$variableName] = $variableNode;
        }

        return $filteredVariables;
    }

    /**
     * @param ParameterReflection[] $parameterReflections
     * @return Param[]
     */
    private function createParams(array $parameterReflections): array
    {
        $params = [];
        foreach ($parameterReflections as $parameterReflection) {
            $param = new Param(new Variable($parameterReflection->getName()));

            if (! $parameterReflection->getType() instanceof MixedType) {
                $param->type = $this->staticTypeMapper->mapPHPStanTypeToPhpParserNode(
                    $parameterReflection->getType(),
                    TypeKind::PARAM()
                );
            }

            $params[] = $param;
        }

        return $params;
    }

    /**
     * @param Param[] $params
     */
    private function createInnerMethodCall(
        PhpMethodReflection $phpMethodReflection,
        Expr $expr,
        array $params
    ): MethodCall | StaticCall | null {
        if ($phpMethodReflection->isStatic()) {
            $expr = $this->normalizeClassConstFetchForStatic($expr);
            if ($expr === null) {
                return null;
            }

            $innerMethodCall = new StaticCall($expr, $phpMethodReflection->getName());
        } else {
            $expr = $this->resolveExpr($expr);
            if (! $expr instanceof Expr) {
                return null;
            }

            $innerMethodCall = new MethodCall($expr, $phpMethodReflection->getName());
        }

        $innerMethodCall->args = $this->nodeFactory->createArgsFromParams($params);

        return $innerMethodCall;
    }

    private function normalizeClassConstFetchForStatic(Expr $expr): null | FullyQualified | Expr
    {
        if (! $expr instanceof ClassConstFetch) {
            return $expr;
        }

        if (! $this->nodeNameResolver->isName($expr->name, 'class')) {
            return $expr;
        }

        // dynamic name, nothing we can do
        $className = $this->nodeNameResolver->getName($expr->class);
        if ($className === null) {
            return null;
        }

        return new FullyQualified($className);
    }

    private function resolveExpr(Expr $expr): New_ | Expr | null
    {
        if (! $expr instanceof ClassConstFetch) {
            return $expr;
        }

        if (! $this->nodeNameResolver->isName($expr->name, 'class')) {
            return $expr;
        }

        // dynamic name, nothing we can do
        $className = $this->nodeNameResolver->getName($expr->class);
        if ($className === null) {
            return null;
        }

        return new New_(new FullyQualified($className));
    }
}
