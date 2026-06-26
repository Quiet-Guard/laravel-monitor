<?php

namespace LaBoiteACode\LaravelMonitor\Support;

use LaBoiteACode\Monitor\Backup\BackupCipher as CoreBackupCipher;

/**
 * Laravel adapter alias for the framework-agnostic backup cipher. The crypto
 * lives in laboiteacode/monitor-php so every platform client shares it.
 */
class BackupCipher extends CoreBackupCipher {}
