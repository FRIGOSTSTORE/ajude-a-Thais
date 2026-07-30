<?php
/**
 * configurar_webhook.php
 * Execute UMA VEZ para registrar o webhook no BASSPAGO.
 * Depois pode deletar ou deixar protegido.
 *
 * Acesse: http://localhost/apipix/configurar_webhook.php
 */

require_once __DIR__ . '/pix_api.php';

// ── Altere esta URL para o endereço público do seu servidor ──────────────────
$WEBHOOK_URL = 'https://ajude-seven.vercel.app/api/webhook_pix.php';

header('Content-Type: application/json; charset=utf-8');

global $CHAVE_PIX;
$chave = trim($CHAVE_PIX ?? '');

if (empty($chave)) {
    http_response_code(500);
    echo json_encode(['erro' => 'CHAVE_PIX não configurada em var.php']);
    exit;
}

if ($WEBHOOK_URL === 'https://ajude-seven.vercel.app/api/apipix/webhook_pix.php') {
    http_response_code(400);
    echo json_encode(['erro' => 'Altere $WEBHOOK_URL para a URL real antes de executar.']);
    exit;
}

try {
    $pix    = new PixApi();
    $result = $pix->configurarWebhook($chave, $WEBHOOK_URL);

    echo json_encode([
        'ok'         => true,
        'mensagem'   => 'Webhook registrado com sucesso!',
        'chave'      => $chave,
        'webhookUrl' => $result['webhookUrl'] ?? $WEBHOOK_URL,
        'criacao'    => $result['criacao']    ?? null,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (RuntimeException $e) {
    http_response_code((int)$e->getCode() ?: 500);
    echo json_encode(['erro' => $e->getMessage()]);
}
