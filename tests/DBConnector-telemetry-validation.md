# Connector merge regression checks

The Telescope feature incorporates `dev` without replacing its Fivetran deletion filtering,
metadata recursion guard/cache, Snowflake binding conversion, pagination, or error handling.
The existing query body runs inside the optional telemetry wrapper.

Run from a sibling cmd-runner checkout with installed dependencies:

```powershell
php vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap ../liberty-cmd-package/tests/bootstrap-workspace.php ../liberty-cmd-package/tests/Unit/DBConnectorTelemetryRegressionTest.php ../liberty-cmd-package/tests/Unit/FivetranDeletedRowFilterTest.php ../liberty-cmd-package/tests/Unit/SnowflakeBindingsTest.php --no-progress
```

Local result: 23 tests, 97 assertions passed on PHP 8.3.27. The connector regression
cases run with telemetry disabled, recording, and with a recorder that throws:

- Metadata is fetched once, with positional bindings and its original timeout.
- Business queries retain deletion filters, bindings, explicit timeouts and results.
- Metadata failure prevents the business query and resets the recursion guard for retry.
- Async status polling, partition fetching and next-page results remain intact.
- Incomplete results still throw instead of silently returning partial data.
- PDO parameterized reads/writes, commit, rollback and the connector's error result remain intact.

HTTP uses Guzzle's MockHandler beneath the connector's actual middleware stack.
PDO writes use SQLite in memory. These checks perform no live business operations and
do not establish SQL Server driver compatibility, real Snowflake authentication,
or production workload performance. Before live enablement, validate the deployed
driver and credentials in staging with read-only queries. Keep telemetry disabled
until those checks and the host rollout checks are complete.
