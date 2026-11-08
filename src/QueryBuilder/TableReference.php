<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal Preserve table-prefix rules and validate/render index identifiers. */
final class TableReference
{
    public static function parse(&$tableName, $prefix)
    {
        if (strpos($tableName, '.') === false) {
            $tableName = $prefix . $tableName;
        }

        if (strpos($tableName, '`') !== false){
            return $tableName;
        }

        if (strpos($tableName, ' ') !== false){
            return $tableName;
        }

        return '`' . str_replace('.', '`.`', $tableName) . '`';
    }

    public static function normalizeIndexes(string|array $indexes): array
    {
        $indexes = is_array($indexes) ? array_values($indexes) : [$indexes];
        foreach ($indexes as $index) {
            if (!is_string($index) || trim($index) === '' || str_contains($index, "\0")) {
                throw new \InvalidArgumentException('Index names must be non-empty strings without NUL bytes');
            }
        }
        return $indexes;
    }

    public static function indexHints(array $hints): string
    {
        $sql = '';
        foreach ($hints as $type => $names) {
            if ($names === []) { continue; }
            $indexes = array_map(static fn(string $index): string => '`' . str_replace('`', '``', $index) . '`', $names);
            $sql .= ' ' . $type . ' INDEX (' . implode(', ', $indexes) . ')';
        }
        return $sql;
    }

    /** Quote a literal identifier, without interpreting dots as separators. */
    public static function identifier($name): string
    {
        if (!is_string($name) || trim($name) === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Identifiers must be non-empty strings without NUL bytes');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** Quote each component of a column reference, accepting correctly backtick-quoted components. */
    public static function column(string $name): string
    {
        $parts = [];
        $length = strlen($name);
        $position = 0;
        while ($position < $length) {
            $part = '';
            if ($name[$position] === '`') {
                $position++;
                $closed = false;
                while ($position < $length) {
                    if ($name[$position] !== '`') { $part .= $name[$position++]; continue; }
                    if ($position + 1 < $length && $name[$position + 1] === '`') {
                        $part .= '`'; $position += 2; continue;
                    }
                    $position++; $closed = true; break;
                }
                if (!$closed || ($position < $length && $name[$position] !== '.')) {
                    throw new \InvalidArgumentException('Malformed quoted column reference');
                }
            } else {
                while ($position < $length && $name[$position] !== '.') { $part .= $name[$position++]; }
            }
            $parts[] = self::identifier($part);
            if ($position < $length) {
                $position++;
                if ($position === $length) { throw new \InvalidArgumentException('Column references must not contain empty components'); }
            }
        }
        if ($parts === []) { throw new \InvalidArgumentException('Column reference must not be empty'); }
        return implode('.', $parts);
    }
}
