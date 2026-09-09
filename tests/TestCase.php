<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests;

use EmerickLaw\SqlAnywherePdo\SqlAnywherePdo;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base class for integration tests: skips gracefully when ext-sqlanywhere
 * isn't loaded, or when no SASQL_TEST_DSN env var points at a reachable
 * server. Neither was available while this package was first written.
 */
abstract class TestCase extends BaseTestCase
{
    protected function requireLiveConnection(): SqlAnywherePdo
    {
        if (!extension_loaded('sqlanywhere')) {
            self::markTestSkipped('ext-sqlanywhere is not loaded.');
        }

        $dsn = getenv('SASQL_TEST_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('SASQL_TEST_DSN is not set; no SQL Anywhere test server configured.');
        }

        try {
            return new SqlAnywherePdo($dsn);
        } catch (\Throwable $e) {
            self::markTestSkipped('Could not connect to SASQL_TEST_DSN: ' . $e->getMessage());
        }
    }
}
