<?php

declare(strict_types=1);

/**
 * Deterministic local decision model wire fake for executable CLI/HTTP proof only.
 * Never stores state, auth values, or unfiltered response bodies.
 */
if (parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/v1/systemone') {
    http_response_code(404);
    exit;
}

$raw = file_get_contents('php://input');
$request = is_string($raw) ? json_decode($raw, true) : null;
$headers = function_exists('getallheaders') ? getallheaders() : [];
$auth = isset($headers['Authorization']);
$criteria = $request['questions']['decision']['criteria'] ?? null;
$state = $request['state'] ?? null;
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
    || !is_array($criteria)
    || !is_string($state)
    || ($request['questions']['decision']['type'] ?? null) !== 'choice'
    || !isset($criteria['none'])
) {
    http_response_code(400);
    exit;
}

$root = getenv('DECISION_MODEL_FAKE_EVIDENCE');
if (is_string($root) && $root !== '' && is_dir($root)) {
    $record = [
        'method' => $_SERVER['REQUEST_METHOD'],
        'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        'content_type' => $_SERVER['CONTENT_TYPE'] ?? null,
        'authorization_present' => $auth,
        'candidate_ids' => array_keys($criteria),
        'state_sha256' => hash('sha256', $state),
    ];
    file_put_contents($root . '/wire-' . bin2hex(random_bytes(6)) . '.json', json_encode($record, JSON_THROW_ON_ERROR));
}

if (str_starts_with($state, 'HTTP_ERROR')) {
    http_response_code(503);
    exit;
}
if (str_starts_with($state, 'TIMEOUT')) {
    usleep(1_500_000);
}
header('Content-Type: application/json');
if (str_starts_with($state, 'INVALID')) {
    echo '{"answers":{"decision":{"type":"choice","choice":"invented-specialist","probabilities":{}}}}';
    exit;
}
if (str_starts_with($state, 'STALE')) {
    echo '{"answers":{"decision":{"type":"choice","choice":"agent-loop-investigator","probabilities":{"none":0.1}}}}';
    exit;
}
if (str_starts_with($state, 'NON_FINITE_NAN')) {
    echo '{"answers":{"decision":{"type":"choice","choice":"agent-loop-investigator","probabilities":{"agent-loop-investigator":NaN}}}}';
    exit;
}
if (str_starts_with($state, 'NON_FINITE_INFINITY')) {
    echo '{"answers":{"decision":{"type":"choice","choice":"agent-loop-investigator","probabilities":{"agent-loop-investigator":Infinity}}}}';
    exit;
}
if (str_starts_with($state, 'OVERSIZED')) {
    // The consumer must reject before storing a remote body this large.
    echo str_repeat('X', 65536);
    exit;
}
if (str_starts_with($state, 'OUT_OF_RANGE')) {
    $invalid = array_fill_keys(array_keys($criteria), 0.0);
    $invalid['agent-loop-investigator'] = 1.2;
    echo json_encode([
        'answers' => [
            'decision' => [
                'type' => 'choice',
                'choice' => 'agent-loop-investigator',
                'probabilities' => $invalid,
                'confidence' => 0.7,
            ],
        ],
    ], JSON_THROW_ON_ERROR);
    exit;
}
$choice = str_starts_with($state, 'ABSTAIN') ? 'none' : (isset($criteria['request_changes']) ? 'request_changes' : 'agent-loop-investigator');
if (!array_key_exists($choice, $criteria)) {
    http_response_code(400);
    exit;
}
$probabilities = [];
$other = 0.2 / (count($criteria) - 1);
foreach ($criteria as $id => $description) {
    $probabilities[$id] = $id === $choice ? 0.8 : $other;
}
echo json_encode([
    'model' => 'fake-decision-model-localhost',
    'answers' => [
        'decision' => [
            'type' => 'choice',
            'choice' => $choice,
            'probabilities' => $probabilities,
            'confidence' => str_starts_with($state, 'CONFIDENCE') ? 0.1 : 0.8,
        ],
    ],
], JSON_THROW_ON_ERROR);
