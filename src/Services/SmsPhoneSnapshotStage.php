<?php

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;

/** Private, bounded-memory deduplication across every source page, before target deletion. */
final class SmsPhoneSnapshotStage
{
    private ?PDO $db = null;
    private string $directory;
    private ?\PDOStatement $find = null;
    private ?\PDOStatement $insert = null;
    private int $count = 0;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-phone-'.bin2hex(random_bytes(16));
        if (! mkdir($this->directory, 0700)) throw new RuntimeException('Unable to create private phone staging.');
        try {
            $this->privateFile('snapshot.sqlite');
            $this->db = new PDO('sqlite:'.$this->directory.DIRECTORY_SEPARATOR.'snapshot.sqlite', null, null,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->db->exec('PRAGMA cache_size=-2048; CREATE TABLE phones(source TEXT COLLATE BINARY NOT NULL,
                cid TEXT COLLATE BINARY NOT NULL,phone TEXT COLLATE BINARY NOT NULL,payload TEXT NOT NULL,
                PRIMARY KEY(source,cid,phone)) WITHOUT ROWID');
            $this->db->beginTransaction();
            $this->find = $this->db->prepare('SELECT payload FROM phones WHERE source=? AND cid=? AND phone=?');
            $this->insert = $this->db->prepare('INSERT INTO phones(source,cid,phone,payload) VALUES(?,?,?,?)');
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function add(string $source, array $row): void
    {
        $payload = json_encode($row, JSON_THROW_ON_ERROR);
        $key = [$source, $row['cid'], $row['phone']];
        $this->find->execute($key);
        $previous = $this->find->fetchColumn();
        $this->find->closeCursor();
        if ($previous !== false) {
            if ($previous !== $payload) throw new RuntimeException('Conflicting phone provenance; the phone snapshot was not replaced.');
            return;
        }
        $this->insert->execute([...$key, $payload]);
        ++$this->count;
    }

    public function count(): int { return $this->count; }

    public function export(): string
    {
        $this->db->commit();
        $path = $this->privateFile('phones.jsonl');
        $handle = fopen($path, 'wb');
        if ($handle === false) throw new RuntimeException('Unable to open private phone staging.');
        try {
            $rows = $this->db->query('SELECT payload FROM phones');
            while (($payload = $rows->fetchColumn()) !== false) {
                $line = $payload.PHP_EOL;
                if (fwrite($handle, $line) !== strlen($line)) throw new RuntimeException('Unable to complete private phone staging.');
            }
            if (! fflush($handle)) throw new RuntimeException('Unable to complete private phone staging.');
        } finally { fclose($handle); }
        return $path;
    }

    private function privateFile(string $name): string
    {
        $path = $this->directory.DIRECTORY_SEPARATOR.$name;
        $handle = fopen($path, 'x+b');
        if ($handle === false) throw new RuntimeException('Unable to create private phone staging file.');
        fclose($handle);
        if (! chmod($path, 0600)) throw new RuntimeException('Unable to restrict private phone staging file.');
        return $path;
    }

    public function close(): void
    {
        $this->find = null; $this->insert = null;
        if ($this->db?->inTransaction()) $this->db->rollBack();
        $this->db = null;
        foreach (['phones.jsonl', 'snapshot.sqlite-journal', 'snapshot.sqlite-wal', 'snapshot.sqlite-shm', 'snapshot.sqlite'] as $name) {
            $path = $this->directory.DIRECTORY_SEPARATOR.$name;
            if (is_file($path)) @unlink($path);
        }
        if (is_dir($this->directory)) @rmdir($this->directory);
    }

    public function __destruct() { $this->close(); }
}
