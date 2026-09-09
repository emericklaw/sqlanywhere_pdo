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
            static fn (array $pair): string => $pair[0] . '=' . $pair[1],
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
     * @return list<array{0: string, 1: string}>
     */
    private static function parsePairs(string $dsn): array
    {
        $pairs = [];

        foreach (explode(';', $dsn) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $equalsPos = strpos($segment, '=');
            if ($equalsPos === false) {
                continue;
            }

            $key = trim(substr($segment, 0, $equalsPos));
            $value = trim(substr($segment, $equalsPos + 1));
            $pairs[] = [$key, $value];
        }

        return $pairs;
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
