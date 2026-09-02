<?php

namespace QuietGuard\LaravelMonitor\Support;

use QuietGuard\Monitor\Backup\BackupCipher as CoreBackupCipher;

/**
 * Laravel adapter alias for the framework-agnostic backup cipher. The crypto
 * lives in quiet-guard/monitor-php so every platform client shares it.
 */
class BackupCipher extends CoreBackupCipher {}
