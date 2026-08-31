<?php
/** Recebe webhooks da FlevoPay e marca a transação como paga. */
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';
require_once __DIR__ . '/var.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'msg' => 'Método não permitido']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'msg' => 'Payload inválido']);
    exit;
}

$txid = (string)($payload['external_id'] ?? $payload['id'] ?? '');
$status = strtolower((string)($payload['status'] ?? ''));

if ($txid === '' || $status === '') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

$tracker = new Tracker();
$store   = new TransactionStore();

try {
    $txData = $store->carregar($txid);
} catch (Throwable $e) {
    error_log('[webhook_pix] Falha ao carregar transação: ' . $e->getMessage());
    $txData = [];
}

if (empty($txData)) {
    error_log('[webhook_pix] Transação não encontrada: ' . $txid);
    http_response_code(200);
    echo json_encode(['ok' => true, 'ignored' => true]);
    exit;
}

if (($txData['status'] ?? '') === 'paid') {
    http_response_code(200);
    echo json_encode(['ok' => true, 'already_processed' => true]);
    exit;
}

$paidStatuses = ['approved', 'paid', 'completed', 'concluida', 'concluido'];
if (!in_array($status, $paidStatuses, true)) {
    http_response_code(200);
    echo json_encode(['ok' => true, 'status' => $status]);
    exit;
}

$customer = is_array($payload['customer'] ?? null) ? $payload['customer'] : [];
$paidAtRaw = $payload['timestamp'] ?? $payload['updated_at'] ?? null;
$paidAt = $paidAtRaw ? date('Y-m-d H:i:s', strtotime($paidAtRaw)) : date('Y-m-d H:i:s');

$data = array_merge($txData, [
    'txid'       => $txid,
    'valor'      => isset($payload['amount']) ? ((float)$payload['amount'] / 100) : ($txData['valor'] ?? '0.00'),
    'paidAt'     => $paidAt,
    'endToEndId' => $payload['endToEndId'] ?? ($payload['transaction_id'] ?? null),
    'nome'       => $customer['name'] ?? ($txData['nome'] ?? ''),
    'document'   => $customer['document'] ?? ($txData['document'] ?? ''),
    'status'     => 'paid',
]);

try {
    $tracker->purchase($data);
} catch (Throwable $e) {
    error_log('[webhook_pix] Falha no tracker: ' . $e->getMessage());
}

try {
    $store->salvar($txid, $data);
} catch (Throwable $e) {
    error_log('[webhook_pix] Falha ao salvar status paid: ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true, 'status' => 'paid']);
