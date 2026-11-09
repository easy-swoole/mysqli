<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Tests\Support\ApiContractSnapshot;
use PHPUnit\Framework\TestCase;

final class ApiContractTest extends TestCase
{
    public function testExistingSourceMethodsKeepTheirCallShapeAndDeclaredTypes(): void
    {
        $expected = json_decode(file_get_contents(__DIR__ . '/resources/api-contract.json'), true, 512, JSON_THROW_ON_ERROR);
        $current = ApiContractSnapshot::capture(dirname(__DIR__) . '/src');
        self::assertSame(1, $expected['format']);
        foreach ($expected['classes'] as $class => $contract) {
            self::assertArrayHasKey($class, $current['classes'], 'Removed source symbol ' . $class);
            $actual = $current['classes'][$class];
            foreach (['kind', 'final', 'abstract'] as $attribute) {
                self::assertSame($contract[$attribute], $actual[$attribute], $class . ': ' . $attribute);
            }
            foreach ($contract['methods'] as $method => $signature) {
                $label = $class . '::' . $method;
                self::assertArrayHasKey($method, $actual['methods'], 'Removed or hidden method ' . $label);
                $found = $actual['methods'][$method];
                foreach (['visibility', 'static', 'final', 'returnByReference'] as $attribute) {
                    self::assertSame($signature[$attribute], $found[$attribute], $label . ': ' . $attribute);
                }
                self::assertCount(count($signature['parameters']), $found['parameters'], $label . ': parameter count');
                foreach ($signature['parameters'] as $index => $parameter) {
                    $actualParameter = $found['parameters'][$index];
                    $parameterLabel = $label . ' $' . $parameter['name'];
                    self::assertTrue(ApiContractSnapshot::keepsExistingType($parameter['type'], $actualParameter['type']),
                        $parameterLabel . ': existing type was removed or narrowed');
                    unset($parameter['type'], $actualParameter['type']);
                    self::assertSame($parameter, $actualParameter, $parameterLabel . ': name/order/default/reference shape');
                }
                self::assertTrue(ApiContractSnapshot::keepsExistingType($signature['returnType'], $found['returnType']),
                    $label . ': existing return type was removed or narrowed');
            }
        }
        // 此历史兼容性契约允许新增内部类和方法。
    }

    public function testTypePolicyAllowsAuthorizedDeclarationsButRejectsExistingTypeNarrowing(): void
    {
        self::assertTrue(ApiContractSnapshot::keepsExistingType(null, ['array', 'bool']));
        self::assertTrue(ApiContractSnapshot::keepsExistingType(['string'], ['SomeEnum', 'string']));
        self::assertTrue(ApiContractSnapshot::keepsExistingType(['array'], ['mixed']));
        self::assertFalse(ApiContractSnapshot::keepsExistingType(['null', 'string'], ['string']));
        self::assertFalse(ApiContractSnapshot::keepsExistingType(['array', 'bool'], ['array']));
        self::assertFalse(ApiContractSnapshot::keepsExistingType(['string'], null));
    }
}
