<?php
/** Diagnostic only: use the existing isolated check-in adapter and real handler. */
require dirname(__DIR__, 2) . '/tests/checkin-workflow.php';

$settings = new \HeartHub\EventRegistrations\Settings();
$settings->enabled = 'true';
$repo = new \HeartHub\EventRegistrations\CCT_Repository();
$manager = new \HeartHub\EventRegistrations\Checkin_Manager(
    $settings, $repo, new \HeartHub\EventRegistrations\Audit_Log(),
    new \HeartHub\EventRegistrations\Capacity_Manager(),
    new \HeartHub\EventRegistrations\Public_Page_Theme()
);
$feedback = 'a' . str_repeat("\xF0\x9F\x8C\xB1", 1250);
$GLOBALS['transients'] = [];
$_POST = ['hherm_public_nonce' => 'valid', 'identity' => 'guest@example.test',
    'checkin_step' => 'confirm', 'party_size' => '4', 'feedback' => $feedback, 'feedback_score' => '5'];
$method = new ReflectionMethod($manager, 'process_public_submission');
$message = $method->invoke($manager, 10, 'unicode-token');
$captured = $repo->saved[1];
echo json_encode(['message' => $message, 'submitted_characters' => 1251,
    'submitted_bytes' => strlen($feedback), 'submitted_utf8_valid' => preg_match('//u', $feedback) === 1,
    'captured_bytes' => strlen($captured), 'captured_utf8_valid' => preg_match('//u', $captured) === 1], JSON_PRETTY_PRINT) . "\n";
