<?php

namespace Tests\Unit;

use App\Support\JsonCanonicalizer;
use PHPUnit\Framework\TestCase;

class JsonCanonicalizerTest extends TestCase
{
    public function test_object_keys_are_sorted(): void
    {
        $input = [
            'quantity' => 2,
            'productId' => 'abc',
        ];

        $this->assertSame(
            '{"productId":"abc","quantity":2}',
            JsonCanonicalizer::encode($input)
        );
    }

    public function test_nested_objects_are_canonicalized(): void
    {
        $input = [
            'items' => [
                [
                    'quantity' => 2,
                    'productId' => 'abc',
                ],
            ],
        ];

        $this->assertSame(
            '{"items":[{"productId":"abc","quantity":2}]}',
            JsonCanonicalizer::encode($input)
        );
    }

    public function test_array_order_is_preserved(): void
    {
        $input = [
            'items' => [
                ['productId' => 'A'],
                ['productId' => 'B'],
            ],
        ];

        $this->assertSame(
            '{"items":[{"productId":"A"},{"productId":"B"}]}',
            JsonCanonicalizer::encode($input)
        );
    }

    public function test_hash_is_sha256_of_canonical_json(): void
    {
        $input = [
            'quantity' => 2,
            'productId' => 'abc',
        ];

        $expected = hash(
            'sha256',
            '{"productId":"abc","quantity":2}'
        );

        $this->assertSame($expected, JsonCanonicalizer::hash($input));
    }

    public function test_negative_zero_is_serialized_as_zero(): void
    {
        $this->assertSame(
            '0',
            JsonCanonicalizer::encode(-0.0)
        );
    }

    public function test_unicode_characters_are_preserved(): void
    {
        $this->assertSame(
            '{"name":"Kopi"}',
            JsonCanonicalizer::encode(['name' => 'Kopi'])
        );
    }

    public function test_exponent_number_serialization(): void
    {
        $this->assertSame(
            '1e+30',
            JsonCanonicalizer::encode(1.0e30)
        );
    }

    public function test_object_keys_follow_utf16_sort_order(): void
    {
        $input = [
            "\u{E000}" => 'private',
            "\u{1F600}" => 'emoji',
        ];

        $expected = '{"😀":"emoji","":"private"}';

        $this->assertSame(
            $expected,
            JsonCanonicalizer::encode($input)
        );
    }

    public function test_decimal_number_serialization(): void
    {
        $this->assertSame(
            '333333333.3333333',
            JsonCanonicalizer::encode(333333333.3333333)
        );
    }

    public function test_small_exponent_number_serialization(): void
    {
        $this->assertSame(
            '1e-7',
            JsonCanonicalizer::encode(1.0e-7)
        );
    }

    public function test_rfc_8785_appendix_b_number_serialization_vectors(): void
    {
        $vectors = [
            ['0000000000000000', '0'],
            ['8000000000000000', '0'],
            ['0000000000000001', '5e-324'],
            ['8000000000000001', '-5e-324'],
            ['7fefffffffffffff', '1.7976931348623157e+308'],
            ['ffefffffffffffff', '-1.7976931348623157e+308'],
            ['4340000000000000', '9007199254740992'],
            ['c340000000000000', '-9007199254740992'],
            ['4430000000000000', '295147905179352830000'],
            ['44b52d02c7e14af5', '9.999999999999997e+22'],
            ['44b52d02c7e14af6', '1e+23'],
            ['44b52d02c7e14af7', '1.0000000000000001e+23'],
            ['444b1ae4d6e2ef4e', '999999999999999700000'],
            ['444b1ae4d6e2ef4f', '999999999999999900000'],
            ['444b1ae4d6e2ef50', '1e+21'],
            ['3eb0c6f7a0b5ed8c', '9.999999999999997e-7'],
            ['3eb0c6f7a0b5ed8d', '0.000001'],
            ['41b3de4355555553', '333333333.3333332'],
            ['41b3de4355555554', '333333333.33333325'],
            ['41b3de4355555555', '333333333.3333333'],
            ['41b3de4355555556', '333333333.3333334'],
            ['41b3de4355555557', '333333333.33333343'],
            ['becbf647612f3696', '-0.0000033333333333333333'],
            ['43143ff3c1cb0959', '1424953923781206.2'],
        ];

        foreach ($vectors as [$ieee754, $canonical]) {
            $number = unpack('Evalue', hex2bin($ieee754))['value'];
            $this->assertSame($canonical, JsonCanonicalizer::encode($number));
        }

        $this->assertSame('1e-7', JsonCanonicalizer::encode(1.0e-7));
        $this->assertSame('100000000000000000000', JsonCanonicalizer::encode(1.0e20));
    }

    public function test_hash_uses_canonicalized_large_integer_representation(): void
    {
        $this->assertSame(
            'c681da39d7273a6a24c15c9cac3a75526ff2ecf8ba4ee60346a0c70c8163bdb2',
            JsonCanonicalizer::hash(9007199254740993),
        );
    }
}
