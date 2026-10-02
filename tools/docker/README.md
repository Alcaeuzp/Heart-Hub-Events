# Docker validation

From the plugin root in PowerShell, with Docker Desktop running:

```powershell
.\tools\validate-docker.ps1
```

This runs every isolated PHP fixture and syntax check on PHP 7.4, 8.3 and 8.4, plus every shipped JavaScript syntax check and JavaScript fixture on Node 24. Each container reads a copy of the current source; plugin files are mounted read-only. Skips, PHP diagnostics, nonzero exits and fixtures with no assertions fail validation.

It then builds a disposable WordPress/PHP 8.3 container with WP-CLI and a MariaDB container. Real integration tests exercise schema upgrades, support submissions and deletion, Unicode and long email addresses, audit redaction, option locks, and parallel capacity mutations. The database and WordPress install live in container temporary filesystems. No ports are published; the integration network is internal; WordPress external HTTP and cron are disabled; mail is intercepted inside the container. The fixed test passwords are exclusively for this isolated disposable environment.

Reports are written to `validation/docker-latest`. Image tags are intentionally configurable through the tooling; record the actual image IDs/digests alongside release evidence because upstream tags can change. The default runner pulls missing official PHP/Node/WordPress images and uses `mariadb:lts`.

The Compose project is named `hherm-secondary-validation`. Only this project's containers and network are removed at completion. Pass `-KeepContainers` to retain them for troubleshooting, then clean up with:

```powershell
docker compose -p hherm-secondary-validation -f tools/docker/compose.yml down --volumes
```

Run integration checks against that retained environment:

```powershell
docker compose -p hherm-secondary-validation -f tools/docker/compose.yml exec -T wordpress wp eval-file /plugin/tests/integration/wordpress-mariadb.php
docker compose -p hherm-secondary-validation -f tools/docker/compose.yml exec -T wordpress wp eval-file /plugin/tests/integration/capacity-concurrency.php
```

The parallel capacity test launches 32 PHP processes across four synchronized batches: competition for the final seats, repeated releases, repeated reservations, and partial check-in releases. These use the real WordPress options/postmeta APIs and MariaDB audit table. They do not simulate browser requests or load JetEngine.

JetEngine is a separately licensed dependency and is not bundled here. Registration CCT tests therefore use injected adapters; installed JetEngine mappings, browser behavior and SMTP delivery still require staging checks. Test handlers and destructive integration scripts refuse to run outside the explicitly marked disposable environment.
