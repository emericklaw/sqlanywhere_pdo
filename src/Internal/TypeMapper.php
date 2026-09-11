<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use PDO;

/**
 * Maps PDO::PARAM_* constants to the single-character type codes expected
 * by sasql_stmt_bind_param() (sqlanywhere.c:4016): 'd' double, 'i' integer,
 * 'b' blob, 's' string.
 */
final class TypeMapper
{
    /**
     * PDO has no PARAM_FLOAT constant, so a bound PHP float — whether
     * passed explicitly via bindValue()/bindParam() with the default
     * $type (PARAM_STR), or auto-inferred by inferParamType() below —
     * normally resolves here as 's' (string). sasql_stmt_bind_param()
     * then binds it as A_STRING and the C extension truncates the
     * string to whatever buffer_size the server described for that
     * parameter's actual (numeric) column type before sending it —
     * corrupting the decimal value (confirmed against a live insert:
     * a float column value got silently truncated mid-digit and the
     * server then failed with "Cannot convert '<truncated value>' to
     * double"). $value is passed through so a genuine PHP float can be
     * bound as 'd' (double) instead, sidestepping the truncation
     * entirely; a caller who explicitly needs a float sent as a literal
     * string should cast it to string before binding.
     */
    public static function toSasqlTypeChar(int $pdoParamType, mixed $value = null): string
    {
        if ($pdoParamType === PDO::PARAM_STR && is_float($value)) {
            return 'd';
        }

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

    /**
     * Renders a bound value as a literal for emulated prepares (see
     * SqlAnywherePdo::prepare()), where the value is spliced straight into
     * the SQL text instead of sent as a host variable via
     * sasql_stmt_bind_param(). $escapeString must be sasql_real_escape_string()
     * (or equivalent) bound to the live connection — never PHP string
     * escaping alone, which doesn't know SQL Anywhere's quoting rules.
     */
    public static function toLiteral(int $pdoParamType, mixed $value, \Closure $escapeString): string
    {
        if ($value === null || $pdoParamType === PDO::PARAM_NULL) {
            return 'NULL';
        }

        if ($pdoParamType === PDO::PARAM_LOB) {
            throw new SqlAnywherePdoException(
                'SQLSTATE[HY000]: PDO::PARAM_LOB values cannot be bound with emulated prepares. '
                . 'Set PDO::ATTR_EMULATE_PREPARES to false on the connection or in prepare() options for this statement.',
            );
        }

        if ($pdoParamType === PDO::PARAM_INT || $pdoParamType === PDO::PARAM_BOOL) {
            return (string) (int) $value;
        }

        // Same truncation hazard toSasqlTypeChar() guards against above:
        // a genuine PHP float must render as a plain decimal literal, not
        // via PHP's default float-to-string (which can emit scientific
        // notation SQL Anywhere won't parse as a numeric literal).
        if ($pdoParamType === PDO::PARAM_STR && is_float($value)) {
            return rtrim(rtrim(sprintf('%.15F', $value), '0'), '.');
        }

        return "'" . $escapeString((string) $value) . "'";
    }
}
