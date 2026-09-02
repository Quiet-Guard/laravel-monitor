<?php

namespace QuietGuard\LaravelMonitor\Facades;

use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static void report(Throwable $e)
 *
 * @see \QuietGuard\LaravelMonitor\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \QuietGuard\LaravelMonitor\Monitor::class;
    }
}
