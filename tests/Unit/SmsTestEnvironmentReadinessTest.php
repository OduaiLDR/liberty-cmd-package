<?php

declare(strict_types=1);

use Cmd\Reports\Services\SmsExportQueue;
use Cmd\Reports\Services\SmsTestEnvironmentReadiness;

function smsEnvironmentFixtureSettings(): array
{
    return ['app_env' => 'testing', 'queue_connection' => 'database', 'queue_driver' => 'database',
        'queue_retry_after' => 7500, 'sms_queue' => 'sms-test', 'auth_enabled' => true,
        'identity_url' => 'https://identity.fixture.invalid', 'app_url' => 'https://cmd.fixture.invalid'];
}

function smsEnvironmentFixtureRuntime(): array
{
    return ['php_sapi' => 'cli', 'jobs_schema_ready' => true, 'failed_jobs_schema_ready' => true,
        'private_root_exists' => true, 'private_root_is_directory' => true,
        'private_root_writable' => true, 'private_root_symlink_free' => true];
}

test('test configuration readiness leaves real processes identities data and revisions unverified', function (): void {
    $report = SmsTestEnvironmentReadiness::report(smsEnvironmentFixtureSettings(), smsEnvironmentFixtureRuntime());
    expect($report['test_configuration_ready'])->toBeTrue()->and($report['production_ready'])->toBeFalse()
        ->and($report['blockers'])->toBe([])->and($report['execution_context']['php_sapi'])->toBe('cli')
        ->and($report['checks']['authenticated_sessions_enforced'])->toBeTrue()
        ->and($report['external_verification_required'])->toHaveCount(8)
        ->and(implode(' ', $report['external_verification_required']))->toContain('worker', 'scheduler', 'real test accounts', 'controlled acceptance dataset', 'production testing is supported', 'scale', 'revisions')
        ->and($report['isolated_fixture_test_scope'])->toContain('not prerequisites');
    $settings = smsEnvironmentFixtureSettings();
    $settings['run_archive_enabled'] = false;
    $settings['upturn_results_sample_available'] = false;
    expect(SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime())['test_configuration_ready'])->toBeTrue();
});

test('local authentication bypass cannot count as authenticated acceptance despite an enabled flag', function (): void {
    $settings = smsEnvironmentFixtureSettings(); $settings['app_env'] = 'local';
    $settings['identity_url'] = 'http://127.0.0.1:8787'; $settings['app_url'] = 'http://127.0.0.1:8080';
    $report = SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime());
    expect($report['checks']['authentication_flag_enabled'])->toBeTrue()
        ->and($report['checks']['local_authentication_bypass'])->toBeTrue()
        ->and($report['checks']['authenticated_sessions_enforced'])->toBeFalse()
        ->and($report['checks']['identity_url_suitable_for_environment'])->toBeTrue()
        ->and(array_column($report['blockers'], 'code'))->toBe(['authenticated_sessions_enforced'])
        ->and($report['blockers'][0]['message'])->toContain('APP_ENV=local');
});

test('queue mismatches short reservations and malformed queue names block test configuration', function (array $change, string $blocker): void {
    $settings = array_replace(smsEnvironmentFixtureSettings(), $change);
    $report = SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime());
    expect($report['test_configuration_ready'])->toBeFalse()
        ->and(array_column($report['blockers'], 'code'))->toContain($blocker);
})->with([
    'sync connection' => [['queue_connection' => 'sync'], 'asynchronous_database_queue'],
    'misconfigured database driver' => [['queue_driver' => 'sync'], 'asynchronous_database_queue'],
    'default short reservation' => [['queue_retry_after' => 90], 'queue_reservation_exceeds_job_timeout'],
    'equal timeout' => [['queue_retry_after' => SmsExportQueue::JOB_TIMEOUT], 'queue_reservation_exceeds_job_timeout'],
    'invalid reservation' => [['queue_retry_after' => 'not-a-number'], 'queue_reservation_exceeds_job_timeout'],
    'empty queue' => [['sms_queue' => ''], 'sms_queue_configured'],
    'queue list' => [['sms_queue' => 'default,sms-test'], 'sms_queue_configured'],
]);

test('nonlocal identity and signed download URLs must be appropriate bases and origins', function (array $change, string $blocker): void {
    $settings = array_replace(smsEnvironmentFixtureSettings(), $change);
    $report = SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime());
    expect($report['test_configuration_ready'])->toBeFalse()
        ->and(array_column($report['blockers'], 'code'))->toContain($blocker);
    expect(json_encode($report, JSON_THROW_ON_ERROR))->not->toContain('do-not-print-this');
})->with([
    'identity missing' => [['identity_url' => null], 'identity_url_suitable_for_environment'],
    'identity plain HTTP' => [['identity_url' => 'http://identity.fixture.invalid'], 'identity_url_suitable_for_environment'],
    'identity URL with credentials' => [['identity_url' => 'https://do-not-print-this@identity.fixture.invalid'], 'identity_url_suitable_for_environment'],
    'identity query cannot be appended' => [['identity_url' => 'https://identity.fixture.invalid?token=do-not-print-this'], 'identity_url_suitable_for_environment'],
    'download HTTP' => [['app_url' => 'http://cmd.fixture.invalid'], 'app_url_suitable_for_environment'],
    'download not an origin' => [['app_url' => 'https://cmd.fixture.invalid/nested'], 'app_url_suitable_for_environment'],
    'download fragment' => [['app_url' => 'https://cmd.fixture.invalid/#do-not-print-this'], 'app_url_suitable_for_environment'],
]);

test('missing schema and storage observations fail closed without filesystem or database reads', function (string $runtimeKey, string $blocker): void {
    $runtime = smsEnvironmentFixtureRuntime(); unset($runtime[$runtimeKey]);
    $report = SmsTestEnvironmentReadiness::report(smsEnvironmentFixtureSettings(), $runtime);
    expect($report['test_configuration_ready'])->toBeFalse()
        ->and(array_column($report['blockers'], 'code'))->toBe([$blocker]);
})->with([
    ['jobs_schema_ready', 'jobs_schema_ready'], ['failed_jobs_schema_ready', 'failed_jobs_schema_ready'],
    ['private_root_exists', 'private_root_exists'], ['private_root_is_directory', 'private_root_is_directory'],
    ['private_root_writable', 'private_root_writable'], ['private_root_symlink_free', 'private_root_symlink_free'],
]);

test('explicitly disabled auth and missing environment block authenticated acceptance', function (): void {
    $settings = smsEnvironmentFixtureSettings(); $settings['auth_enabled'] = false;
    expect(array_column(SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime())['blockers'], 'code'))
        ->toContain('authenticated_sessions_enforced');
    unset($settings['app_env']);
    $report = SmsTestEnvironmentReadiness::report($settings, smsEnvironmentFixtureRuntime());
    expect($report['checks']['application_environment_configured'])->toBeFalse()
        ->and($report['checks']['authenticated_sessions_enforced'])->toBeFalse();
});

test('numeric configuration and alternate PHP execution contexts do not imply web or worker verification', function (): void {
    $settings = smsEnvironmentFixtureSettings(); $settings['queue_retry_after'] = '7500';
    $settings['identity_url'] = 'https://identity.fixture.invalid/base'; $settings['app_url'] .= '/';
    $runtime = smsEnvironmentFixtureRuntime(); $runtime['php_sapi'] = 'fpm-fcgi';
    $report = SmsTestEnvironmentReadiness::report($settings, $runtime);
    expect($report['test_configuration_ready'])->toBeTrue()->and($report['queue_retry_after_seconds'])->toBe(7500)
        ->and($report['execution_context']['php_sapi'])->toBe('fpm-fcgi')
        ->and($report['execution_context']['observations_apply_to'])->toContain('current PHP process only');
});
