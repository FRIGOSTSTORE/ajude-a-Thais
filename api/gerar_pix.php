<?php
/**
 * Endpoint: gerar_pix.php
 * Cria uma cobranca PIX imediata e retorna os dados para geracao do QR Code.
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
 *   "src":          "...",               (opcional - UTMify)
 *   "sck":          "...",               (opcional - UTMify)
 *   "fbc":          "_fbc cookie",       (opcional - Facebook)
 *   "fbp":          "_fbp cookie",       (opcional - Facebook)
 *   "url":          "https://...",       (opcional - pagina de origem)
 *   "email":        "...",               (opcional)
 *   "phone":        "...",               (opcional)
 * }
 */

// Evita que Warnings/Notices do PHP quebrem o JSON de resposta
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

// Bufferiza toda a saida: qualquer texto solto (BOM, espaco, warning) e
// descartado antes do JSON, evitando resposta invalida no navegador.
ob_start();

// Rede de seguranca: se ocorrer um erro fatal do PHP (classe nao encontrada,
// parse error em arquivo incluido, etc.), devolve o motivo exato em JSON em vez
// de uma resposta vazia com HTTP 200.
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
        ], JSON_UNESCAPED_UNICODE);
    }

    if (ob_get_length() !== false) {
        ob_end_flush();
    }
});

// Confere se os arquivos da API existem antes de incluir, para dar um erro claro.
foreach (['pix_api.php', 'tracker.php', 'transaction_store.php', 'var.php'] as $dependencia) {
    if (!is_file(__DIR__ . '/' . $dependencia)) {
        http_response_code(500);
        echo json_encode(['erro' => 'Arquivo ausente na pasta api: ' . $dependencia], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Metodo nao permitido.']);
    exit;
}

require_once __DIR__ . '/pix_api.php';
require_once __DIR__ . '/tracker.php';
require_once __DIR__ . '/transaction_store.php';

if (!class_exists('PixApi')) {
    http_response_code(500);
    echo json_encode(['erro' => 'A classe PixApi nao foi carregada. Verifique o conteudo de api/pix_api.php.'], JSON_UNESCAPED_UNICODE);
    exit;
}

global $CHAVE_PIX;

if (empty(trim($CHAVE_PIX ?? ''))) {
    http_response_code(500);
    echo json_encode(['erro' => 'Chave PIX nao configurada em var.php ($CHAVE_PIX).']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Validar valor
$valor = round((float)($input['valor'] ?? 0), 2);
if ($valor < 0.01) {
    http_response_code(400);
    echo json_encode(['erro' => 'Informe um valor minimo de R$ 0,01.']);
    exit;
}

// Sanitizar descricao
$descricao = mb_substr(trim($input['descricao'] ?? ''), 0, 140);

// Montar body da cobranca PIX
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

    // -- Montar dados de tracking ---------------------------------------------
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

    // -- Persiste transacao para o webhook usar depois (Upstash Redis) --------
    if ($txid) {
        try {
            (new TransactionStore())->salvar($txid, $trackData);
        } catch (Throwable $e) {
            // Nao derruba a resposta ao usuario por falha de persistencia -
            // mas loga para voce conseguir ver isso nos Logs da Vercel.
            error_log('Falha ao salvar transacao no Upstash: ' . $e->getMessage());
        }
    }

    // -- Dispara InitiateCheckout (FB) + waiting_paid (UTMify) ----------------
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
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Erro interno ao gerar PIX: ' . $e->getMessage(),
        'arquivo' => basename($e->getFile()) . ':' . $e->getLine(),
    ], JSON_UNESCAPED_UNICODE);
}
