<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

/**
 * sasql_connect()/sasql_pconnect() take a single flat SQL Anywhere DSN
 * string like "UID=test;PWD=test;SERVER=x;DBN=test". This accepts either 
 * that format directly, or a PDO-style DSN prefixed with "sqlanywhere:",
 * and merges in the constructor's $username/$password as UID/PWD when not
 * already present in the DSN itself.
 */
final class ConnectionStringBuilder
{
    public static function build(string $dsn, ?string $username, ?string $password): string
    {
        $pairs = self::parsePairs(self::stripDriverPrefix($dsn));

        if ($username !== null && $username !== '' && !self::hasKey($pairs, 'UID')) {
            $pairs[] = ['UID', $username];
        }

        if ($password !== null && $password !== '' && !self::hasKey($pairs, 'PWD')) {
            $pairs[] = ['PWD', $password];
        }

        return implode(';', array_map(
            static fn (array $pair): string => $pair[0] . '=' . self::formatValue($pair[1]),
            $pairs,
        ));
    }

    private static function stripDriverPrefix(string $dsn): string
    {
        if (stripos($dsn, 'sqlanywhere:') === 0) {
            return substr($dsn, strlen('sqlanywhere:'));
        }

        return $dsn;
    }

    /**
     * SQL Anywhere connection-string values may be brace-quoted —
     * "PWD={p@ss;word}" — with "}}" as an escaped literal "}" inside the
     * braces (the same convention ODBC connection strings use), precisely
     * so a value can contain ';' or '=' without being misread as a
     * segment/key boundary. A plain explode(';')/explode('=', ..., 2)
     * has no notion of that quoting and silently mangles any such value
     * into extra bogus pairs instead of erroring — this walks the string
     * char-by-char so brace-quoted sections are skipped over intact.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function parsePairs(string $dsn): array
    {
        $pairs = [];
        $length = strlen($dsn);
        $i = 0;

        while ($i < $length) {
            while ($i < $length && ($dsn[$i] === ';' || ctype_space($dsn[$i]))) {
                $i++;
            }

            if ($i >= $length) {
                break;
            }

            $keyStart = $i;
            while ($i < $length && $dsn[$i] !== '=' && $dsn[$i] !== ';') {
                $i++;
            }

            if ($i >= $length || $dsn[$i] !== '=') {
                // Segment has no '=' before the next ';' (or end) — not a
                // valid key=value pair, skip past it.
                continue;
            }

            $key = trim(substr($dsn, $keyStart, $i - $keyStart));
            $i++; // skip '='

            while ($i < $length && ctype_space($dsn[$i])) {
                $i++;
            }

            if ($i < $length && $dsn[$i] === '{') {
                $i++;
                $value = '';
                while ($i < $length) {
                    if ($dsn[$i] === '}') {
                        if ($i + 1 < $length && $dsn[$i + 1] === '}') {
                            $value .= '}';
                            $i += 2;
                            continue;
                        }
                        $i++;
                        break;
                    }
                    $value .= $dsn[$i];
                    $i++;
                }
                // Discard anything between the closing brace and the next
                // ';' rather than misreading it as another key.
                while ($i < $length && $dsn[$i] !== ';') {
                    $i++;
                }
            } else {
                $valueStart = $i;
                while ($i < $length && $dsn[$i] !== ';') {
                    $i++;
                }
                $value = trim(substr($dsn, $valueStart, $i - $valueStart));
            }

            $pairs[] = [$key, $value];
        }

        return $pairs;
    }

    /**
     * Re-quotes a value with braces if it contains a character that would
     * otherwise be misread as a segment/key boundary on the next parse
     * (';' or '=') or that would break the brace-quoting convention itself
     * ('{' or '}').
     */
    private static function formatValue(string $value): string
    {
        if (!str_contains($value, ';') && !str_contains($value, '=')
            && !str_contains($value, '{') && !str_contains($value, '}')) {
            return $value;
        }

        return '{' . str_replace('}', '}}', $value) . '}';
    }

    /**
     * @param list<array{0: string, 1: string}> $pairs
     */
    private static function hasKey(array $pairs, string $key): bool
    {
        foreach ($pairs as $pair) {
            if (strcasecmp($pair[0], $key) === 0) {
                return true;
            }
        }

        return false;
    }
}
