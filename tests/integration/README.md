# WordPress integration fixtures

Use only a disposable WordPress installation backed by MariaDB/MySQL. These fixtures exercise native schema upgrades and inject selected SQL failures. Never run them against production.

```sh
HHERM_INTEGRATION_TEST=1 wp eval-file /plugin/tests/integration/wordpress-mariadb.php --allow-root
```

The plugin must already be active. The support fixture uses real WordPress options, capabilities, nonces, `dbDelta`, and SQL persistence. A second database connection tests lock replacement ownership. Redirect termination and outbound mail are intercepted; normal messages remain in Log only mode. Original settings are restored and created applicant/audit rows removed afterward.

Coverage includes migration retry, saved programme values, SQL failure injection, public submissions and approval nonces, Unicode/long-email boundaries, source-aware audit erasure, ID collisions, legacy interests, strict operational-ledger reads, and atomic lock replacement. Actual WordPress/PHP/database versions are printed and written with per-check results to `/results/wordpress-mariadb.json`.

Support schema 5 indexes complete programme/email/type values. With utf8mb4 this requires support for an index of approximately 1,096 bytes (supported by current MySQL/MariaDB/InnoDB configurations). An older engine/row format restricted to 767 bytes keeps its prior prefix key when the atomic replacement fails; the plugin leaves the upgrade pending and displays its database-upgrade error. Such a database needs upgrading before full long-email uniqueness is available.

JetEngine and JetFormBuilder are not validated by this support fixture. Genuine CCT/form-action compatibility requires the licensed vendor plugins. Standalone fixtures in the parent `tests` directory remain separate from this suite.
