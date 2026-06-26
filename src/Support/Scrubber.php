<?php

namespace LaBoiteACode\LaravelMonitor\Support;

class Scrubber
{
    public const MASK = '[scrubbed]';

    /**
     * @param  array<int, string>  $keys
     */
    public function __construct(private readonly array $keys = []) {}

    /**
     * Recursively mask values whose key matches one of the configured terms.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function scrub(array $data): array
    {
        $needles = array_map('strtolower', $this->keys);

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->matches(strtolower($key), $needles)) {
                $data[$key] = self::MASK;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $this->scrub($value);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, string>  $needles
     */
    private function matches(string $key, array $needles): bool
    {
        foreach ($needles as $needle) {
            if ($needle !== '' && str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }
}
