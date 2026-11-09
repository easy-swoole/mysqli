<?php

namespace EasySwoole\Mysqli\QueryBuilder;

/** @internal 保留表前缀规则，并校验及渲染索引标识符。 */
final class TableReference
{
    public static function parse(mixed &$tableName, mixed $prefix): mixed
    {
        $source = trim($tableName);
        // 派生表和表值表达式包含 SQL，不作为普通表名添加前缀。
        if (str_starts_with($source, '(') || preg_match('/\A[^\s`(]+\s*\(/', $source) === 1) {
            return $tableName;
        }

        $identifier = '(?:`(?:``|[^`])*`|[^\s.`()]+)';
        $reference = '~\A(?<table>' . $identifier . '(?:\s*\.\s*' . $identifier . ')*)'
            . '(?<suffix>\s+[\s\S]*)?\z~';
        if (preg_match($reference, $source, $match) === 1) {
            preg_match_all('~' . $identifier . '~', $match['table'], $tokens);
            $parts = array_map(static function (string $part): string {
                return $part[0] === '`' ? str_replace('``', '`', substr($part, 1, -1)) : $part;
            }, $tokens[0]);
            $unqualified = count($parts) === 1;
            if ($unqualified) {
                $separator = strrpos($prefix, '.');
                $quoted = $separator === false ? self::identifier($prefix . $parts[0])
                    : self::column(substr($prefix, 0, $separator)) . '.'
                        . self::identifier(substr($prefix, $separator + 1) . $parts[0]);
            } else {
                $quoted = implode('.', array_map([self::class, 'identifier'], $parts));
            }
            $suffix = $match['suffix'] ?? '';
            if (strpos($match['table'], '`') !== false) {
                $tableName = $quoted . $suffix;
                return $tableName;
            }
            // 保留普通别名、可信尾部片段及未引用表名元数据的原有拼写。
            $tableName = ($unqualified ? $prefix . $match['table'] : $match['table']) . $suffix;
            return $suffix !== '' ? $tableName : $quoted;
        }

        // 对标识符语法之外的可信旧 SQL 片段（例如表提示）保持原有处理。
        if (strpos($tableName, '.') === false) {
            $tableName = $prefix . $tableName;
        }
        if (strpos($tableName, '`') !== false || strpos($tableName, ' ') !== false) {
            return $tableName;
        }
        return '`' . str_replace('.', '`.`', $tableName) . '`';
    }

    /** @return list<string> */
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

    /** @param array<string, list<string>> $hints */
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

    /** 引用单个字面标识符，不将其中的点解释为分隔符。 */
    public static function identifier(mixed $name): string
    {
        if (!is_string($name) || trim($name) === '' || str_contains($name, "\0")) {
            throw new \InvalidArgumentException('Identifiers must be non-empty strings without NUL bytes');
        }
        return '`' . str_replace('`', '``', $name) . '`';
    }

    /** 逐段引用字段名，接受已正确使用反引号引用的各段。 */
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
