<?php

namespace Cmd\Reports\Services;

use PDO;
use RuntimeException;

/** Private, bounded-memory contact aggregation across every source page, before target deletion. */
final class SmsPhoneSnapshotStage
{
    private ?PDO $db = null;
    private string $directory;
    private ?\PDOStatement $find = null;
    private ?\PDOStatement $insert = null;
    private ?\PDOStatement $update = null;
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
                cid TEXT COLLATE BINARY NOT NULL,payload TEXT NOT NULL,
                PRIMARY KEY(source,cid)) WITHOUT ROWID');
            $this->db->beginTransaction();
            $this->find = $this->db->prepare('SELECT payload FROM phones WHERE source=? AND cid=?');
            $this->insert = $this->db->prepare('INSERT INTO phones(source,cid,payload) VALUES(?,?,?)');
            $this->update = $this->db->prepare('UPDATE phones SET payload=? WHERE source=? AND cid=?');
        } catch (\Throwable $error) { $this->close(); throw $error; }
    }

    public function add(string $source, array $row): void
    {
        $metadata = ['cid'=>$row['cid'],'created'=>$row['created'],
            'data_source'=>$row['data_source']??null,'external_id'=>$row['external_id']??null,
            'data_source_id'=>$row['data_source_id']??null,
            'data_source_origin'=>$row['data_source_origin']??null,
            'data_source_map_sha256'=>$row['data_source_map_sha256']??null,
            'status'=>$row['status']??null,'status_id'=>$row['status_id']??null,
            'status_stamp'=>$row['status_stamp']??null];
        $slot = $row['slot'] ?? 0;
        if (! is_int($slot) || $slot < 0 || $slot > 3) throw new RuntimeException('Invalid phone slot; the phone snapshot was not replaced.');
        $key = [$source, $row['cid']];
        $this->find->execute($key);
        $previous = $this->find->fetchColumn();
        $this->find->closeCursor();
        if ($previous !== false) {
            $saved = json_decode($previous, true, 512, JSON_THROW_ON_ERROR);
            if ($saved['metadata'] !== $metadata) throw new RuntimeException('Conflicting phone provenance; the phone snapshot was not replaced.');
            $phones = $saved['phones'];
            $phones[$row['phone']] = min($phones[$row['phone']] ?? 4, $slot);
            if (count($phones) > 4) throw new RuntimeException('More than four unique contact phones; the phone snapshot was not replaced.');
            $this->update->execute([json_encode(['metadata'=>$metadata,'phones'=>$phones], JSON_THROW_ON_ERROR), ...$key]);
            return;
        }
        $payload = json_encode(['metadata'=>$metadata,'phones'=>[$row['phone']=>$slot]], JSON_THROW_ON_ERROR);
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
                $saved = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
                $phones = $saved['phones'];
                // Source slot is authoritative; lexical order only resolves impossible equal-slot source ties deterministically.
                uksort($phones, fn ($left,$right) => $phones[$left] <=> $phones[$right] ?: strcmp((string)$left,(string)$right));
                $numbers = array_map('strval', array_keys($phones));
                $row = [...$saved['metadata'], 'phone'=>$numbers[0], 'phones'=>array_pad($numbers,4,null)];
                $line = json_encode($row, JSON_THROW_ON_ERROR).PHP_EOL;
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
        $this->find = null; $this->insert = null; $this->update = null;
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
