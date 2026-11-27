<?php

namespace App\Support;

use InvalidArgumentException;

class JsonCanonicalizer
{
    public static function encode(mixed $value): string
    {
        return self::serializeValue($value);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function serializeValue(mixed $value): string
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = array_map(
                    fn ($item) => self::serializeValue($item),
                    $value
                );

                return '['.implode(',', $items).']';
            }

            $keys = array_keys($value);

            foreach ($keys as $key) {
                if (! is_string($key)) {
                    throw new InvalidArgumentException(
                        'JSON object keys must be strings.'
                    );
                }
            }

            usort($keys, function (string $a, string $b): int {
                $aUtf16 = iconv('UTF-8', 'UTF-16BE', $a);
                $bUtf16 = iconv('UTF-8', 'UTF-16BE', $b);

                if ($aUtf16 === false || $bUtf16 === false) {
                    throw new InvalidArgumentException(
                        'Invalid UTF-8 object key.'
                    );
                }

                return strcmp($aUtf16, $bUtf16);
            });

            $items = [];

            foreach ($keys as $key) {
                $encodedKey = json_encode(
                    $key,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
                );

                $items[] = $encodedKey.':'.self::serializeValue($value[$key]);
            }

            return '{'.implode(',', $items).'}';
        }

        if (is_string($value) || is_bool($value) || $value === null) {
            return json_encode(
                $value,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        }

        if (is_int($value) || is_float($value)) {
            return self::serializeNumber((float) $value);
        }

        throw new InvalidArgumentException('Unsupported JSON value.');
    }

    private static function serializeNumber(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException(
                'Non-finite numbers are not valid JSON.'
            );
        }

        if ($value == 0.0) {
            return '0';
        }

        $encoded = json_encode($value, JSON_THROW_ON_ERROR);

        if (! str_contains($encoded, 'e')) {
            return str_ends_with($encoded, '.0')
                ? substr($encoded, 0, -2)
                : $encoded;
        }

        [$mantissa, $exponent] = explode('e', $encoded, 2);
        $exponent = (int) $exponent;
        $negative = str_starts_with($mantissa, '-');
        $unsignedMantissa = $negative ? substr($mantissa, 1) : $mantissa;
        $decimalPosition = strpos($unsignedMantissa, '.');
        $decimalPosition = $decimalPosition === false
            ? strlen($unsignedMantissa)
            : $decimalPosition;
        $digits = rtrim(str_replace('.', '', $unsignedMantissa), '0');
        $decimalPosition += $exponent;

        if ($decimalPosition > 0 && $decimalPosition <= 21) {
            if (strlen($digits) <= $decimalPosition) {
                $canonical = $digits.str_repeat('0', $decimalPosition - strlen($digits));
            } else {
                $canonical = substr($digits, 0, $decimalPosition)
                    .'.'
                    .substr($digits, $decimalPosition);
            }
        } elseif ($decimalPosition <= 0 && $decimalPosition > -6) {
            $canonical = '0.'.str_repeat('0', -$decimalPosition).$digits;
        } else {
            $canonical = $digits[0];

            if (strlen($digits) > 1) {
                $canonical .= '.'.substr($digits, 1);
            }

            $scientificExponent = $decimalPosition - 1;
            $canonical .= 'e'.($scientificExponent >= 0 ? '+' : '').$scientificExponent;
        }

        return ($negative ? '-' : '').$canonical;
    }
}
