<?php

declare(strict_types=1);

namespace EasySwoole\Mysqli\Tests\Support;

/** Reflection helpers shared by the API guard and its one-time baseline generator. */
final class ApiContractSnapshot
{
    public static function capture(string $source): array
    {
        $source = realpath($source);
        if ($source === false) { throw new \RuntimeException('API source directory does not exist'); }
        $classes = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') { continue; }
            $relative = substr($file->getPathname(), strlen($source) + 1, -4);
            $name = 'EasySwoole\\Mysqli\\' . str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
            if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
                throw new \RuntimeException('Cannot reflect source symbol ' . $name);
            }
            $class = new \ReflectionClass($name);
            $methods = [];
            foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC | \ReflectionMethod::IS_PROTECTED) as $method) {
                if ($method->getDeclaringClass()->getName() !== $name || $method->getFileName() === false) { continue; }
                $parameters = [];
                foreach ($method->getParameters() as $parameter) {
                    $parameters[] = ['name' => $parameter->getName(), 'byReference' => $parameter->isPassedByReference(),
                        'variadic' => $parameter->isVariadic(), 'optional' => $parameter->isOptional(),
                        'hasDefault' => $parameter->isDefaultValueAvailable(),
                        'default' => $parameter->isDefaultValueAvailable() ? self::defaultValue($parameter->getDefaultValue()) : null,
                        'type' => self::type($parameter->getType())];
                }
                $methods[$method->getName()] = ['visibility' => $method->isPublic() ? 'public' : 'protected',
                    'static' => $method->isStatic(), 'final' => $method->isFinal(), 'returnByReference' => $method->returnsReference(),
                    'parameters' => $parameters, 'returnType' => self::type($method->getReturnType())];
            }
            ksort($methods);
            $classes[$name] = ['kind' => $class->isEnum() ? 'enum' : ($class->isInterface() ? 'interface' : ($class->isTrait() ? 'trait' : 'class')),
                'final' => $class->isFinal(), 'abstract' => $class->isAbstract(), 'methods' => $methods];
        }
        ksort($classes);
        return ['format' => 1, 'classes' => $classes];
    }

    /** Union order and nullable spelling are presentation details, rather than API differences. */
    public static function type(?\ReflectionType $type): ?array
    {
        if ($type === null) { return null; }
        if ($type instanceof \ReflectionNamedType) {
            $types = [$type->getName()];
            if ($type->allowsNull() && !in_array($type->getName(), ['mixed', 'null'], true)) { $types[] = 'null'; }
        } elseif ($type instanceof \ReflectionUnionType) {
            $types = [];
            foreach ($type->getTypes() as $part) { array_push($types, ...self::type($part)); }
        } else {
            $parts = array_map(static fn(\ReflectionType $part): string => implode('|', self::type($part)), $type->getTypes());
            sort($parts);
            $types = [implode('&', $parts)];
        }
        $types = array_values(array_unique($types));
        sort($types);
        return $types;
    }

    public static function defaultValue(mixed $value): array
    {
        if ($value instanceof \UnitEnum) { return ['enum' => $value::class, 'case' => $value->name]; }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $key => $item) { $items[] = ['key' => $key, 'value' => self::defaultValue($item)]; }
            return ['type' => 'array', 'items' => $items];
        }
        if ($value === null || is_scalar($value)) { return ['type' => get_debug_type($value), 'value' => $value]; }
        throw new \RuntimeException('Unsupported API default value ' . get_debug_type($value));
    }

    /** New declarations are allowed only at previously untyped positions; existing domains cannot shrink. */
    public static function keepsExistingType(?array $before, ?array $after): bool
    {
        if ($before === null) { return true; }
        if ($after === null) { return false; }
        if (in_array('mixed', $after, true)) { return true; }
        return array_diff($before, $after) === [];
    }
}
