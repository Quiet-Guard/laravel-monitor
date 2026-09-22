<?php

namespace QuietGuard\LaravelMonitor\Support;

use QuietGuard\Monitor\Support\Scrubber as CoreScrubber;

/**
 * Thin alias over the framework-agnostic core scrubber so every client in the
 * family masks with the same rules: a key name is matched by lower-cased
 * substring, a hyphen reading as an underscore and a configured name also
 * found without its inner separators; a string holding JSON is opened and
 * masked by key; a string that starts with a URL has its query values, its
 * token-shaped path segments and its userinfo password masked; and a source
 * snippet line that gives a secret a value goes whole (see the core class).
 */
class Scrubber extends CoreScrubber {}
