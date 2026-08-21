<?php
/**
 * verificar_pagamento.php
 * Retorna o status de uma transação PIX pelo txid.
 *
 * GET /verificar_pagamento.php?txid={txid}
 *
 * Resposta:
 *   { "status": "waiting_paid" }  ou  { "status": "paid" }
 *
 * IMPORTANTE: este endpoint é somente leitura. Ele NUNCA consulta o PSP nem
 * dispara eventos de compra (Facebook/UTMify) — isso é feito exclusivamente
 * por webhook_pix.php, chamado pelo BASSPAGO quando o PIX é pago de fato.
 *
 * Antes, este arquivo também confirmava o pagamento e disparava
 * Tracker::purchase() por conta própria a cada poll do navegador (a cada
 * 5s). Isso criava uma segunda rota, concorrente com o webhook, mandando
 * "paid" para a UTMify para o mesmo orderId. Como a UTMify atualiza o
 * pedido pelo orderId, a última chamada que chegava vencia — e se o poll do
 * navegador disparasse com os dados de UTM ainda incompletos (por exemplo,
 * por uma leitura do Upstash feita antes do registro terminar de salvar),
 * ele sobrescrevia a campanha correta que o webhook já tinha enviado.
 * Reduzir este endpoint a uma simples leitura elimina essa corrida.
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
    error_log('[verificar_pagamento] Upstash indisponivel: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['erro' => 'Erro ao consultar transação.']);
    exit;
}

if (empty($data)) {
    // Ainda não existe registro (ex.: PIX acabou de ser criado, ou a
    // gravação inicial no Upstash falhou). Não é erro do ponto de vista do
    // checkout — o webhook ainda vai confirmar quando o PIX for pago.
    echo json_encode(['txid' => $txid, 'status' => 'waiting_paid']);
    exit;
}

$status = $data['status'] ?? 'waiting_paid';

echo json_encode(['txid' => $txid, 'status' => $status]);
