<?php

declare(strict_types=1);

namespace EmerickLaw\SqlAnywherePdo;

use EmerickLaw\SqlAnywherePdo\Internal\ErrorInfo;
use EmerickLaw\SqlAnywherePdo\Internal\TypeMapper;
use PDO;
use PDOStatement;

/**
 * PDO-compatible statement backed by ext-sqlanywhere. Declares its own
 * constructor and never calls parent::__construct() — PDOStatement has no
 * usable public constructor since it's normally only ever created
 * internally by a real PDO driver, which doesn't exist here.
 *
 * Two backing modes, matching the two families of sasql_* functions:
 *  - "result" mode: built from PDO::query()/PDO::exec() via sasql_query(),
 *    already executed; fetches go through sasql_fetch_*($result).
 *  - "stmt" mode: built from PDO::prepare() via sasql_prepare(); binding
 *    and fetching go through the sasql_stmt_*($stmt) family, which mirrors
 *    mysqli_stmt's bind_result()+fetch() model (fetch() fills previously
 *    bound-by-reference variables rather than returning a row directly).
 */
class SqlAnywherePdoStatement extends PDOStatement
{
    /** @var resource|null */
    private mixed $result;

    /** @var resource|null */
    private mixed $stmt;

    private readonly bool $isPrepared;

    /** @var list<string|int> */
    private readonly array $paramOrder;

    /** @var array<int, array{value?: mixed, ref?: mixed, type: int, isRef: bool}> */
    private array $boundParams = [];

    private int $fetchMode = PDO::FETCH_BOTH;

    /** @var list<mixed> */
    private array $fetchModeArgs = [];

    private bool $preparedResultBound = false;

    /**
     * Set when ensurePreparedResultBound() has already consumed the first
     * row via sasql_stmt_fetch() to force SQL Anywhere to expose field
     * metadata (see its doc comment). The next fetch() call must return
     * this buffered row instead of advancing the cursor again.
     */
    private bool $hasPrefetchedRow = false;

    private int $columnCount = 0;

    /** @var list<string> */
    private array $columnNames = [];

    /** @var list<mixed> */
    private array $fetchBuffer = [];

    /** @var array<int, mixed> references bound via bindColumn(), synced after each stmt fetch */
    private array $externalColumnBindings = [];

    /**
     * sasql_stmt_result_metadata() returns an ALIAS to the statement's own
     * internal result object, not a copy (confirmed empirically: letting
     * its refcount hit zero — e.g. a local var going out of scope — zeroes
     * out sasql_stmt_field_count() and breaks sasql_stmt_fetch() on THIS
     * SAME stmt afterward). Must be kept alive as long as the current
     * execute() cycle is being fetched from.
     * @var resource|null
     */
    private mixed $resultMetadataResource = null;

    /**
     * @param list<string|int> $paramOrder
     * @param resource|null $result
     * @param resource|null $stmt
     */
    public function __construct(
        private readonly SqlAnywherePdo $pdo,
        mixed $result = null,
        mixed $stmt = null,
        array $paramOrder = [],
    ) {
        $this->result = $result;
        $this->stmt = $stmt;
        $this->isPrepared = $stmt !== null;
        $this->paramOrder = $paramOrder;
        $this->fetchMode = $pdo->getDefaultFetchMode();
    }

    public function bindParam(string|int $param, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        $slot = $this->resolveSlot($param);
        $this->boundParams[$slot] = ['ref' => &$var, 'type' => $type, 'isRef' => true];

        return true;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $slot = $this->resolveSlot($param);
        $this->boundParams[$slot] = ['value' => $value, 'type' => $type, 'isRef' => false];

        return true;
    }

    public function bindColumn(string|int $column, mixed &$var, int $type = PDO::PARAM_STR, int $maxLength = 0, mixed $driverOptions = null): bool
    {
        if (!$this->isPrepared) {
            // No stmt_bind_result equivalent exists for the non-prepared path.
            return false;
        }

        $this->ensurePreparedResultBound();

        $index = is_int($column)
            ? $column - 1
            : array_search(strtolower((string) $column), array_map('strtolower', $this->columnNames), true);

        if ($index === false || !array_key_exists($index, $this->fetchBuffer)) {
            return false;
        }

        $this->externalColumnBindings[$index] = &$var;

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if (!$this->isPrepared) {
            throw new SqlAnywherePdoException('execute() is only supported on a statement created by PDO::prepare().');
        }

        if ($params !== null) {
            $isList = array_is_list($params);
            foreach ($params as $key => $value) {
                $this->bindValue($isList ? $key + 1 : $key, $value, TypeMapper::inferParamType($value));
            }
        }

        $this->bindBufferedParamsToStatement();
        $this->sendLongDataForLobParams();

        if (@sasql_stmt_execute($this->stmt) === false) {
            return $this->pdo->fail($this->statementErrorInfo());
        }

        // sasql_stmt_execute() already closed/replaced the statement's
        // previous internal result before returning, so dropping our old
        // metadata alias here is safe — it's not "still needed internally"
        // at this point, unlike mid-fetch.
        $this->resultMetadataResource = null;
        $this->preparedResultBound = false;
        $this->hasPrefetchedRow = false;
        $this->fetchBuffer = [];
        $this->externalColumnBindings = [];

        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $mode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        if ($this->isPrepared) {
            $row = $this->fetchPreparedRow();

            if ($row === null) {
                return false;
            }

            return $this->buildFetchResult($row, $this->columnNames, $mode);
        }

        return match ($mode) {
            PDO::FETCH_ASSOC => @sasql_fetch_assoc($this->result),
            PDO::FETCH_NUM => @sasql_fetch_row($this->result),
            PDO::FETCH_OBJ => @sasql_fetch_object($this->result),
            default => @sasql_fetch_array($this->result, SASQL_BOTH),
        };
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = [];

        while (($row = $this->fetch($mode)) !== false) {
            $rows[] = $row;
        }

        return $rows;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->fetch(PDO::FETCH_NUM);

        if ($row === false) {
            return false;
        }

        return $row[$column] ?? false;
    }

    public function fetchObject(?string $class = 'stdClass', array $constructorArgs = []): object|false
    {
        $assoc = $this->fetch(PDO::FETCH_ASSOC);

        if ($assoc === false) {
            return false;
        }

        $class ??= 'stdClass';

        if ($constructorArgs !== [] && $class !== 'stdClass') {
            $object = new $class(...$constructorArgs);
            foreach ($assoc as $property => $value) {
                $object->$property = $value;
            }

            return $object;
        }

        $object = new $class();
        foreach ($assoc as $property => $value) {
            $object->$property = $value;
        }

        return $object;
    }

    public function rowCount(): int
    {
        if ($this->isPrepared) {
            return (int) @sasql_stmt_affected_rows($this->stmt);
        }

        // sasql_affected_rows() takes the CONNECTION resource, not the
        // result (sqlanywhere.c:2865).
        return (int) @sasql_affected_rows($this->pdo->getConnectionResource());
    }

    public function columnCount(): int
    {
        if ($this->isPrepared) {
            return (int) @sasql_stmt_field_count($this->stmt);
        }

        // sasql_field_count() takes the CONNECTION resource, not the
        // result (sqlanywhere.c:2894).
        return (int) @sasql_field_count($this->pdo->getConnectionResource());
    }

    public function closeCursor(): bool
    {
        if ($this->isPrepared) {
            @sasql_stmt_free_result($this->stmt);
        } elseif (is_resource($this->result)) {
            // sasql_query() returns bool(true), not a resource, for
            // statements with no result set (sqlanywhere.c:2459-2461).
            @sasql_free_result($this->result);
        }

        $this->resultMetadataResource = null;
        $this->preparedResultBound = false;
        $this->fetchBuffer = [];
        $this->externalColumnBindings = [];

        return true;
    }

    public function errorCode(): ?string
    {
        $info = $this->statementErrorInfo();

        return $info->hasError() ? $info->sqlstate : null;
    }

    public function errorInfo(): array
    {
        return $this->statementErrorInfo()->toArray();
    }

    public function setFetchMode(int $mode, mixed ...$args): bool
    {
        $this->fetchMode = $mode;
        $this->fetchModeArgs = $args;

        return true;
    }

    public function getAttribute(int $name): mixed
    {
        return null;
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return false;
    }

    public function getColumnMeta(int $column): array|false
    {
        $names = $this->isPrepared ? $this->resolveColumnNames() : $this->resolveNonPreparedColumnNames();

        if (!array_key_exists($column, $names)) {
            return false;
        }

        return ['name' => $names[$column]];
    }

    public function nextRowset(): bool
    {
        if ($this->isPrepared) {
            return @sasql_stmt_next_result($this->stmt) !== false;
        }

        return @sasql_next_result($this->pdo->getConnectionResource()) !== false;
    }

    public function debugDumpParams(): ?bool
    {
        return null;
    }

    public function getIterator(): \Iterator
    {
        while (($row = $this->fetch()) !== false) {
            yield $row;
        }
    }

    public function __destruct()
    {
        // Drop our metadata alias before tearing down the statement itself
        // — same ordering as execute()/closeCursor(), so we never release
        // it after the stmt has already been closed out from under it.
        $this->resultMetadataResource = null;

        if ($this->isPrepared && isset($this->stmt) && is_resource($this->stmt)) {
            @sasql_stmt_close($this->stmt);
        } elseif (isset($this->result) && is_resource($this->result)) {
            @sasql_free_result($this->result);
        }
    }

    private function resolveSlot(string|int $param): int
    {
        if (is_int($param)) {
            $zeroBased = $param - 1;

            if ($this->paramOrder !== [] && !array_key_exists($zeroBased, $this->paramOrder)) {
                throw new SqlAnywherePdoException(sprintf('SQLSTATE[HY093]: Invalid parameter number: %d', $param));
            }

            return $zeroBased;
        }

        $name = strtolower(ltrim($param, ':'));
        $index = array_search($name, $this->paramOrder, true);

        if ($index === false) {
            throw new SqlAnywherePdoException(sprintf('SQLSTATE[HY093]: Invalid parameter number: %s', $param));
        }

        return $index;
    }

    private function bindBufferedParamsToStatement(): void
    {
        $count = count($this->paramOrder);

        if ($count === 0) {
            return;
        }

        $typeChars = '';
        $values = [];

        for ($slot = 0; $slot < $count; $slot++) {
            if (!array_key_exists($slot, $this->boundParams)) {
                throw new SqlAnywherePdoException(sprintf('SQLSTATE[HY093]: Invalid parameter number: parameter %d was not bound', $slot + 1));
            }

            $bound = $this->boundParams[$slot];
            $type = $bound['type'];
            $typeChars .= TypeMapper::toSasqlTypeChar($type);

            $values[$slot] = match (true) {
                TypeMapper::isLob($type) => '',
                $bound['isRef'] => $bound['ref'],
                default => $bound['value'],
            };
        }

        $callArgs = [$this->stmt, $typeChars];
        foreach ($values as $slot => $value) {
            $callArgs[] = &$values[$slot];
        }

        if (@call_user_func_array('sasql_stmt_bind_param', $callArgs) === false) {
            $this->pdo->fail($this->statementErrorInfo());
        }
    }

    private function sendLongDataForLobParams(): void
    {
        foreach ($this->boundParams as $slot => $bound) {
            if (!TypeMapper::isLob($bound['type'])) {
                continue;
            }

            $data = $bound['isRef'] ? $bound['ref'] : $bound['value'];

            if (is_resource($data)) {
                while (!feof($data)) {
                    $chunk = fread($data, 65536);

                    if ($chunk === false || $chunk === '') {
                        break;
                    }

                    if (@sasql_stmt_send_long_data($this->stmt, $slot, $chunk) === false) {
                        $this->pdo->fail($this->statementErrorInfo());
                    }
                }

                continue;
            }

            if (@sasql_stmt_send_long_data($this->stmt, $slot, (string) $data) === false) {
                $this->pdo->fail($this->statementErrorInfo());
            }
        }
    }

    private function ensurePreparedResultBound(): void
    {
        if ($this->preparedResultBound) {
            return;
        }

        $this->columnCount = (int) @sasql_stmt_field_count($this->stmt);
        $this->fetchBuffer = array_fill(0, max($this->columnCount, 0), null);

        if ($this->columnCount > 0) {
            $refs = [$this->stmt];
            foreach (range(0, $this->columnCount - 1) as $i) {
                $refs[] = &$this->fetchBuffer[$i];
            }

            @call_user_func_array('sasql_stmt_bind_result', $refs);

            // SQL Anywhere doesn't expose field metadata (names) via
            // sasql_stmt_result_metadata()/sasql_fetch_field() until at
            // least one row has been fetched — confirmed empirically for
            // TOP-limited statements. The buffer is already bound above,
            // so this fetch populates it; fetchPreparedRow() returns this
            // buffered row on its first call instead of fetching again.
            $this->hasPrefetchedRow = (bool) @sasql_stmt_fetch($this->stmt);
        }

        // Kept on $this (not a local var) — letting this resource's
        // refcount hit zero corrupts the statement's internal result.
        $this->resultMetadataResource = @sasql_stmt_result_metadata($this->stmt);
        $this->columnNames = $this->resolveColumnNames();

        $this->preparedResultBound = true;
    }

    /**
     * sasql_stmt_result_metadata() returns a result-like resource; column
     * descriptors are read off it one at a time via sasql_fetch_field(),
     * same as the non-prepared path — confirmed empirically against a live
     * server (returns a stdClass with ->name, ->type, etc per column).
     *
     * @return list<string>
     */
    private function resolveColumnNames(): array
    {
        $names = [];

        if ($this->resultMetadataResource !== false && $this->resultMetadataResource !== null) {
            for ($i = 0; $i < $this->columnCount; $i++) {
                $field = @sasql_fetch_field($this->resultMetadataResource, $i);
                $names[$i] = match (true) {
                    is_object($field) && isset($field->name) => (string) $field->name,
                    is_array($field) && isset($field['name']) => (string) $field['name'],
                    default => (string) $i,
                };
            }
        }

        for ($i = 0; $i < $this->columnCount; $i++) {
            $names[$i] ??= (string) $i;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function resolveNonPreparedColumnNames(): array
    {
        // sasql_field_count() takes the CONNECTION resource, not the
        // result (sqlanywhere.c:2894).
        $count = (int) @sasql_field_count($this->pdo->getConnectionResource());
        $names = [];

        for ($i = 0; $i < $count; $i++) {
            $field = @sasql_fetch_field($this->result, $i);
            $names[$i] = match (true) {
                is_object($field) && isset($field->name) => (string) $field->name,
                is_array($field) && isset($field['name']) => (string) $field['name'],
                default => (string) $i,
            };
        }

        return $names;
    }

    /**
     * @return list<mixed>|null
     */
    private function fetchPreparedRow(): ?array
    {
        $this->ensurePreparedResultBound();

        if ($this->hasPrefetchedRow) {
            $this->hasPrefetchedRow = false;
        } else {
            $fetched = @sasql_stmt_fetch($this->stmt);

            if ($fetched === false || $fetched === null) {
                return null;
            }
        }

        $row = $this->fetchBuffer;

        foreach ($this->externalColumnBindings as $index => &$boundVar) {
            $boundVar = $row[$index] ?? null;
        }

        return $row;
    }

    /**
     * @param list<mixed> $row
     * @param list<string> $columnNames
     */
    private function buildFetchResult(array $row, array $columnNames, int $mode): mixed
    {
        $mode = $mode === PDO::FETCH_DEFAULT ? $this->fetchMode : $mode;

        return match ($mode) {
            PDO::FETCH_NUM => $row,
            PDO::FETCH_ASSOC => $this->combineAssoc($columnNames, $row),
            PDO::FETCH_OBJ, PDO::FETCH_CLASS => (object) $this->combineAssoc($columnNames, $row),
            default => $this->combineAssoc($columnNames, $row) + $row,
        };
    }

    /**
     * @param list<string> $names
     * @param list<mixed> $row
     * @return array<string, mixed>
     */
    private function combineAssoc(array $names, array $row): array
    {
        $assoc = [];

        foreach ($row as $i => $value) {
            $assoc[$names[$i] ?? (string) $i] = $value;
        }

        return $assoc;
    }

    private function statementErrorInfo(): ErrorInfo
    {
        if (!$this->isPrepared) {
            return $this->pdo->connectionErrorInfo();
        }

        $sqlstate = @sasql_stmt_sqlstate($this->stmt);
        $code = @sasql_stmt_errno($this->stmt);
        $message = @sasql_stmt_error($this->stmt);

        if (($sqlstate === '00000' || !$sqlstate) && empty($message)) {
            return ErrorInfo::none();
        }

        return new ErrorInfo($sqlstate ?: 'HY000', $code, $message ?: null);
    }
}
