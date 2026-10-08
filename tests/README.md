# Tests

Unit tests need PHP 8.1+, Swoole 5.1+ and no database:

```bash
composer test:unit
```

Integration tests reuse the SQL fixtures from `easyswoole/fast-db`. Point them at a dedicated test database:

```bash
export FAST_DB_TEST_HOST=127.0.0.1
export FAST_DB_TEST_PORT=3306
export FAST_DB_TEST_USER=test
export FAST_DB_TEST_PASSWORD='secret'
export FAST_DB_TEST_DATABASE=test
composer test:integration
```

The integration suite creates or replaces the FastDb test tables and exercises raw queries, native prepared statements, `QueryBuilder`, reconnection, and per-query timeouts. It also creates a random temporary table and verifies both text and prepared result protocols for signed and unsigned integers (including the complete `BIGINT UNSIGNED` range), decimal, float, double, bit, character and binary strings, text/blob, date, multi-day negative time, datetime, timestamp, year, JSON, enum, set, and NULL values. The temporary table is dropped after each test.

When the server permits packets larger than 16 MiB, `LargePacketTest` uploads, reads, updates, and reads back a payload larger than MySQL's `0xFFFFFF` single-packet boundary. Length and SHA-256 checks verify that packet fragmentation and reassembly preserve every byte.

`TransactionTest` uses independent InnoDB sessions to verify commit visibility, full rollback, rollback to savepoint, lock-wait error `1205`, and a real two-session deadlock where exactly one transaction receives error `1213` and the other commits.
