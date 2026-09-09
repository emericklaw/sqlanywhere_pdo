<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Integration;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use EmerickLaw\SqlAnywherePdo\Tests\TestCase;

final class TransactionTest extends TestCase
{
    public function test_begin_commit_rollback_and_in_transaction_flag(): void
    {
        $pdo = $this->requireLiveConnection();

        self::assertFalse($pdo->inTransaction());

        $pdo->beginTransaction();
        self::assertTrue($pdo->inTransaction());

        $pdo->rollBack();
        self::assertFalse($pdo->inTransaction());

        $pdo->beginTransaction();
        $pdo->commit();
        self::assertFalse($pdo->inTransaction());
    }

    public function test_nested_begin_transaction_throws(): void
    {
        $pdo = $this->requireLiveConnection();

        $pdo->beginTransaction();

        try {
            $this->expectException(SqlAnywherePdoException::class);
            $pdo->beginTransaction();
        } finally {
            $pdo->rollBack();
        }
    }

    public function test_commit_without_active_transaction_throws(): void
    {
        $pdo = $this->requireLiveConnection();

        $this->expectException(SqlAnywherePdoException::class);

        $pdo->commit();
    }
}
