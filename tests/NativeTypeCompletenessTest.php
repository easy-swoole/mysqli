<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests;

use EasySwoole\Mysqli\Tests\Support\ApiContractSnapshot;
use PHPUnit\Framework\TestCase;

final class NativeTypeCompletenessTest extends TestCase
{
    public function testAllDeclaredSourcePropertiesAndMethodSlotsHaveNativeTypes(): void
    {
        $snapshot = ApiContractSnapshot::capture(dirname(__DIR__) . '/src');
        $missing = [];
        foreach (array_keys($snapshot['classes']) as $name) {
            $class = new \ReflectionClass($name);
            foreach ($class->getProperties() as $property) {
                if ($property->getDeclaringClass()->getName() !== $name) { continue; }
                // 枚举的 name/value 属性由 PHP 生成，并非源码中的属性声明。
                if ($class->isEnum() && in_array($property->getName(), ['name', 'value'], true)) { continue; }
                if (!$property->hasType()) { $missing[] = $name . '::$' . $property->getName(); }
            }
            foreach ($class->getMethods() as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || $method->getFileName() === false) { continue; }
                $label = $name . '::' . $method->getName();
                foreach ($method->getParameters() as $parameter) {
                    if (!$parameter->hasType()) { $missing[] = $label . '($' . $parameter->getName() . ')'; }
                }
                // PHP 不允许为构造函数和析构函数声明返回类型。
                if (!in_array(strtolower($method->getName()), ['__construct', '__destruct'], true)
                    && !$method->hasReturnType()) {
                    $missing[] = $label . '(): return';
                }
            }
        }
        sort($missing);
        self::assertSame([], $missing, "Missing native type declarations:\n" . implode("\n", $missing));
    }
}
