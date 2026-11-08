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
}
