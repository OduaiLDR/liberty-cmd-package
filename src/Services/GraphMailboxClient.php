<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/**
 * Reads an Exchange mailbox through Microsoft Graph — the counterpart of EmailSenderService, which
 * only sends. Built for the inbox-triggered automations that CMD LDR.xlsm ran as Outlook rules
 * (find a message by subject, save its attachment, mark it read, move it to Inbox\Archive).
 *
 * Same app registration and client-credentials token as EmailSenderService (GRAPH_TENANT_ID /
 * GRAPH_CLIENT_ID / GRAPH_CLIENT_SECRET). Reading needs the application permission
 * `Mail.ReadWrite` on the mailboxes involved — sending alone (`Mail.Send`) is not enough, and
 * Graph answers 403 `ErrorAccessDenied` until it is granted.
 */
class GraphMailboxClient
{
    private const GRAPH = 'https://graph.microsoft.com/v1.0';

    private ?string $token = null;

    public function __construct(
        private readonly string $tenantId,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private ?Client $http = null,
    ) {
        $this->http ??= new Client(['timeout' => 60]);
    }

    /**
     * Credentials from `<prefix>_TENANT_ID` / `<prefix>_CLIENT_ID` / `<prefix>_CLIENT_SECRET`. The
     * default prefix is the Liberty tenant's `GRAPH_*` (shared with EmailSenderService); a mailbox in
     * another tenant — Lending Tower is one — has its own app registration under its own prefix,
     * e.g. `GRAPH_LT_*`.
     */
    public static function fromEnvironment(string $prefix = 'GRAPH'): self
    {
        if (!self::isConfigured($prefix)) {
            throw new RuntimeException("Microsoft Graph is not configured: set {$prefix}_TENANT_ID, {$prefix}_CLIENT_ID and {$prefix}_CLIENT_SECRET in .env.");
        }

        return new self(
            (string) env("{$prefix}_TENANT_ID", ''),
            (string) env("{$prefix}_CLIENT_ID", ''),
            (string) env("{$prefix}_CLIENT_SECRET", ''),
        );
    }

    public static function isConfigured(string $prefix = 'GRAPH'): bool
    {
        foreach (['TENANT_ID', 'CLIENT_ID', 'CLIENT_SECRET'] as $key) {
            if (trim((string) env("{$prefix}_{$key}", '')) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Messages in the mailbox's Inbox, oldest first, optionally only those whose subject starts
     * with $subjectPrefix (case-insensitive; Graph's own filter is case-insensitive too, but the
     * check is repeated here so a mailbox that ignores the filter cannot slip other mail through).
     *
     * @return list<array{id: string, subject: string, receivedDateTime: string, hasAttachments: bool, isRead: bool, from: string}>
     */
    public function listInboxMessages(string $mailbox, ?string $subjectPrefix = null, int $top = 50): array
    {
        // No $orderby: Graph rejects a $filter on one property ordered by another (InefficientFilter),
        // so the oldest-first order is applied below instead.
        $query = [
            '$select' => 'id,subject,receivedDateTime,hasAttachments,isRead,from',
            '$top' => (string) $top,
        ];
        if ($subjectPrefix !== null && $subjectPrefix !== '') {
            $query['$filter'] = "startswith(subject,'" . str_replace("'", "''", $subjectPrefix) . "')";
        }

        $data = $this->request('GET', "/users/{$this->encode($mailbox)}/mailFolders/inbox/messages", ['query' => $query]);

        $messages = [];
        foreach ($data['value'] ?? [] as $message) {
            $subject = (string) ($message['subject'] ?? '');
            if ($subjectPrefix !== null && $subjectPrefix !== '' && stripos($subject, $subjectPrefix) !== 0) {
                continue;
            }
            $messages[] = [
                'id' => (string) ($message['id'] ?? ''),
                'subject' => $subject,
                'receivedDateTime' => (string) ($message['receivedDateTime'] ?? ''),
                'hasAttachments' => (bool) ($message['hasAttachments'] ?? false),
                'isRead' => (bool) ($message['isRead'] ?? false),
                'from' => (string) ($message['from']['emailAddress']['address'] ?? ''),
            ];
        }
        usort($messages, static fn (array $a, array $b): int => strcmp($a['receivedDateTime'], $b['receivedDateTime']));

        return $messages;
    }

    /**
     * @return list<array{id: string, name: string, contentType: string, size: int}>
     */
    public function listAttachments(string $mailbox, string $messageId): array
    {
        $data = $this->request('GET', "/users/{$this->encode($mailbox)}/messages/{$this->encode($messageId)}/attachments", [
            'query' => ['$select' => 'id,name,contentType,size'],
        ]);

        $attachments = [];
        foreach ($data['value'] ?? [] as $attachment) {
            $attachments[] = [
                'id' => (string) ($attachment['id'] ?? ''),
                'name' => (string) ($attachment['name'] ?? ''),
                'contentType' => (string) ($attachment['contentType'] ?? ''),
                'size' => (int) ($attachment['size'] ?? 0),
            ];
        }

        return $attachments;
    }

    /** Saves an attachment's raw bytes to $path (directories created as needed). */
    public function downloadAttachment(string $mailbox, string $messageId, string $attachmentId, string $path): void
    {
        $bytes = $this->raw('GET', "/users/{$this->encode($mailbox)}/messages/{$this->encode($messageId)}/attachments/{$this->encode($attachmentId)}/\$value");

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}");
        }
        if (file_put_contents($path, $bytes) === false) {
            throw new RuntimeException("Could not write {$path}");
        }
    }

    public function markRead(string $mailbox, string $messageId): void
    {
        $this->request('PATCH', "/users/{$this->encode($mailbox)}/messages/{$this->encode($messageId)}", [
            'json' => ['isRead' => true],
        ]);
    }

    /**
     * Moves a message into a child folder of the Inbox (the VBA's `Inbox\Archive`), creating the
     * folder when the mailbox does not have it yet.
     */
    public function moveToInboxSubfolder(string $mailbox, string $messageId, string $folderName): void
    {
        $folderId = $this->inboxSubfolderId($mailbox, $folderName)
            ?? $this->createInboxSubfolder($mailbox, $folderName);

        $this->request('POST', "/users/{$this->encode($mailbox)}/messages/{$this->encode($messageId)}/move", [
            'json' => ['destinationId' => $folderId],
        ]);
    }

    private function inboxSubfolderId(string $mailbox, string $folderName): ?string
    {
        $data = $this->request('GET', "/users/{$this->encode($mailbox)}/mailFolders/inbox/childFolders", [
            'query' => ['$filter' => "displayName eq '" . str_replace("'", "''", $folderName) . "'", '$select' => 'id,displayName'],
        ]);
        foreach ($data['value'] ?? [] as $folder) {
            if (strcasecmp((string) ($folder['displayName'] ?? ''), $folderName) === 0) {
                return (string) $folder['id'];
            }
        }

        return null;
    }

    private function createInboxSubfolder(string $mailbox, string $folderName): string
    {
        $data = $this->request('POST', "/users/{$this->encode($mailbox)}/mailFolders/inbox/childFolders", [
            'json' => ['displayName' => $folderName],
        ]);
        $id = (string) ($data['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException("Graph did not return an id for the new folder {$folderName} in {$mailbox}");
        }

        return $id;
    }

    /** @return array<string, mixed> decoded JSON body */
    private function request(string $method, string $path, array $options = []): array
    {
        $body = $this->raw($method, $path, $options);
        if ($body === '') {
            return [];
        }
        $data = json_decode($body, true);

        return is_array($data) ? $data : [];
    }

    private function raw(string $method, string $path, array $options = []): string
    {
        $options['headers'] = ($options['headers'] ?? []) + [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept' => 'application/json',
        ];

        try {
            $response = $this->http->request($method, self::GRAPH . $path, $options);
        } catch (GuzzleException $e) {
            throw new RuntimeException("Graph {$method} {$path} failed: " . $e->getMessage(), 0, $e);
        }

        return (string) $response->getBody();
    }

    private function accessToken(): string
    {
        if ($this->token !== null) {
            return $this->token;
        }

        try {
            $response = $this->http->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                'form_params' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope' => 'https://graph.microsoft.com/.default',
                    'grant_type' => 'client_credentials',
                ],
            ]);
        } catch (GuzzleException $e) {
            throw new RuntimeException('Graph token request failed: ' . $e->getMessage(), 0, $e);
        }

        $data = json_decode((string) $response->getBody(), true);
        $token = (string) ($data['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('Graph token response had no access_token.');
        }

        return $this->token = $token;
    }

    private function encode(string $segment): string
    {
        return rawurlencode($segment);
    }
}
