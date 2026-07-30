<?php
/**
 * verificar_pagamento.php
 * Retorna o status de uma transação PIX pelo txid.
 *
 * GET /verificar_pagamento.php?txid={txid}
 *
 * Resposta:
 *   { "status": "waiting_paid" }  ou  { "status": "paid" }
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/transaction_store.php';

// Sanitiza o txid — apenas alfanumérico
$txid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['txid'] ?? '');

if (empty($txid)) {
    http_response_code(400);
    echo json_encode(['erro' => 'txid inválido.']);
    exit;
}

try {
    $data = (new TransactionStore())->carregar($txid);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao consultar transação.']);
    exit;
}

if (empty($data)) {
    http_response_code(404);
    echo json_encode(['erro' => 'Transação não encontrada.']);
    exit;
}

$status = $data['status'] ?? 'waiting_paid';

echo json_encode(['txid' => $txid, 'status' => $status]);
