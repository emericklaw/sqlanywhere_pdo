<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdo;
use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use EmerickLaw\SqlAnywherePdo\Tests\TestCase;
use PDO;

final class ConnectionTest extends TestCase
{
    public function test_connect_and_close(): void
    {
        $pdo = $this->requireLiveConnection();

        self::assertSame('sqlanywhere', $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    public function test_bad_dsn_throws_on_connect(): void
    {
        if (!extension_loaded('sqlanywhere')) {
            self::markTestSkipped('ext-sqlanywhere is not loaded.');
        }

        $this->expectException(SqlAnywherePdoException::class);

        new SqlAnywherePdo('UID=nope;PWD=nope;SERVER=does-not-exist;DBN=nope');
    }
}
