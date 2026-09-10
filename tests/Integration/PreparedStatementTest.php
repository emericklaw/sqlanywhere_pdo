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

    /**
     * Regression test for a real Laravel app: a bindings array commonly
     * ends up with extra named keys that don't correspond to any
     * placeholder actually present in the SQL (e.g. built up
     * speculatively across several conditional branches, some of which
     * are unused for a given report). Real PDO drivers in Laravel's
     * typical (emulated-prepare) usage silently ignore these; this
     * wrapper should too, rather than throwing HY093.
     */
    public function test_execute_ignores_bindings_with_no_matching_placeholder(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT :a + :b');
        $stmt->execute(['a' => 2, 'b' => 3, 'unused' => 'whatever']);

        self::assertSame(5, (int) $stmt->fetchColumn());
    }

    /**
     * Regression test: a named placeholder reused more than once in the
     * SQL text (e.g. a report query referencing :dateFrom several times)
     * occupies one positional slot per occurrence — a single bindValue()
     * call by name must fill every occurrence, not just the first, or
     * execute() fails with "parameter N was not bound".
     */
    public function test_execute_binds_a_repeated_named_placeholder_to_every_occurrence(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT :a + :a + :a');
        $stmt->execute(['a' => 2]);

        self::assertSame(6, (int) $stmt->fetchColumn());
    }

    public function test_fetch_class_hydrates_the_requested_class(): void
    {
        $pdo = $this->requireLiveConnection();

        $stmt = $pdo->prepare('SELECT 1 AS one, 2 AS two');
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_CLASS, PreparedStatementTestRow::class);

        $row = $stmt->fetch();

        self::assertInstanceOf(PreparedStatementTestRow::class, $row);
        self::assertSame(1, $row->one);
        self::assertSame(2, $row->two);
    }
}

final class PreparedStatementTestRow
{
    public int $one;

    public int $two;
}
