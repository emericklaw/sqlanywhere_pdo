<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\Tests\TestCase;
use PDO;

/**
 * Requires a BLOB/CLOB-capable scratch table on the test server; adjust
 * the DDL/table name to whatever the live SASQL_TEST_DSN database provides.
 */
final class LobStreamingTest extends TestCase
{
    public function test_bind_and_send_long_data_round_trip(): void
    {
        $pdo = $this->requireLiveConnection();

        $pdo->exec('CREATE TABLE IF NOT EXISTS sqlanywhere_pdo_lob_test (id INT, payload LONG BINARY)');

        $stmt = $pdo->prepare('INSERT INTO sqlanywhere_pdo_lob_test (id, payload) VALUES (?, ?)');
        $stmt->bindValue(1, 1, PDO::PARAM_INT);
        $stmt->bindValue(2, str_repeat('x', 200000), PDO::PARAM_LOB);
        $stmt->execute();

        $read = $pdo->prepare('SELECT payload FROM sqlanywhere_pdo_lob_test WHERE id = ?');
        $read->execute([1]);

        self::assertSame(200000, strlen((string) $read->fetchColumn()));

        $pdo->exec('DELETE FROM sqlanywhere_pdo_lob_test WHERE id = 1');
    }
}
