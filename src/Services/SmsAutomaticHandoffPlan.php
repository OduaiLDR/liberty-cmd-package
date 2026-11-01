<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

use RuntimeException;
use Throwable;

/** Metadata-only approval snapshot. No files, credentials or recipient-table lookup. */
final class SmsAutomaticHandoffPlan
{
    public static function build(object $run, SmsRunLinkPublisher $publisher): array
    {
        try {
            $id = strtolower((string) ($run->Request_ID ?? ''));
            if (!preg_match('/\A[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}\z/D', $id)
                || ($run->Status ?? null) !== 'approved' || !SmsRecordPolicy::isRecord($run)
                || empty($run->Approved_At) || !filter_var($run->Approved_By ?? null, FILTER_VALIDATE_EMAIL)
                || !empty($run->File_Cleanup_Path) || (int) ($run->Selected ?? 0) < 1) throw new RuntimeException;
            $options = SmsRunOptions::fromStored($run->Run_Options_JSON, (int) $run->Target);
            if (($options['options_version'] ?? null) !== SmsRunOptions::PLANNED_DATE_VERSION
                || ($options['filename_version'] ?? null) !== SmsRunOptions::APPROVAL_FILENAME_VERSION) throw new RuntimeException;
            $manifest = json_decode($run->Artifact_Manifest_JSON, true, 32, JSON_THROW_ON_ERROR);
            (new SmsRunArtifactBundle('v2'))->validateManifest($manifest, $id, $options, (int) $run->Selected);
            $root = config('sms-exports.local_root');
            if (!is_string($root) || $root === '' || preg_match('/[\x00\r\n]/', $root)) throw new RuntimeException;
            $path = str_replace('\\', '/', rtrim($root, '/\\')).'/runs/'.$id.'/v2/published/'.$manifest['download']['name'];
            if ($run->File_Path !== $path || (int) $run->File_Bytes !== $manifest['download']['bytes']
                || $run->File_Sha256 !== $manifest['download']['sha256']) throw new RuntimeException;
            $identity = $publisher->identity(); $policy = $publisher->policy($options['send_date']);
            if ($identity === '' || strlen($identity) > 255 || preg_match('/[^\x21-\x7e]/', $identity)
                || $policy === [] || strlen(self::json($policy)) > 65536) throw new RuntimeException;
            $scope = ['source' => config('sms-exports.email_report_source'), 'report' => config('sms-exports.email_report_name'),
                'company' => config('sms-exports.email_report_company'), 'hosts' => config('sms-exports.email_allowed_link_hosts')];
            if (!in_array($scope['source'], ['ldr', 'plaw'], true)) throw new RuntimeException;
            foreach (['report', 'company'] as $key) {
                if (!is_string($scope[$key]) || $scope[$key] === '' || $scope[$key] !== trim($scope[$key])
                    || strlen($scope[$key]) > 128 || preg_match('/[\x00-\x1f\x7f]/', $scope[$key])) throw new RuntimeException;
            }
            if (!is_array($scope['hosts']) || !array_is_list($scope['hosts']) || $scope['hosts'] === []) throw new RuntimeException;
            foreach ($scope['hosts'] as $host) {
                if (!is_string($host) || strlen($host) > 253 || !preg_match('/\A[a-z0-9]+(?:[.-][a-z0-9]+)*\.[a-z][a-z0-9-]*\z/D', $host)
                    || filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException;
            }
            return ['version' => 1, 'request_id' => $id, 'run_sha256' => self::fingerprint($run),
                'actor' => $run->Approved_By, 'send_date' => $options['send_date'],
                'publication' => ['identity' => $identity, 'policy' => $policy], 'email_scope' => $scope];
        } catch (Throwable) { throw new RuntimeException('The automatic SMS handoff context could not be confirmed.', 409); }
    }

    public static function fingerprint(object $run): string
    {
        $values = [];
        foreach (['Request_ID', 'Status', 'Selection_Policy', 'Target', 'Selected', 'Approved_By', 'Approved_At', 'Sent_By', 'Sent_At',
            'Run_Options_JSON', 'Artifact_Manifest_JSON', 'File_Path', 'File_Bytes', 'File_Sha256', 'File_Cleanup_Path'] as $key) $values[$key] = $run->$key ?? null;
        return hash('sha256', self::json($values));
    }

    public static function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
