<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo;

use PDOException;

/**
 * Thrown in place of the native PDOException by the sqlanywhere PDO wrapper.
 * Still an instance of \PDOException so existing `catch (\PDOException $e)`
 * code works unmodified.
 */
final class SqlAnywherePdoException extends PDOException
{
    /**
     * @param array{0: string, 1: int|string|null, 2: string|null} $errorInfo [sqlstate, driverCode, driverMessage]
     */
    public static function fromErrorInfo(array $errorInfo, ?\Throwable $previous = null): self
    {
        [$sqlstate, $driverCode, $driverMessage] = [
            $errorInfo[0] ?? 'HY000',
            $errorInfo[1] ?? null,
            $errorInfo[2] ?? 'Unknown error',
        ];

        $exception = new self((string) $driverMessage, 0, $previous);
        $exception->errorInfo = [$sqlstate, $driverCode, $driverMessage];
        $exception->code = $sqlstate;

        return $exception;
    }
}
