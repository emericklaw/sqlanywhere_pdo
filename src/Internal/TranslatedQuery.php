<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

/**
 * Result of translating PDO-style placeholders (':name' or '?') into the
 * positional '?' placeholders sasql_prepare() understands.
 */
final class TranslatedQuery
{
    /**
     * @param list<string|int> $paramOrder Ordered placeholder keys as
     *   encountered in the SQL: either a lowercase string (named
     *   placeholder, without the leading ':') or a 0-based int (anonymous '?').
     */
    public function __construct(
        public readonly string $sql,
        public readonly array $paramOrder,
    ) {
    }

    public function isNamed(): bool
    {
        return $this->paramOrder !== [] && is_string($this->paramOrder[0]);
    }
}
