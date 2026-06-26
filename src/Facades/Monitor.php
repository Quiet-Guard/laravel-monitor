<?php

namespace LaBoiteACode\LaravelMonitor\Facades;

use Illuminate\Support\Facades\Facade;
use Throwable;

/**
 * @method static void report(Throwable $e)
 *
 * @see \LaBoiteACode\LaravelMonitor\Monitor
 */
class Monitor extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \LaBoiteACode\LaravelMonitor\Monitor::class;
    }
}
