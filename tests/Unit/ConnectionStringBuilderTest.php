<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Unit;

use EmerickLaw\SqlAnywherePdo\Internal\ConnectionStringBuilder;
use PHPUnit\Framework\TestCase;

final class ConnectionStringBuilderTest extends TestCase
{
    public function test_flat_dsn_passes_through_unchanged_when_credentials_already_present(): void
    {
        $result = ConnectionStringBuilder::build('UID=test;PWD=test;SERVER=x;DBN=test', null, null);

        self::assertSame('UID=test;PWD=test;SERVER=x;DBN=test', $result);
    }

    public function test_sqlanywhere_prefix_is_stripped(): void
    {
        $result = ConnectionStringBuilder::build('sqlanywhere:SERVER=x;DBN=test', null, null);

        self::assertSame('SERVER=x;DBN=test', $result);
    }

    public function test_username_and_password_are_merged_in_when_missing(): void
    {
        $result = ConnectionStringBuilder::build('SERVER=x;DBN=test', 'alice', 'secret');

        self::assertSame('SERVER=x;DBN=test;UID=alice;PWD=secret', $result);
    }

    public function test_dsn_supplied_uid_takes_precedence_over_constructor_username(): void
    {
        $result = ConnectionStringBuilder::build('UID=fromdsn;SERVER=x', 'fromctor', null);

        self::assertSame('UID=fromdsn;SERVER=x', $result);
    }

    public function test_uid_key_match_is_case_insensitive(): void
    {
        $result = ConnectionStringBuilder::build('uid=fromdsn;SERVER=x', 'fromctor', null);

        self::assertSame('uid=fromdsn;SERVER=x', $result);
    }

    public function test_brace_quoted_value_containing_semicolon_is_preserved(): void
    {
        $result = ConnectionStringBuilder::build('SERVER=x;PWD={p@ss;word};DBN=test', null, null);

        self::assertSame('SERVER=x;PWD={p@ss;word};DBN=test', $result);
    }

    public function test_escaped_closing_brace_inside_quoted_value_is_preserved(): void
    {
        $result = ConnectionStringBuilder::build('SERVER=x;PWD={p}}ss}', null, null);

        self::assertSame('SERVER=x;PWD={p}}ss}', $result);
    }

    public function test_password_containing_semicolon_from_constructor_is_brace_quoted(): void
    {
        $result = ConnectionStringBuilder::build('SERVER=x', null, 'p;ss');

        self::assertSame('SERVER=x;PWD={p;ss}', $result);
    }

    /**
     * Regression test: LINKS=TCPIP(host=h;port=2638) is the documented
     * working DSN form for network host/port (SqlAnywhereConnector's own
     * example). The ';' and '=' inside the parens must not be mistaken
     * for a segment/key boundary on parse, and must NOT come back
     * brace-quoted on output — braced was never confirmed against a live
     * server, and a prior version of this fix wrongly quoted the
     * paren-truncated fragment, producing "LINKS={TCPIP(host=h}" plus a
     * bogus top-level "port" pair, which SQL Anywhere rejected with
     * "Trying to add unknown port ''".
     */
    public function test_paren_grouped_links_value_with_nested_semicolon_and_equals_round_trips_unquoted(): void
    {
        $dsn = 'SERVER=x;DBN=test;LINKS=TCPIP(host=1.2.3.4;port=2638)';

        $result = ConnectionStringBuilder::build($dsn, null, null);

        self::assertSame($dsn, $result);
    }
}
