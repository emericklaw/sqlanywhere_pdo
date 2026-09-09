<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use EmerickLaw\SqlAnywherePdo\Tests\TestCase;
use PDO;

final class ErrorHandlingTest extends TestCase
{
    public function test_errmode_exception_throws_on_bad_sql(): void
    {
        $pdo = $this->requireLiveConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->expectException(SqlAnywherePdoException::class);

        $pdo->query('SELECT this is not valid sql');
    }

    public function test_errmode_silent_returns_false_and_populates_error_info(): void
    {
        $pdo = $this->requireLiveConnection();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        $result = $pdo->query('SELECT this is not valid sql');

        self::assertFalse($result);
        self::assertNotSame('00000', $pdo->errorCode());
        self::assertCount(3, $pdo->errorInfo());
    }
}
