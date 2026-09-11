<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;

/**
 * sasql_prepare()/sasql_stmt_bind_param() only understand anonymous '?'
 * placeholders bound by positional order (sqlanywhere.c:3914, :4016). PDO
 * callers may use either '?' or ':name' placeholders. This tokenizes the
 * SQL character-by-character (skipping string/identifier literals and
 * comments so a literal '?' or ':' inside them is never mistaken for a
 * placeholder) and rewrites every placeholder to '?', recording the order
 * so bindParam()/execute() can resolve back to the right positional slot.
 *
 * substitute() reuses the same tokenizer to splice literal values directly
 * into the SQL text instead (emulated prepares — see SqlAnywherePdo::prepare()),
 * so the two never drift out of sync on how a placeholder is recognized.
 */
final class PlaceholderTranslator
{
    public static function translate(string $sql): TranslatedQuery
    {
        [$out, $paramOrder, $sawNamed, $sawAnonymous] = self::walk(
            $sql,
            static fn (int $slot, ?string $name): string => '?',
        );

        if ($sawNamed && $sawAnonymous) {
            throw SqlAnywherePdoException::fromErrorInfo([
                'HY093',
                null,
                'Mixed named and positional placeholders are not supported in a single statement.',
            ]);
        }

        return new TranslatedQuery($out, $paramOrder);
    }

    /**
     * Replaces every placeholder in $sql (in the same left-to-right order
     * used by translate(), so slot N here always matches paramOrder[N])
     * with the literal text in $literals[N] — already fully quoted/escaped
     * by the caller. Used for emulated prepares, where bound values are
     * spliced into the SQL text itself rather than sent as host variables.
     *
     * @param list<string> $literals
     */
    public static function substitute(string $sql, array $literals): string
    {
        [$out] = self::walk($sql, static function (int $slot, ?string $name) use ($literals): string {
            if (!array_key_exists($slot, $literals)) {
                throw new SqlAnywherePdoException(sprintf('SQLSTATE[HY093]: Invalid parameter number: parameter %d was not bound', $slot + 1));
            }

            return $literals[$slot];
        });

        return $out;
    }

    /**
     * @return array{0: string, 1: list<string|int>, 2: bool, 3: bool} [sql, paramOrder, sawNamed, sawAnonymous]
     */
    private static function walk(string $sql, \Closure $onPlaceholder): array
    {
        $length = strlen($sql);
        $out = '';
        $paramOrder = [];
        $sawNamed = false;
        $sawAnonymous = false;
        $anonymousIndex = 0;
        $slot = 0;

        $i = 0;
        while ($i < $length) {
            $char = $sql[$i];

            // Single-quoted string literal, with '' as an escaped quote.
            if ($char === "'") {
                $out .= $char;
                $i++;
                while ($i < $length) {
                    $out .= $sql[$i];
                    if ($sql[$i] === "'") {
                        $i++;
                        if ($i < $length && $sql[$i] === "'") {
                            $out .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Double-quoted identifier, with "" as an escaped quote.
            if ($char === '"') {
                $out .= $char;
                $i++;
                while ($i < $length) {
                    $out .= $sql[$i];
                    if ($sql[$i] === '"') {
                        $i++;
                        if ($i < $length && $sql[$i] === '"') {
                            $out .= $sql[$i];
                            $i++;
                            continue;
                        }
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Bracketed identifier: [identifier].
            if ($char === '[') {
                $out .= $char;
                $i++;
                while ($i < $length && $sql[$i] !== ']') {
                    $out .= $sql[$i];
                    $i++;
                }
                if ($i < $length) {
                    $out .= $sql[$i];
                    $i++;
                }
                continue;
            }

            // Line comment: -- ... \n
            if ($char === '-' && $i + 1 < $length && $sql[$i + 1] === '-') {
                while ($i < $length && $sql[$i] !== "\n") {
                    $out .= $sql[$i];
                    $i++;
                }
                continue;
            }

            // Block comment: /* ... */
            if ($char === '/' && $i + 1 < $length && $sql[$i + 1] === '*') {
                $out .= '/*';
                $i += 2;
                while ($i + 1 < $length && !($sql[$i] === '*' && $sql[$i + 1] === '/')) {
                    $out .= $sql[$i];
                    $i++;
                }
                if ($i + 1 < $length) {
                    $out .= '*/';
                    $i += 2;
                }
                continue;
            }

            // Anonymous placeholder.
            if ($char === '?') {
                $paramOrder[] = $anonymousIndex++;
                $sawAnonymous = true;
                $out .= $onPlaceholder($slot++, null);
                $i++;
                continue;
            }

            // Named placeholder: :identifier (not a bare ':' used e.g. in '::').
            if ($char === ':' && $i + 1 < $length && self::isIdentifierStart($sql[$i + 1])) {
                $j = $i + 1;
                while ($j < $length && self::isIdentifierChar($sql[$j])) {
                    $j++;
                }
                $name = strtolower(substr($sql, $i + 1, $j - $i - 1));
                $paramOrder[] = $name;
                $sawNamed = true;
                $out .= $onPlaceholder($slot++, $name);
                $i = $j;
                continue;
            }

            $out .= $char;
            $i++;
        }

        return [$out, $paramOrder, $sawNamed, $sawAnonymous];
    }

    private static function isIdentifierStart(string $char): bool
    {
        return $char === '_' || ctype_alpha($char);
    }

    private static function isIdentifierChar(string $char): bool
    {
        return $char === '_' || ctype_alnum($char);
    }
}
