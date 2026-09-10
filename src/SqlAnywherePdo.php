<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo;

use EmerickLaw\SqlAnywherePdo\Internal\ConnectionStringBuilder;
use EmerickLaw\SqlAnywherePdo\Internal\ErrorInfo;
use EmerickLaw\SqlAnywherePdo\Internal\PlaceholderTranslator;
use PDO;

/**
 * PDO-compatible connection backed by the procedural ext-sqlanywhere
 * (sasql_*) extension. Never calls parent::__construct() — there is no
 * native pdo_sqlanywhere driver registered with PHP, so every PDO method
 * used here is reimplemented against the sasql_* resource functions.
 */
class SqlAnywherePdo extends PDO
{
    /** @var resource */
    private mixed $conn;

    private bool $isPersistent = false;

    private int $errorMode = PDO::ERRMODE_EXCEPTION;

    private int $defaultFetchMode = PDO::FETCH_BOTH;

    private bool $inTransaction = false;

    /** @var array<int, mixed> */
    private array $attributes = [];

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null)
    {
        $options ??= [];

        if (isset($options[PDO::ATTR_ERRMODE])) {
            $this->errorMode = (int) $options[PDO::ATTR_ERRMODE];
        }

        $connectionString = ConnectionStringBuilder::build($dsn, $username, $password);
        $this->isPersistent = (bool) ($options[PDO::ATTR_PERSISTENT] ?? false);

        $conn = $this->isPersistent
            ? @sasql_pconnect($connectionString)
            : @sasql_connect($connectionString);

        if ($conn === false) {
            // No connection resource exists yet, so the per-connection
            // sasql_error($conn) form can't be used — sqlanywhere.c:3742
            // supports a zero-arg call that returns the last global error.
            $message = @sasql_error() ?: 'Unable to connect to SQL Anywhere.';

            throw SqlAnywherePdoException::fromErrorInfo(['08001', null, $message]);
        }

        $this->conn = $conn;

        foreach ($options as $attribute => $value) {
            if ($attribute === PDO::ATTR_PERSISTENT || $attribute === PDO::ATTR_ERRMODE) {
                continue;
            }

            $this->setAttribute($attribute, $value);
        }
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): SqlAnywherePdoStatement|false
    {
        $result = @sasql_query($this->conn, $query);

        if ($result === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        $statement = new SqlAnywherePdoStatement($this, result: $result);

        if ($fetchMode !== null) {
            $statement->setFetchMode($fetchMode, ...$fetchModeArgs);
        }

        return $statement;
    }

    public function prepare(string $query, array $options = []): SqlAnywherePdoStatement|false
    {
        $translated = PlaceholderTranslator::translate($query);

        $stmt = @sasql_prepare($this->conn, $translated->sql);

        if ($stmt === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        return new SqlAnywherePdoStatement($this, stmt: $stmt, paramOrder: $translated->paramOrder, sql: $query);
    }

    public function exec(string $statement): int|false
    {
        $result = @sasql_query($this->conn, $statement);

        if ($result === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        // sasql_affected_rows() takes the CONNECTION resource, not the
        // result (sqlanywhere.c:2865) — it reads $conn->last_stmt internally.
        $affected = sasql_affected_rows($this->conn);

        // sasql_query() returns bool(true) rather than a result resource
        // when the statement produced no result set (sqlanywhere.c:2459-2461)
        // — the common case for exec()'s INSERT/UPDATE/DELETE/DDL use.
        if (is_resource($result)) {
            sasql_free_result($result);
        }

        return $affected;
    }

    public function beginTransaction(): bool
    {
        if ($this->inTransaction) {
            throw new SqlAnywherePdoException('There is already an active transaction');
        }

        if (@sasql_set_option($this->conn, 'auto_commit', 'off') === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        if (!$this->inTransaction) {
            throw new SqlAnywherePdoException('There is no active transaction');
        }

        if (@sasql_commit($this->conn) === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        @sasql_set_option($this->conn, 'auto_commit', 'on');
        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (!$this->inTransaction) {
            throw new SqlAnywherePdoException('There is no active transaction');
        }

        if (@sasql_rollback($this->conn) === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        @sasql_set_option($this->conn, 'auto_commit', 'on');
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        // SQL Anywhere has no named-sequence concept (unlike Postgres/Oracle);
        // $name is accepted for PDO signature compatibility and ignored,
        // matching the native MySQL PDO driver's own behavior.
        $id = @sasql_insert_id($this->conn);

        if ($id === false) {
            return $this->fail($this->connectionErrorInfo());
        }

        return (string) $id;
    }

    public function errorCode(): ?string
    {
        $info = $this->connectionErrorInfo();

        return $info->hasError() ? $info->sqlstate : null;
    }

    public function errorInfo(): array
    {
        return $this->connectionErrorInfo()->toArray();
    }

    public function quote(string $string, int $type = PDO::PARAM_STR): string|false
    {
        if ($type === PDO::PARAM_LOB) {
            return false;
        }

        $escaped = @sasql_real_escape_string($this->conn, $string);

        if ($escaped === false) {
            return false;
        }

        return "'" . $escaped . "'";
    }

    public function getAttribute(int $attribute): mixed
    {
        return match ($attribute) {
            PDO::ATTR_DRIVER_NAME => 'sqlanywhere',
            PDO::ATTR_ERRMODE => $this->errorMode,
            PDO::ATTR_DEFAULT_FETCH_MODE => $this->defaultFetchMode,
            PDO::ATTR_AUTOCOMMIT => !$this->inTransaction,
            PDO::ATTR_PERSISTENT => $this->isPersistent,
            default => $this->attributes[$attribute] ?? null,
        };
    }

    /**
     * PDO has no universal ATTR_CLIENT_VERSION constant (only some
     * driver-specific extensions define one), so this is exposed as its
     * own method rather than wired through getAttribute().
     */
    public function getClientInfo(): string|false
    {
        return @sasql_get_client_info();
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        switch ($attribute) {
            case PDO::ATTR_ERRMODE:
                $this->errorMode = (int) $value;

                return true;
            case PDO::ATTR_DEFAULT_FETCH_MODE:
                $this->defaultFetchMode = (int) $value;

                return true;
            case PDO::ATTR_AUTOCOMMIT:
                @sasql_set_option($this->conn, 'auto_commit', $value ? 'on' : 'off');

                return true;
            default:
                $this->attributes[$attribute] = $value;

                return true;
        }
    }

    /**
     * @internal used by SqlAnywherePdoStatement when no explicit fetch mode
     * has been set on the statement.
     */
    public function getDefaultFetchMode(): int
    {
        return $this->defaultFetchMode;
    }

    /**
     * @internal exposes the connection resource to SqlAnywherePdoStatement
     * for operations that need it (e.g. re-checking connection-level errors).
     * @return resource
     */
    public function getConnectionResource(): mixed
    {
        return $this->conn;
    }

    /**
     * Applies this connection's ERRMODE policy to a failed operation and
     * always returns false so call sites can `return $this->fail(...)`.
     */
    public function fail(ErrorInfo $info): false
    {
        if (!$info->hasError()) {
            return false;
        }

        match ($this->errorMode) {
            PDO::ERRMODE_SILENT => null,
            PDO::ERRMODE_WARNING => trigger_error($info->driverMessage ?? 'SQL Anywhere error', E_USER_WARNING),
            default => throw SqlAnywherePdoException::fromErrorInfo($info->toArray()),
        };

        return false;
    }

    /**
     * @internal used by SqlAnywherePdoStatement for the non-prepared
     * (query()/exec()) fetch path, which has no statement-level error
     * functions of its own and relies on the connection-level ones.
     */
    public function connectionErrorInfo(): ErrorInfo
    {
        $sqlstate = @sasql_sqlstate($this->conn);
        $code = @sasql_errorcode($this->conn);
        $message = @sasql_error($this->conn);

        if (($sqlstate === '00000' || $sqlstate === false || $sqlstate === null) && empty($message)) {
            return ErrorInfo::none();
        }

        return new ErrorInfo($sqlstate ?: 'HY000', $code, $message ?: null);
    }

    public function __destruct()
    {
        if (!$this->isPersistent && isset($this->conn) && is_resource($this->conn)) {
            @sasql_close($this->conn);
        }
    }
}
