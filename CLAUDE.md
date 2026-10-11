# CLAUDE.md

Sylius 2 plugin recording the admin changes of Sylius resources (who changed what, when) with
field-level diffs, an admin grid, entry pages and history cards.
Functional spec and research: `SPEC.md`. Public docs: `README.md`.

## Commands

PHP needs more than 128 MB to compile the test application container: prefix console and
PHPStan calls with `php -d memory_limit=2G` when running locally.

```bash
docker compose up -d mysql mailhog        # ports come from compose.override.yml (gitignored)
vendor/bin/phpunit --testsuite unit
vendor/bin/phpunit --testsuite non-unit   # functional + integration, need the test DB + Sylius fixtures
vendor/bin/phpstan analyse                # level max, src + tests
vendor/bin/ecs check
vendor/bin/console lint:container
vendor/bin/console tmonier:audit-log:demo -e dev   # demo entries (test application command)
```

Local env overrides (gitignored): `compose.override.yml` (MySQL published on 3320, MailHog on
8039/1039), `tests/TestApplication/.env.local` (DATABASE_URL + MAILER_DSN) and
`.env.test.local` (DATABASE_URL). Databases: `tmonier_sylius_audit_log_dev` / `_test`.
Dev server: `symfony server:start -d --port=8086 --no-tls`.

## Architecture

- `src/Entity/AuditLogEntry` — single resource, XML mapping in `config/doctrine` (mapped
  superclass), no foreign key. Dates are stored in UTC; the getter reads them back as UTC.
- `src/Recorder/AuditLogRecorder` — Doctrine `onFlush` / `postFlush` listener; keep the
  strategy: read in `onFlush` (`ChangeCollector`), write in `postFlush` with DBAL
  (`AuditLogWriter`, savepoint), one batch per flush on a stack, every error logged and
  swallowed. Never `persist()` / `flush()` entries from the listener.
- `src/Recorder/ChangeCollector` — turns the unit of work into `PendingEntry` objects:
  translations folded into the translatable (`name (fr_FR)`), collections into their owner,
  references kept as `ObjectReference` until ids exist (`postFlush`).
- `src/Diff` — `FieldPolicy` (ignored / masked fields), `ValueNormalizer` (JSON-safe values,
  truncation), `PendingEntry`.
- `src/Resource` — audited resources from the configuration (defaults in
  `Configuration::DEFAULT_RESOURCES`, merged in `TmonierSyliusAuditLogExtension::mergeResources`).
- `src/Resolver` — labels and owners (tagged, extension points); `CompositeOwnerResolver`
  climbs to the aggregate root stored in `owner_type` / `owner_id`.
- `src/Context` — actor, source (Sylius section resolver), IP, request id, console command;
  `RecordingPolicy` (enabled, sources, ignored commands).
- `src/Grid/ResourceFilter` + `src/Form/Type/ResourceFilterType` — custom grid filter
  (type + id, also matching owned entries); its template is declared in `config/config.yaml`.
- `src/Maintenance/CustomerAuditLogAnonymizer`, `src/Command` — purge and erasure.
- `src/Twig` — history card data and presentation helpers (labels, values, actor, admin URLs).
- `tests/TestApplication/src/Command/GenerateDemoAuditLogCommand` — dev demo data only.

## Rules

- Recording must never break the audited flush: catch, log, continue.
- Never store secrets: new sensitive fields go to `DEFAULT_MASKED_FIELDS` or the patterns.
- Model class parameters `%tmonier_audit_log.model.*.class%` are set in `prepend()` because
  grids are processed before the extension loads. The model config key is `models`
  (`resources` is the list of audited resources).
- Migrations use the Schema API (MySQL, MariaDB, PostgreSQL) and contain plugin tables only.
- Every translation key must exist in en, fr, de, es, it, nl, pl (`TranslationsTest`), and
  every default resource, action, actor type and source needs a label. Clear the cache (dev
  and test) after adding a translation file.
- MySQL reorders JSON object keys: compare stored changes with `assertEquals`, not `assertSame`.
- Twig: `|default` treats `false` as empty; use `?? null` for diff values.
- Functional tests run in a transaction rolled back after each test (kernel not rebooted).
  The admin product form does not survive a kept kernel: `AdminProductEditTest` reboots and
  cleans up after itself.
