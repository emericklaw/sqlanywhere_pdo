<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\Tests\TestCase;
use PDO;

/**
 * Regression test for a real production deadlock: sasql_stmt_execute()
 * (the only code path this wrapper uses for parameterized queries) never
 * commits, unlike the non-prepared sasql_query() path. Every prepared
 * UPDATE/INSERT/DELETE left the connection with an uncommitted
 * transaction, and the next sasql_prepare() on that connection hung
 * inside the closed-source client library's sqlany_prepare() call —
 * reproduced live via a Horizon worker stalling on exactly this
 * prepare-UPDATE-then-prepare-SELECT sequence. Confirms the fix
 * (auto-commit emulated in SqlAnywherePdoStatement::execute() when not
 * inside an explicit transaction) actually prevents the hang.
 */
final class AutocommitTest extends TestCase
{
    private const TABLE = 'sqlanywhere_pdo_autocommit_test';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_prepared_update_then_prepared_select_does_not_deadlock(): void
    {
        $pdo = $this->requireLiveConnection();

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT, started TIMESTAMP NULL)');
        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 1');
        $pdo->exec('INSERT INTO ' . self::TABLE . ' (id) VALUES (1)');

        // Exactly the sequence that deadlocked in production: prepare+
        // execute a SELECT, close it, prepare+execute an UPDATE (no
        // explicit transaction), then prepare+execute ANOTHER statement
        // on the same connection. Without the fix, that last prepare()
        // call hangs forever inside the closed-source client library.
        $select1 = $pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE id = ?');
        $select1->execute([1]);
        self::assertSame(1, (int) $select1->fetchColumn());
        $select1->closeCursor();

        $update = $pdo->prepare('UPDATE ' . self::TABLE . ' SET started = CURRENT TIMESTAMP WHERE id = ?');
        $update->execute([1]);

        $select2 = $pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE id = ?');
        $select2->execute([1]);

        self::assertSame(1, (int) $select2->fetchColumn());

        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 1');
    }

    public function test_autocommit_is_not_applied_inside_an_explicit_transaction(): void
    {
        $pdo = $this->requireLiveConnection();

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT, started TIMESTAMP NULL)');
        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 2');
        $pdo->exec('INSERT INTO ' . self::TABLE . ' (id) VALUES (2)');

        $pdo->beginTransaction();

        $update = $pdo->prepare('UPDATE ' . self::TABLE . ' SET started = CURRENT TIMESTAMP WHERE id = ?');
        $update->execute([2]);

        self::assertTrue($pdo->inTransaction());

        $pdo->rollBack();

        $select = $pdo->prepare('SELECT started FROM ' . self::TABLE . ' WHERE id = ?');
        $select->execute([2]);

        self::assertNull($select->fetchColumn());

        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 2');
    }

    /**
     * Regression test for a second, self-inflicted deadlock found while
     * fixing the first one: sasql_stmt_field_count() reports 0 for a
     * genuine SELECT immediately after execute() (same "not available
     * until a row is fetched" quirk already seen with column metadata).
     * An earlier version of this fix used that count to decide whether to
     * auto-commit, which fired an unwanted commit on a still-open SELECT
     * cursor — and that alone was enough to hang the NEXT prepare() on
     * the same connection. $isWriteStatement is derived statically from
     * the SQL text instead, specifically to avoid this.
     */
    public function test_prepared_select_then_prepared_select_without_fetching_first_does_not_deadlock(): void
    {
        $pdo = $this->requireLiveConnection();

        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (id INT, started TIMESTAMP NULL)');
        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 3');
        $pdo->exec('INSERT INTO ' . self::TABLE . ' (id) VALUES (3)');

        $select1 = $pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE id = ?');
        $select1->execute([3]);
        // Deliberately do NOT fetch from $select1 before preparing again —
        // this is what exposed the bad field_count()-based guard.

        $select2 = $pdo->prepare('SELECT id FROM ' . self::TABLE . ' WHERE id = ?');
        $select2->execute([3]);

        self::assertSame(3, (int) $select2->fetchColumn());

        $pdo->exec('DELETE FROM ' . self::TABLE . ' WHERE id = 3');
    }
}
