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

    public function test_substitute_splices_literals_in_placeholder_order(): void
    {
        $sql = PlaceholderTranslator::substitute(
            'SELECT * FROM t WHERE a = ? AND b = ?',
            ["'x'", '42'],
        );

        self::assertSame("SELECT * FROM t WHERE a = 'x' AND b = 42", $sql);
    }

    public function test_substitute_handles_named_placeholders_in_source_order(): void
    {
        $sql = PlaceholderTranslator::substitute(
            'SELECT * FROM t WHERE a = :foo AND b = :bar',
            ["'x'", '42'],
        );

        self::assertSame("SELECT * FROM t WHERE a = 'x' AND b = 42", $sql);
    }

    public function test_substitute_ignores_placeholder_look_alikes_inside_literals(): void
    {
        $sql = PlaceholderTranslator::substitute(
            "SELECT '?' , ':x' FROM t WHERE a = ?",
            ['99'],
        );

        self::assertSame("SELECT '?' , ':x' FROM t WHERE a = 99", $sql);
    }

    public function test_substitute_throws_when_a_placeholder_has_no_matching_literal(): void
    {
        $this->expectException(SqlAnywherePdoException::class);

        PlaceholderTranslator::substitute('SELECT * FROM t WHERE a = ? AND b = ?', ["'x'"]);
    }

    /**
     * Regression test for the "Not enough values for host variables" bug:
     * SQL Anywhere's own prepare-time parameter count doesn't recognize a
     * '?'/':name' placeholder nested inside a subquery that's itself an
     * argument of a CALL procedure(...) statement as a bindable host
     * variable, even though every such placeholder is textually present
     * and bound. Emulated prepares (the connection default — see
     * SqlAnywherePdo::prepare()) avoid this entirely by never sending a
     * host variable to SQL Anywhere in the first place: every bound value
     * is spliced into the SQL text as a literal before it's sent.
     */
    public function test_substitute_handles_placeholder_nested_in_call_argument_subquery(): void
    {
        $sql = PlaceholderTranslator::substitute(
            "call setquestanswer(?, ?, null, (select site.sitepin from site where site.siteid=?), ?)",
            ["'S'", "'STM'", '1', "'x'"],
        );

        self::assertSame(
            "call setquestanswer('S', 'STM', null, (select site.sitepin from site where site.siteid=1), 'x')",
            $sql,
        );
    }
}
