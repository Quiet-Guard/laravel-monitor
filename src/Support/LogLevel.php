<?php

namespace LaBoiteACode\LaravelMonitor\Support;

class LogLevel
{
    /**
     * PSR-3 levels mapped to a numeric severity (higher = more severe).
     *
     * @var array<string, int>
     */
    public const SEVERITY = [
        'debug' => 0,
        'info' => 1,
        'notice' => 2,
        'warning' => 3,
        'error' => 4,
        'critical' => 5,
        'alert' => 6,
        'emergency' => 7,
    ];

    /**
     * Whether $level is at least as severe as the configured $threshold.
     */
    public static function meetsThreshold(string $level, string $threshold): bool
    {
        $current = self::SEVERITY[strtolower($level)] ?? 1;
        $minimum = self::SEVERITY[strtolower($threshold)] ?? 0;

        return $current >= $minimum;
    }
}
