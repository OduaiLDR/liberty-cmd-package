<?php

declare(strict_types=1);

use Cmd\Reports\Services\ContactRepair;

require_once dirname(__DIR__) . '/src/Services/ContactRepair.php';

// Standalone on purpose: never load Laravel, application credentials, queues, or sync commands.
$options = getopt('', ['plan:', 'output:', 'apply']);
try {
    if (!isset($options['plan'], $options['output'])) {
        throw new RuntimeException('Usage: php scripts/contact-sync-repair.php --plan=reviewed.json --output=new-private-report.json [--apply]');
    }
    $plan = json_decode(file_get_contents($options['plan']), true, 512, JSON_THROW_ON_ERROR);
    ContactRepair::validatePlan($plan);
    $server = getenv('CONTACT_REPAIR_SQLSERVER_HOST') ?: '';
    $database = getenv('CONTACT_REPAIR_SQLSERVER_DATABASE') ?: '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(,[0-9]{1,5})?$/D', $server) || !preg_match('/^[a-zA-Z0-9_-]+$/D', $database)) {
        throw new RuntimeException('Set explicit CONTACT_REPAIR_SQLSERVER_HOST and CONTACT_REPAIR_SQLSERVER_DATABASE.');
    }
    if (isset($options['apply'])) {
        ContactRepair::assertLocalTarget($server, $database);
    }
    $local = preg_match('/^(127\.0\.0\.1|localhost)(,|$)/D', $server) === 1;
    $dsn = "sqlsrv:Server={$server};Database={$database};Encrypt=yes;TrustServerCertificate=" . ($local ? 'yes' : 'no');
    $pdo = new PDO($dsn, getenv('CONTACT_REPAIR_SQLSERVER_USER') ?: '', getenv('CONTACT_REPAIR_SQLSERVER_PASSWORD') ?: '');
    $repair = new ContactRepair($pdo, $server, $database);
    $report = [
        'generated_at' => gmdate(DATE_ATOM), 'mode' => isset($options['apply']) ? 'local_apply_pending' : 'select_only',
        'plan_sha256' => hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR)),
        'plan' => $plan, 'preview' => $repair->preview($plan),
    ];
    // Exclusive creation preserves the reviewed plan/before-image before any local write.
    $journal = fopen($options['output'], 'x');
    if ($journal === false) {
        throw new RuntimeException('Report must be a new writable private file.');
    }
    $encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($journal, $encoded) !== strlen($encoded) || !fflush($journal)) {
        throw new RuntimeException('Cannot preserve before-image; no repair was applied.');
    }
    fclose($journal);
    if (isset($options['apply'])) {
        $result = $repair->applyLocal($plan);
        echo json_encode(['applied_rows' => $result['applied_rows'], 'report' => $options['output']], JSON_THROW_ON_ERROR) . "\n";
    } else {
        echo json_encode(['mode' => 'select_only', 'report' => $options['output'], 'statuses' => array_column($report['preview']['rows'], 'status')], JSON_THROW_ON_ERROR) . "\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
