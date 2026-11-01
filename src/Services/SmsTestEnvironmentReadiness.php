<?php

declare(strict_types=1);

namespace Cmd\Reports\Services;

/** Pure configuration evidence for an authenticated test; no runtime or provider I/O. */
final class SmsTestEnvironmentReadiness
{
    /**
     * Runtime schema/path booleans describe the observing PHP process only.
     * Jobs shape: id, queue, payload, attempts, reserved_at, available_at, created_at.
     * Failed-jobs shape: id, uuid, connection, queue, payload, exception, failed_at.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $runtime
     * @return array<string,mixed>
     */
    public static function report(array $settings, array $runtime): array
    {
        $environment = self::label($settings['app_env'] ?? null);
        $localBypass = $environment === 'local';
        $retryAfter = self::integer($settings['queue_retry_after'] ?? null);
        $queue = $settings['sms_queue'] ?? null;
        $queueConfigured = is_string($queue) && $queue !== '' && $queue === trim($queue)
            && ! preg_match('/[\x00-\x20\x7f,]/', $queue);
        // VerifyCmdSession disables validation only for strict false or APP_ENV=local.
        $authFlagEnabled = ($settings['auth_enabled'] ?? true) !== false;
        $identity = self::url($settings['identity_url'] ?? null, false);
        $origin = self::url($settings['app_url'] ?? null, true);
        $checks = [
            'application_environment_configured' => $environment !== 'unknown',
            'asynchronous_database_queue' => ($settings['queue_connection'] ?? null) === 'database'
                && ($settings['queue_driver'] ?? null) === 'database',
            'queue_reservation_exceeds_job_timeout' => $retryAfter !== null && $retryAfter > SmsExportQueue::JOB_TIMEOUT,
            'sms_queue_configured' => $queueConfigured,
            'jobs_schema_ready' => ($runtime['jobs_schema_ready'] ?? null) === true,
            'failed_jobs_schema_ready' => ($runtime['failed_jobs_schema_ready'] ?? null) === true,
            'authentication_flag_enabled' => $authFlagEnabled,
            'local_authentication_bypass' => $localBypass,
            'authenticated_sessions_enforced' => $environment !== 'unknown' && ! $localBypass && $authFlagEnabled,
            'identity_url_configured' => $identity['configured'],
            'identity_url_https' => $identity['https'],
            'identity_url_suitable_for_environment' => $identity['valid'] && ($localBypass || $identity['https']),
            'app_url_is_origin' => $origin['valid'],
            'app_url_https' => $origin['https'],
            'app_url_suitable_for_environment' => $origin['valid'] && ($localBypass || $origin['https']),
            'private_root_exists' => ($runtime['private_root_exists'] ?? null) === true,
            'private_root_is_directory' => ($runtime['private_root_is_directory'] ?? null) === true,
            'private_root_writable' => ($runtime['private_root_writable'] ?? null) === true,
            'private_root_symlink_free' => ($runtime['private_root_symlink_free'] ?? null) === true,
        ];
        $requirements = [
            'application_environment_configured' => 'Set the application environment explicitly before an authenticated interface test.',
            'asynchronous_database_queue' => 'Use the asynchronous database queue connection and database driver.',
            'queue_reservation_exceeds_job_timeout' => 'The queue reservation must exceed the SMS job timeout of '.SmsExportQueue::JOB_TIMEOUT.' seconds.',
            'sms_queue_configured' => 'Configure one explicit SMS queue for the worker to consume.',
            'jobs_schema_ready' => 'Install the expected jobs table shape on the configured queue database.',
            'failed_jobs_schema_ready' => 'Install the expected failed-jobs table shape on its configured database.',
            'authenticated_sessions_enforced' => $localBypass
                ? 'APP_ENV=local bypasses runner authentication even when the authentication flag is enabled; use a nonlocal environment with session validation for authenticated acceptance.'
                : 'Enable runner session validation before an authenticated interface test.',
            'identity_url_suitable_for_environment' => 'Configure a valid identity base URL using HTTPS for a nonlocal authenticated test.',
            'app_url_suitable_for_environment' => 'Configure the direct application HTTPS origin for nonlocal signed downloads.',
            'private_root_exists' => 'Provision the private SMS storage root before testing the environment.',
            'private_root_is_directory' => 'The private SMS storage root must be a directory.',
            'private_root_writable' => 'The observing PHP process must be able to write the private SMS storage root.',
            'private_root_symlink_free' => 'The private SMS storage root and its ancestors must be free of symlinks.',
        ];
        $blockers = [];
        foreach ($requirements as $code => $message) {
            if (! $checks[$code]) $blockers[] = compact('code', 'message');
        }

        return [
            'scope' => 'authenticated SMS interface test configuration',
            'execution_context' => [
                'php_sapi' => self::label($runtime['php_sapi'] ?? null),
                'app_environment' => $environment,
                'observations_apply_to' => 'the current PHP process only; web and worker configuration may differ',
            ],
            'checks' => $checks,
            'job_timeout_seconds' => SmsExportQueue::JOB_TIMEOUT,
            'queue_retry_after_seconds' => $retryAfter,
            'test_configuration_ready' => $blockers === [],
            'production_ready' => false,
            'blockers' => $blockers,
            'external_verification_required' => [
                'actual worker running and listening to the configured SMS queue',
                'worker timeout, queue retry interval and coordinated worker restart',
                'scheduler running with the intended SMS availability refresh schedule',
                'private storage access by the actual web and worker identities',
                'real test accounts, current session grants and denied actions',
                'controlled acceptance dataset and quantity in the chosen environment; production testing is supported, without authorizing a real campaign',
                'source schema, execution plans and representative scale',
                'matching deployed UI, identity, runner and package revisions',
            ],
            'isolated_fixture_test_scope' => 'Pure, mocked and isolated synthetic integration tests can run independently; archive activation, Upturn handoff and result mappings are not prerequisites for those tests.',
        ];
    }

    /** @return array{configured:bool,valid:bool,https:bool} */
    private static function url(mixed $value, bool $originOnly): array
    {
        $configured = is_string($value) && $value !== '';
        $invalid = ['configured' => $configured, 'valid' => false, 'https' => false];
        if (! $configured || $value !== trim($value) || preg_match('/[\x00-\x20\x7f]/', $value)
            || filter_var($value, FILTER_VALIDATE_URL) === false) return $invalid;
        $parts = parse_url($value);
        if (! is_array($parts) || ! isset($parts['host']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ($originOnly && ! in_array($parts['path'] ?? '', ['', '/'], true))) return $invalid;
        return ['configured' => true, 'valid' => true, 'https' => strtolower($parts['scheme']) === 'https'];
    }

    private static function integer(mixed $value): ?int
    {
        if (is_int($value)) return $value >= 0 ? $value : null;
        if (! is_string($value) || ! preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) return null;
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $integer === false ? null : $integer;
    }

    private static function label(mixed $value): string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $value) ? $value : 'unknown';
    }
}
