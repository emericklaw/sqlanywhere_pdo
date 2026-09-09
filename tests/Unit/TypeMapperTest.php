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
