<?php

declare(strict_types=1);

// Explicitly requested SSH/.env alternative: read-only Azure management discovery.
// Only OAuth token issuance and ARM GETs; no role changes, restore, SQL or billable writes.
$options = getopt('', ['root:', 'confirm-read-only-management']);
if (! isset($options['confirm-read-only-management'])) {
    fwrite(STDERR, "Pass --confirm-read-only-management. This tool cannot restore or change Azure resources.\n");
    exit(2);
}
$root = rtrim((string) ($options['root'] ?? getcwd()), '/\\');
require $root.'/vendor/autoload.php';
$env = Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
$client = new GuzzleHttp\Client(['timeout' => 15, 'connect_timeout' => 5,
    'http_errors' => false, 'allow_redirects' => false, 'verify' => true]);
$profiles = ['GRAPH' => ['GRAPH_TENANT_ID', 'GRAPH_CLIENT_ID', 'GRAPH_CLIENT_SECRET'],
    'MS' => ['MS_TENANT_ID', 'MS_CLIENT_ID', 'MS_CLIENT_SECRET']];
$seen = [];
$guid = '/\A[0-9a-fA-F]{8}(?:-[0-9a-fA-F]{4}){3}-[0-9a-fA-F]{12}\z/D';
foreach ($profiles as $label => [$tenantKey, $clientKey, $secretKey]) {
    $report = ['profile' => $label, 'read_only' => true];
    $token = null;
    try {
        $tenant = $env[$tenantKey] ?? '';
        $app = $env[$clientKey] ?? '';
        if (! preg_match($guid, $tenant) || ! preg_match($guid, $app) || empty($env[$secretKey])) {
            $report['result'] = 'Identity configuration incomplete; no request made.';
            continue;
        }
        $identity = hash('sha256', $tenant.'|'.$app);
        if (isset($seen[$identity])) { $report['result'] = 'Same application as an already checked profile.'; continue; }
        $seen[$identity] = true;
        $response = $client->post('https://login.microsoftonline.com/'.$tenant.'/oauth2/v2.0/token',
            ['form_params' => ['client_id' => $app, 'client_secret' => $env[$secretKey],
                'scope' => 'https://management.azure.com/.default', 'grant_type' => 'client_credentials']]);
        $report['token_http'] = $response->getStatusCode();
        $auth = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        if ($report['token_http'] !== 200 || empty($auth['access_token'])) {
            $report['result'] = 'Azure management authentication failed; token response is not printed.';
            continue;
        }
        $token = $auth['access_token'];
        unset($auth, $response);
        $get = static function (string $path) use ($client, $token): array {
            if (! str_starts_with($path, '/subscriptions')) throw new RuntimeException('Unexpected management path.');
            $response = $client->get('https://management.azure.com'.$path,
                ['headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']]);
            $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            return [$response->getStatusCode(), $body];
        };
        [$status, $body] = $get('/subscriptions?api-version=2022-12-01');
        $report['subscriptions_http'] = $status;
        $subscriptions = $body['value'] ?? [];
        $report['discoverable_subscriptions'] = count($subscriptions);
        $report['subscription_scan_limited'] = count($subscriptions) > 10 || ! empty($body['nextLink']);
        if ($status !== 200) { $report['result'] = 'Subscription discovery not authorized or unavailable.'; continue; }
        $report['target_found'] = false;
        foreach (array_slice($subscriptions, 0, 10) as $subscription) {
            $subscriptionId = $subscription['subscriptionId'] ?? '';
            if (! preg_match($guid, $subscriptionId)) continue;
            [$status, $servers] = $get('/subscriptions/'.$subscriptionId.'/providers/Microsoft.Sql/servers?api-version=2023-08-01');
            if ($status !== 200 || ! empty($servers['nextLink'])) $report['server_discovery_incomplete'] = true;
            if ($status !== 200) continue;
            foreach ($servers['value'] ?? [] as $server) {
                if (strcasecmp($server['name'] ?? '', 'serverlibertydebtrelief') !== 0) continue;
                $resource = $server['id'] ?? '';
                if (! preg_match('~\A/subscriptions/[0-9a-f-]+/resourceGroups/[^/?#]+/providers/Microsoft\.Sql/servers/serverlibertydebtrelief\z~iD', $resource)) throw new RuntimeException('Unexpected target resource identity.');
                $report['target_found'] = true;
                $report['server_resource_id'] = $resource;
                [$status, $database] = $get($resource.'/databases/CommissionDatabase?api-version=2023-08-01');
                $report['database_read_http'] = $status;
                if ($status === 200) {
                    $report['earliest_restore_utc'] = $database['properties']['earliestRestoreDate'] ?? null;
                    $report['database_status'] = $database['properties']['status'] ?? null;
                }
                [$status, $permissions] = $get($resource.'/providers/Microsoft.Authorization/permissions?api-version=2022-04-01');
                $report['server_permissions_http'] = $status;
                if ($status === 200) {
                    $listed = false;
                    $matches = static fn (string $pattern): bool => (bool) preg_match('~\A'.str_replace('\\*', '.*', preg_quote($pattern, '~')).'\z~iD', 'Microsoft.Sql/servers/databases/write');
                    foreach ($permissions['value'] ?? [] as $permission) {
                        $allowed = array_filter($permission['actions'] ?? [], $matches);
                        $excepted = array_filter($permission['notActions'] ?? [], $matches);
                        if ($allowed && ! $excepted) $listed = true;
                    }
                    $report['database_create_write_action_listed'] = $listed;
                    $report['warning'] = 'Listed RBAC action is not restore approval; policy, locks, scope and restore prerequisites still apply.';
                }
                break 2;
            }
        }
        $report['result'] = $report['target_found'] ? 'Target metadata checked; no resource changes.'
            : 'Target not discovered. This does not prove absence of narrowly scoped access; an Azure resource ID may be needed.';
    } catch (Throwable $error) {
        $report['result'] = 'Read-only probe failed; exception, HTTP bodies and credentials are not printed.';
        $report['error_type'] = get_class($error);
    } finally {
        unset($token, $get, $auth, $body, $servers, $permissions);
        echo json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
