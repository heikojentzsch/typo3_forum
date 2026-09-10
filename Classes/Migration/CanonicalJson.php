<?php

declare(strict_types = 1);

namespace Mittwald\Typo3Forum\Migration;

final class CanonicalJson
{
    public static function encode(mixed $value): string
    {
        return json_encode(self::normalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function checksum(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    private static function normalize(mixed $value): mixed
    {
        if (is_string($value) && preg_match('//u', $value) !== 1) {
            return ['__binary_sha256' => hash('sha256', $value), '__length' => strlen($value)];
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as &$item) {
            $item = self::normalize($item);
        }
        return $value;
    }
}
