<?php
/**
 * webhook_pix.php — Recebe notificações do BASSPAGO quando um PIX é pago.
 *
 * Configure esta URL no painel do BASSPAGO:
 *   PUT /webhook/{chave}  →  webhookUrl: "https://ajude-seven.vercel.app/api/webhook_pix.php"
 *
 * Formato real observado (payload vem direto na raiz, sem wrapper "pix"):
 * {
 *   "endToEndId":      "E1234...",
 *   "valor":           "0.50",
 *   "horario":         "2026-07-26T16:24:54.699Z",
 *   "componentesValor":{ "original": { "valor": "0.50" } },
 *   "txid":            "54c3d1dcfb4fa287624c657d5d4283",
 *   "pagador":         { "nome": "...", "cpf": "..." }
 * }
 *
 * Mantemos compatibilidade com o formato alternativo { "pix": [ {...}, {...} ] },
 * caso o BASSPAGO envie assim em outras situações (ex: múltiplos PIX de uma vez).
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

header('Content-Type: application/json; charset=utf-8');

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$raw = file_get_contents('php://input');

$payload = json_decode($raw, true);

// ── Normaliza os dois formatos possíveis em uma lista de itens PIX ───────────
$itensPix = [];
if (!empty($payload['pix']) && is_array($payload['pix'])) {
    // Formato { "pix": [ {...}, {...} ] }
    $itensPix = $payload['pix'];
} elseif (!empty($payload['txid'])) {
    // Formato real do BASSPAGO: objeto único direto na raiz
    $itensPix = [$payload];
}

if (empty($itensPix)) {
    http_response_code(200); // Responde 200 para não gerar retry desnecessário
    echo json_encode(['ok' => false, 'msg' => 'Nenhum pix no payload']);
    exit;
}

$tracker = new Tracker();
$store   = new TransactionStore();

foreach ($itensPix as $pix) {
    $txid = $pix['txid'] ?? null;
    if (!$txid) continue;

    // Carrega dados da transação salva na criação do QR Code
    try {
        $txData = $store->carregar($txid);
    } catch (Throwable $e) {
        $txData = [];
    }

    // Idempotência: ignora se já processado
    if (($txData['status'] ?? '') === 'paid') continue;

    $data = array_merge($txData, [
        'txid'   => $txid,
        'valor'  => $pix['valor']   ?? ($txData['valor']  ?? '0.00'),
        'paidAt' => isset($pix['horario'])
            ? date('Y-m-d H:i:s', strtotime($pix['horario']))
            : date('Y-m-d H:i:s'),
        'endToEndId' => $pix['endToEndId'] ?? null,
        'nome'       => $pix['pagador']['nome'] ?? ($txData['nome'] ?? ''),
        'document'   => $pix['pagador']['cpf']  ?? ($txData['document'] ?? ''),
    ]);

    $tracker->purchase($data);

    // Marca transação como paga
    try {
        $store->salvar($txid, array_merge($txData, [
            'status' => 'paid',
            'paidAt' => $data['paidAt'],
        ]));
    } catch (Throwable $e) {
        // segue mesmo assim
    }
}

http_response_code(200);
echo json_encode(['ok' => true]);
