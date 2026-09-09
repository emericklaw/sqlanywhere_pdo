<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

use PDO;

/**
 * Maps PDO::PARAM_* constants to the single-character type codes expected
 * by sasql_stmt_bind_param() (sqlanywhere.c:4016): 'd' double, 'i' integer,
 * 'b' blob, 's' string.
 */
final class TypeMapper
{
    public static function toSasqlTypeChar(int $pdoParamType): string
    {
        return match ($pdoParamType) {
            PDO::PARAM_INT => 'i',
            PDO::PARAM_BOOL => 'i',
            PDO::PARAM_LOB => 'b',
            PDO::PARAM_NULL, PDO::PARAM_STR => 's',
            default => 's',
        };
    }

    public static function isLob(int $pdoParamType): bool
    {
        return $pdoParamType === PDO::PARAM_LOB;
    }

    /**
     * Infer a PDO::PARAM_* type from a native PHP value when the caller
     * didn't specify one explicitly (bindValue()/execute($params) array form).
     */
    public static function inferParamType(mixed $value): int
    {
        return match (true) {
            $value === null => PDO::PARAM_NULL,
            is_bool($value) => PDO::PARAM_BOOL,
            is_int($value) => PDO::PARAM_INT,
            default => PDO::PARAM_STR,
        };
    }
}
