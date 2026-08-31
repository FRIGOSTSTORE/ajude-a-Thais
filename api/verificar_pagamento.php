<?php

error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

define('VERSAO_VERIFICADOR', 'v4-arquivo-novo');

if (isset($_GET['ping'])) {
    echo json_encode([
        'ok'      => true,
        'versao'  => VERSAO_VERIFICADOR,
        'arquivo' => basename(__FILE__),
        'php'     => PHP_VERSION,
    ]);
    exit;
}

ob_start();

register_shutdown_function(function () {
    $fatal = error_get_last();
    $tiposFatais = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];

    if ($fatal !== null && in_array($fatal['type'], $tiposFatais, true)) {
        if (ob_get_length() !== false) {
            ob_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'erro' => 'PHP fatal: ' . $fatal['message'],
            'arquivo' => basename((string)$fatal['file']) . ':' . $fatal['line'],
        ]);
    }

    if (ob_get_length() !== false) {
        ob_end_flush();
    }
});

require_once __DIR__ . '/pix_api.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

$txid = preg_replace('/[^a-zA-Z0-9]/', '', $_GET['txid'] ?? '');

if (empty($txid)) {
    http_response_code(400);
    echo json_encode(['erro' => 'txid invalido.']);
    exit;
}

$store    = null;
$txData   = [];
$storeErr = null;

try {
    $store  = new TransactionStore();
    $txData = $store->carregar($txid);
} catch (Throwable $e) {
    $storeErr = $e->getMessage();
    error_log('[verificar_pagamento] Upstash indisponivel: ' . $storeErr);
}

if (($txData['status'] ?? '') === 'paid') {
    echo json_encode(['txid' => $txid, 'status' => 'paid', 'fonte' => 'cache', 'versao' => VERSAO_VERIFICADOR]);
    exit;
}

$statusPsp = null;
$cob       = [];

try {
    $cob       = (new PixApi())->consultarCobranca($txid);
    $statusPsp = strtoupper((string)($cob['status'] ?? ''));
} catch (Throwable $e) {
    error_log('[verificar_pagamento] Falha ao consultar PSP txid=' . $txid . ': ' . $e->getMessage());

    echo json_encode([
        'txid'   => $txid,
        'status' => $txData['status'] ?? 'waiting_paid',
        'aviso'  => 'Nao foi possivel consultar o PSP agora.',
    ]);
    exit;
}

$statusPagos = ['CONCLUIDA', 'CONCLUIDO', 'PAGA', 'PAGO', 'PAID', 'COMPLETED'];
$foiPago     = in_array($statusPsp, $statusPagos, true);

if (!$foiPago && !empty($cob['pix']) && is_array($cob['pix'])) {
    $foiPago = true;
}

if (!$foiPago) {
    echo json_encode([
        'txid'      => $txid,
        'status'    => 'waiting_paid',
        'statusPsp' => $statusPsp,
        'versao'    => VERSAO_VERIFICADOR,
    ]);
    exit;
}

$infoPix = (!empty($cob['pix']) && is_array($cob['pix'])) ? ($cob['pix'][0] ?? []) : [];

$horario = $infoPix['horario'] ?? ($cob['calendario']['criacao'] ?? null);
$paidAt  = $horario ? date('Y-m-d H:i:s', strtotime($horario)) : date('Y-m-d H:i:s');

$valorPago = $infoPix['valor']
    ?? ($cob['valor']['original'] ?? ($txData['valor'] ?? '0.00'));

$dadosPagos = array_merge($txData, [
    'txid'       => $txid,
    'valor'      => $valorPago,
    'paidAt'     => $paidAt,
    'endToEndId' => $infoPix['endToEndId'] ?? ($txData['endToEndId'] ?? null),
    'nome'       => $infoPix['pagador']['nome'] ?? ($txData['nome'] ?? ''),
    'document'   => $infoPix['pagador']['cpf']  ?? ($txData['document'] ?? ''),
    'status'     => 'paid',
]);

$jaDisparado = false;

if ($store !== null) {
    try {
        $store->salvar($txid, $dadosPagos);
    } catch (Throwable $e) {
        error_log('[verificar_pagamento] Falha ao marcar paid no Upstash: ' . $e->getMessage());
    }
}

try {
    (new Tracker())->purchase($dadosPagos);
    $jaDisparado = true;
} catch (Throwable $e) {
    error_log('[verificar_pagamento] Falha ao disparar Purchase/UTMify: ' . $e->getMessage());
}

echo json_encode([
    'txid'       => $txid,
    'status'     => 'paid',
    'fonte'      => 'psp',
    'valor'      => $valorPago,
    'paidAt'     => $paidAt,
    'disparado'  => $jaDisparado,
]);
