<?php

namespace LaBoiteACode\LaravelMonitor\Support;

use LaBoiteACode\Monitor\Support\Scrubber as CoreScrubber;

/**
 * Thin alias over the framework-agnostic core scrubber so every client in the
 * family masks with the same rules (lower-cased substring match on key names).
 */
class Scrubber extends CoreScrubber {}
