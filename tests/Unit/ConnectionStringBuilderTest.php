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
}
