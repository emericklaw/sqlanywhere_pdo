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

    /**
     * Regression test for a claimed SQL Anywhere quirk: column names via
     * sasql_stmt_result_metadata()/sasql_fetch_field() are reportedly not
     * available until at least one row has been fetched, specifically for
     * TOP-limited statements (the shape SqlAnywhereGrammar compiles
     * Laravel's limit()/take() into). Confirms SqlAnywherePdoStatement's
     * "prefetch a row before resolving column names" workaround actually
     * produces correct names for this exact statement shape.
     */
    public function test_fetch_assoc_column_names_on_top_limited_statement(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT TOP 1 1 AS one, 2 AS two');
        $stmt->execute();

        self::assertSame(['one' => 1, 'two' => 2], $stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function test_fetch_all_on_top_limited_multi_row_statement_returns_correct_column_names(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT TOP 2 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3');
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows);
        self::assertSame(['n' => 1], $rows[0]);
        self::assertSame(['n' => 2], $rows[1]);
    }
}
