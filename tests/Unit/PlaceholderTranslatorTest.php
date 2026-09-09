<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Unit;

use EmerickLaw\SqlAnywherePdo\Internal\PlaceholderTranslator;
use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use PHPUnit\Framework\TestCase;

final class PlaceholderTranslatorTest extends TestCase
{
    public function test_anonymous_placeholders_are_passed_through(): void
    {
        $result = PlaceholderTranslator::translate('SELECT * FROM t WHERE a = ? AND b = ?');

        self::assertSame('SELECT * FROM t WHERE a = ? AND b = ?', $result->sql);
        self::assertSame([0, 1], $result->paramOrder);
    }

    public function test_named_placeholders_are_rewritten_positionally(): void
    {
        $result = PlaceholderTranslator::translate('SELECT * FROM t WHERE a = :foo AND b = :bar');

        self::assertSame('SELECT * FROM t WHERE a = ? AND b = ?', $result->sql);
        self::assertSame(['foo', 'bar'], $result->paramOrder);
    }

    public function test_named_placeholder_names_are_lowercased(): void
    {
        $result = PlaceholderTranslator::translate('SELECT * FROM t WHERE a = :FooBar');

        self::assertSame(['foobar'], $result->paramOrder);
    }

    public function test_placeholder_inside_single_quoted_string_is_ignored(): void
    {
        $result = PlaceholderTranslator::translate("SELECT '?' , ':x' FROM t WHERE a = ?");

        self::assertSame("SELECT '?' , ':x' FROM t WHERE a = ?", $result->sql);
        self::assertSame([0], $result->paramOrder);
    }

    public function test_escaped_quote_inside_string_literal_does_not_terminate_it(): void
    {
        $result = PlaceholderTranslator::translate("SELECT 'it''s :x ?' FROM t WHERE a = ?");

        self::assertSame("SELECT 'it''s :x ?' FROM t WHERE a = ?", $result->sql);
        self::assertSame([0], $result->paramOrder);
    }

    public function test_placeholder_inside_double_quoted_identifier_is_ignored(): void
    {
        $result = PlaceholderTranslator::translate('SELECT "col?name" FROM t WHERE a = ?');

        self::assertSame('SELECT "col?name" FROM t WHERE a = ?', $result->sql);
        self::assertSame([0], $result->paramOrder);
    }

    public function test_placeholder_inside_bracketed_identifier_is_ignored(): void
    {
        $result = PlaceholderTranslator::translate('SELECT [col:name] FROM t WHERE a = :x');

        self::assertSame('SELECT [col:name] FROM t WHERE a = ?', $result->sql);
        self::assertSame(['x'], $result->paramOrder);
    }

    public function test_placeholder_inside_line_comment_is_ignored(): void
    {
        $sql = "SELECT a FROM t -- ignore :x here\nWHERE a = ?";
        $result = PlaceholderTranslator::translate($sql);

        self::assertSame($sql, $result->sql);
        self::assertSame([0], $result->paramOrder);
    }

    public function test_placeholder_inside_block_comment_is_ignored(): void
    {
        $sql = 'SELECT a FROM t /* ignore :x and ? here */ WHERE a = ?';
        $result = PlaceholderTranslator::translate($sql);

        self::assertSame($sql, $result->sql);
        self::assertSame([0], $result->paramOrder);
    }

    public function test_mixed_named_and_anonymous_placeholders_throws(): void
    {
        $this->expectException(SqlAnywherePdoException::class);

        PlaceholderTranslator::translate('SELECT * FROM t WHERE a = :foo AND b = ?');
    }

    public function test_sql_with_no_placeholders_is_unchanged(): void
    {
        $result = PlaceholderTranslator::translate('SELECT * FROM t');

        self::assertSame('SELECT * FROM t', $result->sql);
        self::assertSame([], $result->paramOrder);
    }
}
