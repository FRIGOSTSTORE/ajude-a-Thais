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
​
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
​
header('Content-Type: application/json; charset=utf-8');
​
ini_set('display_errors', '0');
ob_start();
register_shutdown_function(function () {
    $fatal = error_get_last();
    $tiposFatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if ($fatal !== null && in_array($fatal['type'], $tiposFatais, true)) {
        if (ob_get_length() !== false) { ob_clean(); }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'erro' => 'PHP fatal: ' . $fatal['message'],
            'arquivo' => basename((string)$fatal['file']) . ':' . $fatal['line'],
        ], JSON_UNESCAPED_UNICODE);
    }
    if (ob_get_length() !== false) { ob_end_flush(); }
});
​
require_once __DIR__ . '/transaction_store.php';
​
// Sanitiza o txid — apenas alfanumérico
$txid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['txid'] ?? '');
​
if (empty($txid)) {
    http_response_code(400);
    echo json_encode(['erro' => 'txid inválido.']);
    exit;
}
​
try {
    $data = (new TransactionStore())->carregar($txid);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao consultar transação.']);
    exit;
}
​
if (empty($data)) {
    http_response_code(404);
    echo json_encode(['erro' => 'Transação não encontrada.']);
    exit;
}
​
$status = $data['status'] ?? 'waiting_paid';
​
echo json_encode(['txid' => $txid, 'status' => $status]);
