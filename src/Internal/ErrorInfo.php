<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Internal;

/**
 * Normalizes sasql_sqlstate()/sasql_errorcode()/sasql_error() (or their
 * sasql_stmt_* equivalents) into PDO's 3-element errorInfo shape.
 */
final class ErrorInfo
{
    public function __construct(
        public readonly string $sqlstate,
        public readonly int|string|null $driverCode,
        public readonly ?string $driverMessage,
    ) {
    }

    public static function none(): self
    {
        return new self('00000', null, null);
    }

    public function hasError(): bool
    {
        return $this->sqlstate !== '00000';
    }

    /**
     * @return array{0: string, 1: int|string|null, 2: string|null}
     */
    public function toArray(): array
    {
        return [$this->sqlstate, $this->driverCode, $this->driverMessage];
    }
}
