<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\QueryBuilder;
use PHPUnit\Framework\TestCase;

/** 固定公开接口契约，同时允许将实现迁移到内部协作类。 */
final class QueryBuilderPublicApiTest extends TestCase
{
    public function testPublicSignaturesAndConstantsRemainCompatible(): void
    {
        $expected = json_decode(file_get_contents(__DIR__ . '/resources/query-builder-public-api.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($expected, self::publicApi());
    }

    public function testSubclassFactoriesChainingAndResetRemainCompatible(): void
    {
        $builder = new class extends QueryBuilder {};
        $subquery = $builder::subQuery('u');
        self::assertInstanceOf($builder::class, $subquery);
        self::assertSame($subquery, $subquery->where('active', true)->get('users', null, 'id'));
        self::assertSame('u', $subquery->getSubQuery()['alias']);
        self::assertSame([true], $subquery->getSubQuery()['params']);
        self::assertSame($builder, $builder->setPrefix('app_')->where('o.tenantId', 9)
            ->join($subquery, 'u.id=o.userId', 'LEFT')->orderByDesc('o.id')->get('orders o', 5));
        self::assertSame([true, 9], $builder->getLastBindParams());
        self::assertStringContainsString('FROM app_orders o LEFT JOIN', $builder->getLastPrepareQuery());
        self::assertStringContainsString('ORDER BY o.id DESC', $builder->getLastPrepareQuery());
        $completedQuery = $builder->getLastPrepareQuery();
        $builder->where('new_condition', 1);
        self::assertSame($completedQuery, $builder->getLastPrepareQuery());
        self::assertSame([true, 9], $builder->getLastBindParams());
        self::assertSame($builder, $builder->reset());
        self::assertSame($builder, $builder->get('orders'));
        self::assertSame('SELECT  * FROM `app_orders`', $builder->getLastPrepareQuery());
        self::assertSame([], $builder->getLastBindParams());
    }

    public function testCompileErrorDoesNotPublishACompletedQueryAndResetRecovers(): void
    {
        $builder = (new QueryBuilder())->where('id', 1)->get('users');
        $previousSql = $builder->getLastPrepareQuery();
        try {
            $builder->where('tenantId', 7)->where('id', [1, 2])->get('users');
            self::fail('Invalid scalar comparison must fail compilation');
        } catch (\EasySwoole\Mysqli\Exception\Exception $error) {
            self::assertSame($previousSql, $builder->getLastPrepareQuery());
            self::assertSame([1], $builder->getLastBindParams());
        }
        $builder->reset()->get('users');
        self::assertSame('SELECT  * FROM `users`', $builder->getLastPrepareQuery());
        self::assertSame([], $builder->getLastBindParams());
    }

    private static function publicApi(): array
    {
        $class = new \ReflectionClass(QueryBuilder::class);
        $methods = [];
        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_contains($method->getDocComment() ?: '', '@internal')) { continue; }
            $parameters = [];
            foreach ($method->getParameters() as $parameter) {
                $text = self::type($parameter->getType());
                $text = $text === '' ? '' : $text . ' ';
                $text .= ($parameter->isPassedByReference() ? '&' : '')
                    . ($parameter->isVariadic() ? '...' : '') . '$' . $parameter->getName();
                if ($parameter->isDefaultValueAvailable()) {
                    $value = $parameter->getDefaultValue();
                    $default = $value instanceof \UnitEnum ? '\\' . $value::class . '::' . $value->name
                        : ($value === [] ? '[]' : var_export($value, true));
                    $text .= ' = ' . $default;
                }
                $parameters[] = $text;
            }
            $signature = ($method->isFinal() ? 'final ' : '') . 'public '
                . ($method->isStatic() ? 'static ' : '') . 'function '
                . ($method->returnsReference() ? '&' : '') . $method->getName()
                . '(' . implode(', ', $parameters) . ')';
            $return = self::type($method->getReturnType());
            $signature .= $return === '' ? '' : ': ' . $return;
            $methods[$method->getName()] = $signature;
        }
        ksort($methods);
        $constants = [];
        foreach ($class->getReflectionConstants(\ReflectionClassConstant::IS_PUBLIC) as $constant) {
            $constants[$constant->getName()] = $constant->getValue();
        }
        ksort($constants);
        return ['final' => $class->isFinal(), 'abstract' => $class->isAbstract(), 'methods' => $methods, 'constants' => $constants];
    }

    private static function type(?\ReflectionType $type): string
    {
        if ($type === null) { return ''; }
        if ($type instanceof \ReflectionNamedType) {
            return ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], true) ? '?' : '') . $type->getName();
        }
        $types = array_map([self::class, 'type'], $type->getTypes());
        sort($types);
        return implode($type instanceof \ReflectionUnionType ? '|' : '&', $types);
    }
}
