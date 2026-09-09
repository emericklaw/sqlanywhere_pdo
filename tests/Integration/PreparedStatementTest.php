<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\Tests\TestCase;
use PDO;

final class PreparedStatementTest extends TestCase
{
    public function test_positional_placeholders(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT ? + ?');
        $stmt->execute([2, 3]);

        self::assertSame(5, (int) $stmt->fetchColumn());
    }

    public function test_named_placeholders(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT :a + :b');
        $stmt->execute(['a' => 2, 'b' => 3]);

        self::assertSame(5, (int) $stmt->fetchColumn());
    }

    public function test_fetch_modes(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT 1 AS one, 2 AS two');
        $stmt->execute();

        self::assertSame(['one' => 1, 'two' => 2], $stmt->fetch(PDO::FETCH_ASSOC));
    }
}
