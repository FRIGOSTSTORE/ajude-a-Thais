<?php
/**
 * Endpoint: gerar_pix.php
 * Cria uma cobrança PIX imediata e retorna os dados para geração do QR Code.
 *
 * Aceita POST com JSON:
 * {
 *   "valor":        10.00,
 *   "descricao":    "Pedido #123",       (opcional)
 *   "utm_source":   "google",            (opcional)
 *   "utm_medium":   "cpc",               (opcional)
 *   "utm_campaign": "black-friday",      (opcional)
 *   "utm_content":  "banner",            (opcional)
 *   "utm_term":     "pix",               (opcional)
 *   "src":          "...",               (opcional — UTMify)
 *   "sck":          "...",               (opcional — UTMify)
 *   "fbc":          "_fbc cookie",       (opcional — Facebook)
 *   "fbp":          "_fbp cookie",       (opcional — Facebook)
 *   "url":          "https://...",       (opcional — página de origem)
 *   "email":        "...",               (opcional)
 *   "phone":        "...",               (opcional)
 * }
 */

// Evita que Warnings/Notices do PHP quebrem o JSON de resposta
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

require_once __DIR__ . '/pix_api.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

global $CHAVE_PIX;

if (empty(trim($CHAVE_PIX ?? ''))) {
    http_response_code(500);
    echo json_encode(['erro' => 'Chave PIX não configurada em var.php ($CHAVE_PIX).']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Validar valor
$valor = round((float)($input['valor'] ?? 0), 2);
if ($valor < 0.01) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um valor mínimo de R$ 0,01.']);
    exit;
}

// Sanitizar descrição
$descricao = mb_substr(trim($input['descricao'] ?? ''), 0, 140);

// Montar body da cobrança PIX
$dados = [
    'calendario' => ['expiracao' => 3600],
    'valor'      => ['original' => number_format($valor, 2, '.', '')],
    'chave'      => trim($CHAVE_PIX),
];
if ($descricao !== '') {
    $dados['solicitacaoPagador'] = $descricao;
}

try {
    $pix      = new PixApi();
    $response = $pix->criarCobranca($dados);

    $pixCopiaECola = $response['pixCopiaECola']
        ?? $response['brcode']
        ?? $response['qrcode']
        ?? $response['emv']
        ?? $response['loc']['pixCopiaECola']
        ?? null;

    $txid = $response['txid'] ?? null;

    // ── Montar dados de tracking ─────────────────────────────────────────────
    $trackData = [
        'txid'         => $txid,
        'valor'        => $response['valor']['original'] ?? number_format($valor, 2, '.', ''),
        'descricao'    => $descricao,
        'createdAt'    => date('Y-m-d H:i:s'),
        'ua'           => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'url'          => $input['url']          ?? '',
        'email'        => $input['email']        ?? '',
        'phone'        => $input['phone']        ?? '',
        'document'     => $input['document']     ?? '',
        'nome'         => $input['nome']         ?? '',
        'fbc'          => $input['fbc']          ?? '',
        'fbp'          => $input['fbp']          ?? '',
        'utm_source'   => $input['utm_source']   ?? '',
        'utm_medium'   => $input['utm_medium']   ?? '',
        'utm_campaign' => $input['utm_campaign'] ?? '',
        'utm_content'  => $input['utm_content']  ?? '',
        'utm_term'     => $input['utm_term']     ?? '',
        'src'          => $input['src']          ?? '',
        'sck'          => $input['sck']          ?? '',
        'status'       => 'waiting_paid',
    ];

    // ── Persiste transação para o webhook usar depois (Upstash Redis) ────────
    if ($txid) {
        try {
            (new TransactionStore())->salvar($txid, $trackData);
        } catch (Throwable $e) {
            // Não derruba a resposta ao usuário por falha de persistência —
            // mas loga para você conseguir ver isso nos Logs da Vercel.
            error_log('Falha ao salvar transação no Upstash: ' . $e->getMessage());
        }
    }

    // ── Dispara InitiateCheckout (FB) + waiting_paid (UTMify) ────────────────
    (new Tracker())->initiateCheckout($trackData);

    echo json_encode([
        'txid'          => $txid,
        'status'        => $response['status']                  ?? null,
        'valor'         => $trackData['valor'],
        'expiracao'     => $response['calendario']['expiracao'] ?? 3600,
        'location'      => $response['location']                ?? ($response['loc']['location'] ?? null),
        'pixCopiaECola' => $pixCopiaECola,
        '_tracking'     => [
            'utm_source'   => $trackData['utm_source'],
            'utm_medium'   => $trackData['utm_medium'],
            'utm_campaign' => $trackData['utm_campaign'],
            'utm_content'  => $trackData['utm_content'],
            'utm_term'     => $trackData['utm_term'],
            'src'          => $trackData['src'],
            'sck'          => $trackData['sck'],
            'fbc'          => $trackData['fbc'],
            'fbp'          => $trackData['fbp'],
        ],
    ], JSON_UNESCAPED_UNICODE);

} catch (RuntimeException $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 ? $code : 500);
    echo json_encode(['erro' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno ao gerar PIX.']);
}
