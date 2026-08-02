<?php

namespace StaticPHP\Utils\Models\Crypto;

use PDO;

/**
 * The crypto commands, driver agnostic.
 *
 * Every command returns a process exit code and writes through the $out callable rather
 * than echoing, so the whole surface is testable without capturing output buffers.
 */
class Commands
{
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/';
    private const TABLE = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/';

    private ?PDO $pdo;

    /** @var callable */
    private $out;

    /**
     * @access public
     * @param ?PDO     $pdo Null for the commands that touch no database
     * @param callable $out Receives one line at a time, without a trailing newline
     */
    public function __construct(?PDO $pdo, callable $out)
    {
        $this->pdo = $pdo;
        $this->out = $out;
    }

    /**
     * @access private
     * @return PDO
     */
    private function pdo(): PDO
    {
        return $this->pdo ?? throw new CryptoError('This command needs a database connection');
    }

    /**
     * @access private
     * @param  string $line
     * @return void
     */
    private function line(string $line = ''): void
    {
        ($this->out)($line);
    }

    /**
     * Print fresh key material.
     *
     * Printed rather than written anywhere, because the only correct destination is
     * wherever the deployment keeps its secrets, and this command cannot know where that is.
     *
     * @access public
     * @return int
     */
    public function key(): int
    {
        if (extension_loaded('sodium') === false) {
            $this->line('error: ext-sodium is not loaded');

            return 1;
        }

        $this->line(Crypto::generateKey());
        $this->line('');
        $this->line('Put it in the environment variable named by config[\'crypto\'][\'keys\'],');
        $this->line('not in a config file. Keep the previous key listed until:');
        $this->line('  staticphp crypto rotate --table=T --column=C');
        $this->line('reports nothing left under it.');

        return 0;
    }

    /**
     * Re-encrypt a column under the current key.
     *
     * Row by row rather than in one statement, because only php holds the keys - the
     * database cannot rewrite these values itself.
     *
     * Rows already under the current key are read and skipped, so running this twice costs
     * a scan and changes nothing. Values that are not encrypted at all are left alone and
     * counted: this rotates, it does not encrypt a plaintext column for the first time.
     *
     * @access public
     * @param  string $table
     * @param  string $column
     * @param  string $idColumn Primary key to page through
     * @param  int    $batch    Rows read per statement
     * @param  bool   $dryRun   Report what would change, change nothing
     * @return int
     */
    public function rotate(string $table, string $column, string $idColumn, int $batch, bool $dryRun): int
    {
        if (preg_match(self::TABLE, $table) !== 1) {
            $this->line("error: \"{$table}\" is not a plain table name");

            return 2;
        }

        foreach (['--column' => $column, '--id' => $idColumn] as $option => $name) {
            if (preg_match(self::IDENTIFIER, $name) !== 1) {
                $this->line("error: {$option}=\"{$name}\" is not a plain column name");

                return 2;
            }
        }

        if ($batch < 1) {
            $this->line('error: --batch must be at least 1');

            return 2;
        }

        if ($this->pdo === null) {
            $this->line('error: rotate needs a database connection');

            return 2;
        }

        try {
            $current = Crypto::currentKeyId();
        } catch (CryptoError $error) {
            $this->line('error: ' . $error->getMessage());

            return 2;
        }

        $this->line("Rotating {$table}.{$column} onto key \"{$current}\"" . ($dryRun ? ' (--dry-run)' : ''));

        $read = 0;
        $rotated = 0;
        $plaintext = 0;
        $cursor = null;

        // Prepared once and reused for every row, which is the whole reason a rotation over
        // a large table is bearable
        $update = $this->pdo()->prepare("UPDATE {$table} SET {$column} = ? WHERE {$idColumn} = ?");

        while (true) {
            try {
                $rows = $this->page($table, $column, $idColumn, $batch, $cursor);
            } catch (\Throwable $exception) {
                $this->line("error: cannot read {$table}: {$exception->getMessage()}");

                return 1;
            }

            if ($rows === []) {
                break;
            }

            foreach ($rows as $row) {
                $id = $row['id'];
                $cursor = $id;
                $read++;

                $value = (is_string($row['value']) ? $row['value'] : null);
                if ($value === null || $value === '') {
                    continue;
                }

                $keyId = Crypto::keyIdOf($value);
                if ($keyId === null) {
                    $plaintext++;

                    continue;
                }

                if ($keyId === $current) {
                    continue;
                }

                $rotated++;

                if ($dryRun === true) {
                    continue;
                }

                try {
                    $rewritten = Crypto::encrypt((string) Crypto::decrypt($value), $current);
                } catch (CryptoError $error) {
                    $label = (is_scalar($id) ? (string) $id : '?');
                    $this->line("error: {$idColumn} {$label}: {$error->getMessage()}");

                    return 1;
                }

                $update->execute([$rewritten, $id]);
            }
        }

        $this->line("Read {$read} rows.");

        if ($plaintext > 0) {
            $this->line("{$plaintext} were not encrypted and were left alone.");
        }

        $this->line(
            $dryRun === true
                ? "{$rotated} would be re-encrypted."
                : "{$rotated} re-encrypted."
        );

        return 0;
    }

    /**
     * One page of rows, keyed past $cursor.
     *
     * Paged on the primary key rather than with OFFSET, so rewriting rows as it goes does
     * not shift the window underneath the next read.
     *
     * @access private
     * @param  string $table
     * @param  string $column
     * @param  string $idColumn
     * @param  int    $batch
     * @param  mixed  $cursor
     * @return list<array{id: mixed, value: mixed}>
     */
    private function page(string $table, string $column, string $idColumn, int $batch, mixed $cursor): array
    {
        $where = ($cursor === null ? '' : " WHERE {$idColumn} > ?");
        $statement = $this->pdo()->prepare(
            "SELECT {$idColumn} AS id, {$column} AS value FROM {$table}{$where}"
            . " ORDER BY {$idColumn} LIMIT {$batch}"
        );
        $statement->execute($cursor === null ? [] : [$cursor]);

        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $rows[] = ['id' => $row['id'] ?? null, 'value' => $row['value'] ?? null];
        }

        return $rows;
    }
}
