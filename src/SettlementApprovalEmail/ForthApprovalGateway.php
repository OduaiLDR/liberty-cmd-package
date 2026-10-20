<?php

declare(strict_types=1);

namespace Cmd\Reports\SettlementApprovalEmail;

use Closure;
use GuzzleHttp\Client;
use RuntimeException;

final class ForthApprovalGateway implements ApprovalGateway
{
    private array $keys = [];
    private array $catalogs = [];

    /** $apiKey reads existing TblAPIKeys, without minting/rotating credentials. */
    public function __construct(private Client $http, private Closure $apiKey, private array $noteSettings) {}

    public function offer(ApprovalEmail $email): ?array
    {
        return $this->request($email, 'GET', '/settlement_offers/' . $email->settlementId, allowMissing: true);
    }

    public function statusId(ApprovalEmail $email, string $label): string
    {
        $catalog = $this->catalogs[$email->company] ??= $this->request($email, 'GET', '/settlement_offer_status');
        $matches = [];
        foreach ($this->statusRows($catalog) as $row) {
            if (!is_array($row)) throw new RuntimeException('Unexpected Forth status catalog format.');
            $name = $row['status_name'] ?? $row['title'] ?? null;
            if (!is_string($name) || trim($name) === '') throw new RuntimeException('Unexpected Forth status catalog format.');
            if (strcasecmp(trim($name), $label) !== 0) continue;
            $id = (string) ($row['id'] ?? '');
            if (!ctype_digit($id) || (int) $id < 1) throw new RuntimeException('Invalid Forth status ID.');
            $matches[$id] = true;
        }
        if (count($matches) !== 1) throw new RuntimeException('Forth target status is missing or ambiguous; no update allowed.');
        return (string) array_key_first($matches);
    }

    private function statusRows(array $catalog): array
    {
        if (array_is_list($catalog)) return $catalog;
        // The live catalog groups names under system_statuses and custom_statuses.
        // Workflow/event metadata is not a source of settlement status IDs.
        $system = $catalog['system_statuses'] ?? null;
        $custom = $catalog['custom_statuses'] ?? null;
        if (!is_array($system) || !array_is_list($system) || !is_array($custom) || !array_is_list($custom)) {
            throw new RuntimeException('Unexpected Forth status catalog format.');
        }
        return array_merge($system, $custom);
    }

    public function assertReady(ApprovalEmail $email): void
    {
        $settings = $this->noteSettings[$email->company] ?? [];
        if (!ctype_digit((string) ($settings['type'] ?? '')) || (int) $settings['type'] < 1
            || !is_bool($settings['public'] ?? null)) {
            throw new RuntimeException('Configure the company note type and explicit note visibility before live processing.');
        }
    }

    public function updateStatus(ApprovalEmail $email, string $statusId): void
    {
        if (!ctype_digit($statusId) || (int) $statusId < 1) throw new RuntimeException('Invalid target status.');
        $this->request($email, 'PUT', '/settlement_offers/' . $email->settlementId . '/status', ['status_id' => $statusId]);
    }

    public function addNote(ApprovalEmail $email): void
    {
        $this->assertReady($email);
        $settings = $this->noteSettings[$email->company];
        $note = $this->request($email, 'POST', '/contacts/' . $email->contactId . '/notes', [
            'content' => $email->note(), 'note_type' => (int) $settings['type'],
            'public' => $settings['public'],
        ]);
        $id = $note['id'] ?? $note['note_id'] ?? null;
        if ((!is_int($id) && !is_string($id)) || !ctype_digit((string) $id) || (int) $id < 1) {
            throw new RuntimeException('Forth did not confirm a created note ID; reconcile before repeating.');
        }
    }

    private function request(ApprovalEmail $email, string $method, string $path, ?array $payload = null, bool $allowMissing = false): ?array
    {
        $key = $this->keys[$email->company] ??= ($this->apiKey)($email->company);
        if (!is_string($key) || trim($key) === '') throw new RuntimeException('Forth API key is not configured.');
        $options = [
            'headers' => ['Api-Key' => $key, 'Accept' => 'application/json'],
            'timeout' => 30, 'connect_timeout' => 10, 'http_errors' => false,
            // Do not forward credentials to a redirect location or retry an uncertain mutation.
            'allow_redirects' => false,
        ];
        if ($payload !== null) $options['json'] = $payload;
        try {
            $response = $this->http->request($method, 'https://api.forthcrm.com/v1' . $path, $options);
        } catch (\Throwable) {
            throw new RuntimeException('Forth request failed; mutation outcomes require reconciliation before retry.');
        }
        $httpStatus = $response->getStatusCode();
        if ($allowMissing && $httpStatus === 404) return null;
        if ($httpStatus < 200 || $httpStatus >= 300) throw new RuntimeException('Forth request rejected (HTTP ' . $httpStatus . ').');
        $data = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new RuntimeException('Invalid Forth response.');
        if (array_key_exists('status', $data) && !is_array($data['status'])) throw new RuntimeException('Unexpected Forth operation status.');
        $code = $data['status']['code'] ?? $httpStatus;
        if (!is_numeric($code) || (int) $code < 200 || (int) $code >= 300) throw new RuntimeException('Forth reported an unsuccessful operation.');
        $record = $data['response'] ?? null;
        if (!is_array($record)) throw new RuntimeException('Forth response record is missing.');
        return $record;
    }
}
