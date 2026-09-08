# Changelog

All notable changes to `quiet-guard/laravel-monitor`.

## v0.2.1

### Security

- Payloads are masked before they are queued. With `MONITOR_QUEUE` set, the
  masking ran on the wire only, so the raw exception and log payloads, with the
  email addresses, IBANs and card numbers the masking exists to catch, were
  serialised in clear into your `jobs` table and permanently into `failed_jobs`.

### Added

- `MONITOR_URL` defaults to `https://quietguard.dev`. Pointing at the hosted
  service now needs a key and nothing else; set the variable to reach your own
  instance.
- `monitor:backups` lists what the vault holds for this project: id, type, name,
  size and date. The endpoint has existed since the vault shipped and no command
  could reach it, so an id lost after an upload could only be found in the
  dashboard, while `monitor:restore` takes that id as its only argument.
- `monitor:backup --keep=N` deletes older backups of the same type after a
  successful upload. Nothing rotated anything before, so the nightly schedule the
  documentation recommends filled the plan allowance and then failed every night.

### Fixed

- A failure says what happened. `monitor:dependencies`, `monitor:backup` and
  `monitor:restore` print the status, the address and the server's own sentence.
  "Encryption is not configured for this team" used to answer four different
  situations, three of which that advice does not fix.
- An address that already ends in `/api/v1` is accepted, and whitespace around
  it is ignored. The client appends `/api/v1` itself, so the other reading of
  the setting answered 404 on every call.
- `MONITOR_QUEUE` names a queue CONNECTION, and an unknown one no longer stops
  reporting. `default`, `monitoring` or `high` are not keys under
  `queue.connections`, so the dispatch threw, the client swallowed it, and
  nothing was reported again. An unknown value now logs the valid connections
  and reports synchronously. This covers log batches too, where the failure was
  worse: the buffer is cleared before dispatch, so every flush lost its entries.
- One bad line no longer sinks a whole log batch. The server refuses an empty
  message and one over 8192 characters, and refuses the entire batch with it, so
  a single line could lose the two hundred it travelled with. Long messages are
  trimmed, empty ones substituted, and `MONITOR_LOGS_MAX_BATCH` is held to the
  server's own limit of 500, above which nothing was ever accepted.
- Exception messages, class names, file paths and release strings are trimmed to
  the server's limits instead of being refused and lost. A query exception
  carries its whole SQL in its message, and an exception from an anonymous class
  carries the file path inside its class name.
- `monitor:restore` compares the checksum the server publishes before it
  decrypts. A damaged download used to fail with the same message as a wrong
  passphrase, and those two do not get fixed the same way.
- `monitor:backup --database` no longer locks the database it is backing up:
  `mysqldump` runs with `--single-transaction`, `--no-tablespaces` so a
  least-privileged user is enough, and `--default-character-set=utf8mb4`. A
  failed sqlite copy is detected instead of producing an empty archive.
- A dump or an archive cut short by a full disk fails instead of being uploaded
  and reported as a success. A database backup needs about twice the dump size
  free in the temporary directory, once for the dump and once for the encrypted
  copy, and the message says so.
- `monitor:backup` checks its own options before the network call, so running it
  with none names the missing option rather than the encryption key.
- The queued jobs fail when the send failed, so the two attempts they declare
  are real. They completed silently whatever the server answered.

### Changed

- Requires `quiet-guard/monitor-php` `^0.2.1`.
