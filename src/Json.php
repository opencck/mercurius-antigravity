<?php

declare(strict_types=1);

namespace Mercurius\Antigravity;

/**
 * Reads fields of decoded JSON nobody vouches for: what `agy` printed, what a request holds.
 *
 * @internal
 */
final class Json {
    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed> the object or list under the key; empty when it is anything else
     */
    public static function object(array $data, string|int $key): array {
        /** @psalm-suppress MixedAssignment */
        $value = $data[$key] ?? null;
        return is_array($value) ? $value : [];
    }

    /** @param array<array-key, mixed> $data */
    public static function string(array $data, string|int $key): ?string {
        /** @psalm-suppress MixedAssignment */
        $value = $data[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** @param array<array-key, mixed> $data */
    public static function int(array $data, string|int $key): ?int {
        /** @psalm-suppress MixedAssignment */
        $value = $data[$key] ?? null;
        return is_int($value) ? $value : null;
    }
}
