<?php

declare(strict_types=1);

namespace WebmanAotBuilder\Compatibility;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use WebmanAotBuilder\Cli\ConfigurationException;
use WebmanAotBuilder\Project\ProjectMirror;

final class ArrayReturnTypeRule
{
    private array $functions = [];
    private array $interfaces = [];
    public function apply(string $mirror, array $ignoredSources = []): array
    {
        if (!ProjectMirror::isOwnedPath($mirror)) {
            throw new ConfigurationException('array return adaptation requires an isolated project mirror');
        }
        $mirror = (string) realpath($mirror);
        $this->functions = [];
        $this->interfaces = [];
        $sources = [];
        $children = [];
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mirror, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') { continue; }
            if ($file->isLink()) { throw new ConfigurationException('array return adaptation refuses linked source'); }
            $source = file_get_contents($file->getPathname());
            if (!is_string($source)) { throw new ConfigurationException('array return adaptation cannot read source'); }
            try {
                $nodes = $parser->parse($source) ?? [];
                $resolver = new NodeTraverser();
                $resolver->addVisitor(new NameResolver());
                $nodes = $resolver->traverse($nodes);
            } catch (\PhpParser\Error) { continue; }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Function_::class) as $function) {
                $this->functions[strtolower($function->namespacedName?->toString() ?? $function->name->toString())] = true;
            }
            $classes = $finder->findInstanceOf($nodes, Node\Stmt\Class_::class);
            foreach ($classes as $class) {
                if ($class->extends !== null) { $children[strtolower($class->extends->toString())] = true; }
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($mirror) + 1));
            if (in_array($relative, $ignoredSources, true)) { continue; }
            foreach ($finder->findInstanceOf($nodes, Node\Stmt\Interface_::class) as $interface) {
                $identity = strtolower($interface->namespacedName?->toString() ?? $interface->name->toString());
                $this->interfaces[$identity] = array_key_exists($identity, $this->interfaces) ? null : $interface;
            }
            foreach ($classes as $class) {
                foreach ($class->getMethods() as $method) {
                    if (strtolower($method->name->toString()) === 'toarray' && $method->returnType === null) {
                        $sources[$file->getPathname()] = [$source, $classes];
                        break 2;
                    }
                }
            }
        }
        $digests = [];
        foreach ($sources as $file => [$source, $classes]) {
            $edits = [];
            foreach ($classes as $class) {
                if ($class->name === null || $class->isAbstract() || $class->extends !== null
                    || isset($children[strtolower($class->namespacedName?->toString() ?? $class->name->toString())])) { continue; }
                foreach ($class->getMethods() as $method) {
                    if (strtolower($method->name->toString()) !== 'toarray' || $method->returnType !== null
                        || $method->byRef || $method->stmts === null || $method->stmts === [] || $this->methodYields($method->stmts)
                        || (!$this->returnsArray($method->stmts, $class) && !$this->arrayInterfaceContract($class))) { continue; }
                    $signature = substr($source, $method->name->getEndFilePos() + 1, $method->stmts[0]->getStartFilePos() - $method->name->getEndFilePos() - 1);
                    $offset = $this->signatureEnd($signature);
                    if ($offset !== null) { $edits[] = $method->name->getEndFilePos() + 1 + $offset; }
                }
            }
            rsort($edits, SORT_NUMERIC);
            foreach ($edits as $offset) { $source = substr_replace($source, ': array', $offset, 0); }
            if ($edits === []) { continue; }
            try { $parser->parse($source); }
            catch (\PhpParser\Error $error) {
                throw new ConfigurationException('array return adaptation produced invalid syntax', previous: $error);
            }
            if (file_put_contents($file, $source, LOCK_EX) !== strlen($source)) {
                throw new ConfigurationException('cannot write isolated array return adaptation');
            }
            $relative = str_replace('\\', '/', substr($file, strlen($mirror) + 1));
            $digests[$relative] = ['beforeSha256' => hash('sha256', $sources[$file][0]), 'afterSha256' => hash('sha256', $source)];
            fwrite(STDERR, '[adapt] array return declaration: ' . $relative . PHP_EOL);
        }
        ksort($digests, SORT_STRING);
        return ['sha256' => hash('sha256', hash_file('sha256', __FILE__) . json_encode($digests, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)), 'files' => $digests];
    }

    private function methodYields(array $statements): bool
    {
        $yield = false;
        $scan = function (Node $node) use (&$scan, &$yield): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) { return; }
            if ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) { $yield = true; }
            foreach ($node->getSubNodeNames() as $name) {
                foreach (is_array($node->$name) ? $node->$name : [$node->$name] as $child) { if ($child instanceof Node) { $scan($child); } }
            }
        };
        foreach ($statements as $statement) { $scan($statement); }
        return $yield;
    }

    private function arrayInterfaceContract(Node\Stmt\Class_ $class): bool
    {
        $queue = array_map(static fn(Node\Name $name): string => strtolower($name->toString()), $class->implements);
        $visited = [];
        $array = false;
        while ($queue !== []) {
            $name = array_pop($queue);
            if (isset($visited[$name])) { continue; }
            $visited[$name] = true;
            $interface = $this->interfaces[$name] ?? null;
            if (!$interface instanceof Node\Stmt\Interface_) { continue; }
            foreach ($interface->extends as $parent) { $queue[] = strtolower($parent->toString()); }
            $method = $interface->getMethod('toArray');
            if ($method === null) { continue; }
            if ($method->byRef) { return false; }
            if ($method->returnType !== null) {
                if (!$this->acceptsArrayReturn($method->returnType)) { return false; }
                if ($method->returnType instanceof Node\Identifier && strtolower($method->returnType->toString()) === 'array') { $array = true; }
                continue;
            }
            $comment = $method->getDocComment()?->getText() ?? '';
            if (substr_count($comment, '@return') === 1 && preg_match('~@return\s+array(?:<[^>\r\n]+>)?(?=\s|\*|$)~', $comment) === 1) { $array = true; }
            elseif (str_contains($comment, '@return')) { return false; }
        }
        return $array;
    }

    private function acceptsArrayReturn(Node $type): bool
    {
        if ($type instanceof Node\NullableType) { return $this->acceptsArrayReturn($type->type); }
        if ($type instanceof Node\UnionType) {
            foreach ($type->types as $member) { if ($this->acceptsArrayReturn($member)) { return true; } }
            return false;
        }
        return $type instanceof Node\Identifier && in_array(strtolower($type->toString()), ['array', 'iterable', 'mixed'], true);
    }

    private function returnsArray(array $statements, Node\Stmt\Class_ $class): bool
    {
        $last = end($statements);
        if (!$last instanceof Node\Stmt\Return_ || !$this->arrayExpression($last->expr, $class)) { return false; }
        $valid = true;
        $scan = function (Node $node) use (&$scan, &$valid, $class): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) { return; }
            if ($node instanceof Node\Stmt\Return_ && !$this->arrayExpression($node->expr, $class)) { $valid = false; }
            if ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) { $valid = false; }
            foreach ($node->getSubNodeNames() as $name) {
                $children = is_array($node->$name) ? $node->$name : [$node->$name];
                foreach ($children as $child) { if ($child instanceof Node) { $scan($child); } }
            }
        };
        foreach ($statements as $statement) { $scan($statement); }
        return $valid;
    }

    private function arrayExpression(?Node $expression, Node\Stmt\Class_ $class, array $locals = [], array $seen = []): bool
    {
        if ($expression instanceof Node\Expr\Array_ || $expression instanceof Node\Expr\Cast\Array_) { return true; }
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name)) { return ($locals[$expression->name]['array'] ?? false) === true; }
        if ($expression instanceof Node\Expr\FuncCall && $expression->name instanceof Node\Name && strtolower($expression->name->toString()) === 'array_merge') {
            $name = $class->namespacedName?->toString() ?? $class->name->toString();
            $namespace = str_contains($name, '\\') ? substr($name, 0, strrpos($name, '\\')) . '\\' : '';
            if (!$expression->name instanceof Node\Name\FullyQualified && isset($this->functions[strtolower($namespace . 'array_merge')])) { return false; }
            foreach ($expression->args as $argument) {
                if (!$argument instanceof Node\Arg || $argument->unpack || !$this->readOnlyExpression($argument->value, $class)) { return false; }
            }
            return true;
        }
        if (!$expression instanceof Node\Expr\MethodCall || !$expression->var instanceof Node\Expr\Variable
            || $expression->var->name !== 'this' || !$expression->name instanceof Node\Identifier || $expression->args !== []
            || count($seen) >= 8
        ) { return false; }
        $name = strtolower($expression->name->toString());
        if (isset($seen[$name])) { return false; }
        $method = $class->getMethod($name);
        if ($method === null || $method->byRef || $method->stmts === null || $method->isStatic()) { return false; }
        $seen[$name] = true;
        $locals = [];
        foreach ($method->params as $parameter) {
            if ($parameter->byRef || $parameter->variadic || !is_string($parameter->var->name)) { return false; }
            [$known, $value] = $this->constantValue($parameter->default, []);
            if (!$known) { return false; }
            $locals[$parameter->var->name] = ['array' => is_array($value), 'known' => true, 'value' => $value];
        }
        $yield = false;
        $scan = function (Node $node) use (&$scan, &$yield): void {
            if ($node instanceof Node\FunctionLike || $node instanceof Node\Stmt\ClassLike) { return; }
            if ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) { $yield = true; }
            foreach ($node->getSubNodeNames() as $key) {
                foreach (is_array($node->$key) ? $node->$key : [$node->$key] as $child) { if ($child instanceof Node) { $scan($child); } }
            }
        };
        foreach ($method->stmts as $statement) { $scan($statement); }
        if ($yield) { return false; }
        return $this->arrayStatements($method->stmts, $class, $locals, $seen) === true;
    }

    private function arrayStatements(array $statements, Node\Stmt\Class_ $class, array &$locals, array $seen): ?bool
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Node\Stmt\Return_) { return $this->arrayExpression($statement->expr, $class, $locals, $seen); }
            if ($statement instanceof Node\Stmt\Expression && $statement->expr instanceof Node\Expr\Assign
                && $statement->expr->var instanceof Node\Expr\Variable && is_string($statement->expr->var->name)
            ) {
                $expression = $statement->expr->expr;
                [$known, $value] = $this->constantValue($expression, $locals);
                $array = $this->arrayExpression($expression, $class, $locals, $seen);
                if (!$known && !$array) { return false; }
                $locals[$statement->expr->var->name] = ['array' => $array, 'known' => $known, 'value' => $value];
            } elseif ($statement instanceof Node\Stmt\If_) {
                [$known, $value] = $this->constantValue($statement->cond, $locals);
                if (!$known || $statement->elseifs !== []) { return false; }
                $result = $this->arrayStatements($value ? $statement->stmts : ($statement->else?->stmts ?? []), $class, $locals, $seen);
                if ($result !== null) { return $result; }
            } elseif (!$statement instanceof Node\Stmt\Nop) { return false; }
        }
        return null;
    }

    private function constantValue(?Node $expression, array $locals): array
    {
        if ($expression instanceof Node\Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            if (in_array($name, ['null', 'true', 'false'], true)) { return [true, match ($name) { 'true' => true, 'false' => false, default => null }]; }
        }
        if ($expression instanceof Node\Scalar\Int_ || $expression instanceof Node\Scalar\Float_ || $expression instanceof Node\Scalar\String_) { return [true, $expression->value]; }
        if ($expression instanceof Node\Expr\Array_ && $expression->items === []) { return [true, []]; }
        if ($expression instanceof Node\Expr\Variable && is_string($expression->name) && ($locals[$expression->name]['known'] ?? false)) { return [true, $locals[$expression->name]['value']]; }
        if ($expression instanceof Node\Expr\BooleanNot) { [$known, $value] = $this->constantValue($expression->expr, $locals); return [$known, !$value]; }
        return [false, null];
    }

    private function readOnlyExpression(Node $expression, Node\Stmt\Class_ $class): bool
    {
        if ($expression instanceof Node\Expr\Variable || $expression instanceof Node\Scalar || $expression instanceof Node\Expr\ConstFetch) { return true; }
        if ($expression instanceof Node\Expr\Array_) {
            foreach ($expression->items as $item) { if ($item === null || $item->unpack || !$this->readOnlyExpression($item->value, $class) || ($item->key !== null && !$this->readOnlyExpression($item->key, $class))) { return false; } }
            return true;
        }
        if ($expression instanceof Node\Expr\PropertyFetch && $expression->var instanceof Node\Expr\Variable && $expression->var->name === 'this' && $expression->name instanceof Node\Identifier) {
            foreach ($class->getProperties() as $property) {
                foreach ($property->props as $item) { if ($item->name->toString() === $expression->name->toString() && ($property->hooks ?? []) === []) { return true; } }
            }
        }
        return false;
    }

    private function signatureEnd(string $signature): ?int
    {
        $offset = -6;
        $depth = 0;
        foreach (token_get_all('<?php ' . $signature) as $token) {
            $text = is_array($token) ? $token[1] : $token;
            $offset += strlen($text);
            if ($token === '(') { ++$depth; }
            if ($token === ')' && --$depth === 0) { return $offset; }
        }
        return null;
    }
}
