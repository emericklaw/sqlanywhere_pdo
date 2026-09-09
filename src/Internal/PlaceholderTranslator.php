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
 */
final class PlaceholderTranslator
{
    public static function translate(string $sql): TranslatedQuery
    {
        $length = strlen($sql);
        $out = '';
        $paramOrder = [];
        $sawNamed = false;
        $sawAnonymous = false;
        $anonymousIndex = 0;

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
                $out .= '?';
                $paramOrder[] = $anonymousIndex++;
                $sawAnonymous = true;
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
                $out .= '?';
                $paramOrder[] = $name;
                $sawNamed = true;
                $i = $j;
                continue;
            }

            $out .= $char;
            $i++;
        }

        if ($sawNamed && $sawAnonymous) {
            throw SqlAnywherePdoException::fromErrorInfo([
                'HY093',
                null,
                'Mixed named and positional placeholders are not supported in a single statement.',
            ]);
        }

        return new TranslatedQuery($out, $paramOrder);
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
