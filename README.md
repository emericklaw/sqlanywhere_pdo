# sqlanywhere-pdo

PDO-compatible userland wrapper around the procedural `ext-sqlanywhere` extension (`lib_sqlanywhere-php-module`) for SAP SQL Anywhere. Not a native `pdo_sqlanywhere` driver — `SqlAnywherePdo`/`SqlAnywherePdoStatement` extend `\PDO`/`\PDOStatement` but never call their native constructors; every method delegates to the `sasql_*` procedural functions instead.

## Requirements

- PHP >= 8.3
- `ext-sqlanywhere` built and loaded (see `../lib_sqlanywhere-php-module/BUILD.md`)

## Usage

```php
use EmerickLaw\SqlAnywherePdo\SqlAnywherePdo;

$pdo = new SqlAnywherePdo('UID=dba;PWD=sql;SERVER=myserver;DBN=mydb');

$stmt = $pdo->prepare('SELECT * FROM customers WHERE id = :id');
$stmt->execute(['id' => 42]);
$customer = $stmt->fetch(PDO::FETCH_ASSOC);
```

Both `?` and `:name` placeholders are supported (not mixed in the same statement) and are rewritten internally to the positional `?` placeholders `sasql_stmt_bind_param()` requires.

## Testing

```
composer install
composer test
```

Integration tests are skipped automatically unless `ext-sqlanywhere` is loaded and a `SASQL_TEST_DSN` environment variable points at a reachable server.

## Verified against a live server

Connection, transactions, prepared statements (both `?` and `:name` placeholders), all supported fetch modes, LOB streaming, and error handling under `ERRMODE_EXCEPTION`/`ERRMODE_SILENT` are covered by `tests/Integration/*` and pass against a real SQL Anywhere instance.

Three real bugs in the initial implementation were found and fixed this way, all due to `sasql_*` argument/return assumptions that couldn't be verified without a live server:
- `sasql_affected_rows()`/`sasql_field_count()` take the **connection** resource, not a result.
- `sasql_stmt_result_metadata()` returns an alias to the statement's own internal result — letting it go out of scope corrupts the statement. It's now kept alive as an instance property for the execute cycle.
- `sasql_query()` returns `bool(true)`, not a resource, when a statement has no result set (e.g. DDL/DML via `exec()`).

## Known gaps

- `PDO::FETCH_CLASS`/`FETCH_INTO`/`FETCH_LAZY`/`FETCH_KEY_PAIR` are not implemented; only `FETCH_ASSOC`, `FETCH_NUM`, `FETCH_BOTH`, and `FETCH_OBJ` are.
