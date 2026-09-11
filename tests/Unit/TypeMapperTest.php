<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Unit;

use EmerickLaw\SqlAnywherePdo\Internal\TypeMapper;
use EmerickLaw\SqlAnywherePdo\SqlAnywherePdoException;
use PDO;
use PHPUnit\Framework\TestCase;

final class TypeMapperTest extends TestCase
{
    public function test_type_char_mapping(): void
    {
        self::assertSame('i', TypeMapper::toSasqlTypeChar(PDO::PARAM_INT));
        self::assertSame('i', TypeMapper::toSasqlTypeChar(PDO::PARAM_BOOL));
        self::assertSame('b', TypeMapper::toSasqlTypeChar(PDO::PARAM_LOB));
        self::assertSame('s', TypeMapper::toSasqlTypeChar(PDO::PARAM_STR));
        self::assertSame('s', TypeMapper::toSasqlTypeChar(PDO::PARAM_NULL));
    }

    /**
     * Regression test: PDO has no PARAM_FLOAT, so a bound PHP float
     * normally resolves to PARAM_STR/'s' — sent as a string, then
     * truncated by the C extension to the buffer size the server
     * described for the target (numeric) column, corrupting the
     * decimal value before it reaches the server. A genuine float value
     * must resolve to 'd' (double) instead so it's bound natively.
     */
    public function test_float_value_resolves_to_double_type_char_even_with_param_str(): void
    {
        self::assertSame('d', TypeMapper::toSasqlTypeChar(PDO::PARAM_STR, 0.0039529800415039));
        self::assertSame('s', TypeMapper::toSasqlTypeChar(PDO::PARAM_STR, 'not a float'));
        self::assertSame('s', TypeMapper::toSasqlTypeChar(PDO::PARAM_STR, null));
        self::assertSame('i', TypeMapper::toSasqlTypeChar(PDO::PARAM_INT, 3.14), 'an explicit non-STR type must not be overridden by a float value');
    }

    public function test_is_lob(): void
    {
        self::assertTrue(TypeMapper::isLob(PDO::PARAM_LOB));
        self::assertFalse(TypeMapper::isLob(PDO::PARAM_STR));
    }

    public function test_infer_param_type(): void
    {
        self::assertSame(PDO::PARAM_NULL, TypeMapper::inferParamType(null));
        self::assertSame(PDO::PARAM_BOOL, TypeMapper::inferParamType(true));
        self::assertSame(PDO::PARAM_INT, TypeMapper::inferParamType(42));
        self::assertSame(PDO::PARAM_STR, TypeMapper::inferParamType('hello'));
        self::assertSame(PDO::PARAM_STR, TypeMapper::inferParamType(3.14));
    }

    public function test_to_literal_renders_null(): void
    {
        self::assertSame('NULL', TypeMapper::toLiteral(PDO::PARAM_NULL, 'ignored', self::noopEscaper()));
        self::assertSame('NULL', TypeMapper::toLiteral(PDO::PARAM_STR, null, self::noopEscaper()));
    }

    public function test_to_literal_renders_int_and_bool_bare(): void
    {
        self::assertSame('42', TypeMapper::toLiteral(PDO::PARAM_INT, 42, self::noopEscaper()));
        self::assertSame('1', TypeMapper::toLiteral(PDO::PARAM_BOOL, true, self::noopEscaper()));
        self::assertSame('0', TypeMapper::toLiteral(PDO::PARAM_BOOL, false, self::noopEscaper()));
    }

    public function test_to_literal_renders_float_as_plain_decimal(): void
    {
        self::assertSame('3.14', TypeMapper::toLiteral(PDO::PARAM_STR, 3.14, self::noopEscaper()));
        self::assertSame('5', TypeMapper::toLiteral(PDO::PARAM_STR, 5.0, self::noopEscaper()));
    }

    public function test_to_literal_quotes_and_escapes_strings_via_callback(): void
    {
        $literal = TypeMapper::toLiteral(
            PDO::PARAM_STR,
            "O'Brien",
            fn (string $s): string => str_replace("'", "''", $s),
        );

        self::assertSame("'O''Brien'", $literal);
    }

    public function test_to_literal_rejects_lob(): void
    {
        $this->expectException(SqlAnywherePdoException::class);

        TypeMapper::toLiteral(PDO::PARAM_LOB, 'blob data', self::noopEscaper());
    }

    private static function noopEscaper(): \Closure
    {
        return fn (string $s): string => $s;
    }
}
