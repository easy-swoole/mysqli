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

Protocol recovery tests also cover deadlock state before any explicit rollback, malformed result rejection and reconnection, truncated integer/binary row decoding, and segmented parameter payloads.

`LargeParameterMemoryTest` runs a separate PHP process with `memory_limit=128M`. It binds the same 16 MiB random binary string twice and validates the server-reported length and SHA-256 with compression both disabled and enabled. This test needs `proc_open`, inherited test database environment variables, and a server `max_allowed_packet` large enough for the approximately 32 MiB execute packet.

`DeadlineTest` checks that shorter limits cannot extend or revive an operation budget. `SessionStateTest` covers session generations, intentional versus unexpected disconnects, deadlock/lock-wait behavior, and completed transaction release. Existing fake-server tests continue to exercise the full PacketStream framing, compression, shared timeout and recovery paths through the public client API.

## TLS tests

`TlsTest` is part of the unit suite. It generates disposable certificates using the PHP OpenSSL extension and starts local fake MySQL servers. It covers opt-in defaults, SSLRequest framing and sequence numbers, full caching_sha2 authentication inside TLS, untrusted/expired certificates, DNS/IP identity validation, wildcard boundaries, SAN parsing ambiguity, unsupported-server rejection without plaintext fallback, and the shared handshake deadline. Private fixture keys are removed after the test class completes.

To run integration tests with TLS, use a dedicated disposable database because the suite replaces fixture tables:

```bash
export MYSQLI_TEST_TLS_MODE=verify_identity
export MYSQLI_TEST_TLS_CA_FILE=/secure/mysql/ca.pem
# Optional: MYSQLI_TEST_TLS_CA_PATH, MYSQLI_TEST_TLS_SERVER_NAME
# Optional mutual TLS: MYSQLI_TEST_TLS_CERT_FILE, MYSQLI_TEST_TLS_KEY_FILE,
# MYSQLI_TEST_TLS_KEY_PASSPHRASE
composer test:integration
```

All existing integration tests and their child processes inherit these settings. With no TLS environment variables, integration connections remain unencrypted, preserving the existing default.

## Query building, pagination and streaming

`QueryBuilderIdentifierRegressionTest` covers table prefixes and quoted/schema-qualified names, UPDATE column references, and complete aliases/expressions in `getColumn()` and `getScalar()`. Query-builder regression/state tests cover bound FIELD sorting and failure/reset/raw isolation; aggregate/pagination tests cover DISTINCT, GROUP BY/HAVING, UNION, derived sources, large totals and cursor-template reuse.

`ResultStreamTest` and `StreamingTest` cover one-pass reads, connection ownership until EOF, early break/destruction/error cleanup, concurrent-close rejection during a blocked fetch, statement reuse versus automatic ownership, transaction-loss protection and the shared deadline including consumer time. Client pagination/chunk tests check callback stopping, key progression and the shared COUNT/page timeout. These tests complement the existing buffered-protocol tests; the container matrix below must be executed to establish results for its exact runtimes.

## Refactoring and native type contracts

`ApiContractTest` compares existing public/protected method shapes against a pre-refactor baseline: method names, parameter names/order/defaults, static/visibility/reference behavior and existing input type domains. This round explicitly allows adding native declarations at previously untyped positions. `QueryBuilderPublicApiTest` also records the current full QueryBuilder signatures. Review intentional API changes before updating either baseline.

`NativeTypeCompletenessTest` checks every source-declared property, method parameter and return position, including private methods. Constructors/destructors have no return type as required by PHP; engine-generated enum properties are excluded. Flexible legacy inputs and database values intentionally retain `mixed`, with PHPDoc describing array and callback shapes.

`QueryBuilderStateTest` exercises clone isolation after raw/column/group helpers, subclass constructors and clone hooks, reset/debug publication timing, copied completed results, FROM/UNION snapshots and delayed IN/EXISTS/JOIN reads. Its behavior assertions also pass against the pre-refactor source.

`SelectPlannerTest`, `AuthenticatorTest` and `ClientExecutionContextTest` cover subclass dispatch, source snapshots, capability/authentication validation, the shared operation deadline, exception precedence, owned-statement cleanup and observer timing. Existing fake-server and real database tests exercise the full path after extraction.

## Reproducible container matrix

With Docker running, execute `bash tests/docker/run-matrix.sh --all`, or select one lane from `bash tests/docker/run-matrix.sh --list`. No host PHP/Swoole or existing MySQL server is required. See [the container harness](docker/README.md) for the exact versions and isolation rules.

The four lanes cover PHP 8.1.34/Swoole 5.1.8 on MySQL 8.0.46 in plaintext, plus PHP 8.4.27/Swoole 6.2.4 on MySQL 8.0.46, 8.4.13 and 9.7.4 with VerifyIdentity TLS. Compression is exercised both disabled and enabled without multiplying the matrix. The server uses strict SQL modes, a dedicated disposable database, and temporary certificates. Unit tests run once for each PHP/Swoole runtime; database tests run on every lane. The plaintext lane excludes only TLS-specific integration tests, and selected tests fail on unexpected skips.

`bash tests/docker/self-check.sh` validates the harness without starting containers. Passing this check does not establish that the Docker image builds or the database matrix passes. The current machine has no Docker runtime, so container execution remains to be verified in CI or on a Docker-enabled machine. A pinned dependency snapshot under `tests/docker/` is used only in temporary test workspaces; the root composer.lock policy is unchanged.

## Prepared-statement reuse benchmark

With the same dedicated test database environment variables as above:

```bash
php tests/benchmarks/prepare-reuse.php 1000
```

The benchmark only executes `SELECT ? AS benchmark_value`. It compares `Client::query($builder)` (Prepare/Execute/Close per query) with a manually prepared `Statement` reused across executions. It checks each result, warms up, alternates mode order across three rounds, and reports PHP/Swoole/MySQL versions, TLS/compression, elapsed time and mean microseconds per query as JSON.

The optional argument is a positive iteration count per round. Timing depends on network latency, server load and transport settings; the reported speedup is an observation, not a pass/fail threshold or a promised production improvement. Reuse remains limited to the current server session; a disconnected session requires a new Prepare. This command does not execute or validate the Docker matrix.

## Bounded stream memory

`StreamMemoryIntegrationTest` runs a child PHP process with `memory_limit=32M` and reads 10,000 rows of 4 KiB each (40,960,000 payload bytes) through the real text-protocol iterator. It checks every row hash, EOF metadata and connection reuse, both with compression disabled and enabled. The stream has a 120-second total budget so that the remote network transfer fits the test; this does not change the library's default timeout. Each worker must finish within 32 MiB, rather than buffering the entire result.
