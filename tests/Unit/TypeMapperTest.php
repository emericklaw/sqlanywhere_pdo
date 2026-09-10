<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo\Tests\Unit;

use EmerickLaw\SqlAnywherePdo\Internal\TypeMapper;
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
}
