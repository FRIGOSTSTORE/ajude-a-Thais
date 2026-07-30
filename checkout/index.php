<?php

require_once __DIR__ . '/pix_api.php';

header('Content-Type: application/json; charset=utf-8');

global $API_KEY;
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals('Bearer ' . $API_KEY, $authHeader)) {
    http_response_code(401);
    echo json_encode(['erro' => 'Não autorizado.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri    = '/' . trim(str_replace('/apipix', '', $uri), '/');

$uri    = explode('?', $uri)[0];

$body = [];
if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
    $input = file_get_contents('php://input');
    if ($input) {
        $body = json_decode($input, true) ?? [];
    }
}

try {
    $pix = new PixApi();
    $result = route($method, $uri, $body, $pix);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['erro' => $e->getMessage()]);
} catch (RuntimeException $e) {
    $code = (int)$e->getCode();
    http_response_code($code >= 400 ? $code : 500);
    echo json_encode(['erro' => $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['erro' => 'Erro interno do servidor.']);
}


function route(string $method, string $uri, array $body, PixApi $pix): array
{
    if ($method === 'GET' && preg_match('#^/pix/([a-zA-Z0-9]{32})/devolucao/([a-zA-Z0-9]{1,35})$#', $uri, $m)) {
        return $pix->consultarDevolucao($m[1], $m[2]);
    }

    if ($method === 'PUT' && preg_match('#^/pix/([a-zA-Z0-9]{32})/devolucao/([a-zA-Z0-9]{1,35})$#', $uri, $m)) {
        return $pix->solicitarDevolucao($m[1], $m[2], $body);
    }

    if ($method === 'GET' && preg_match('#^/pix/([a-zA-Z0-9]{32})$#', $uri, $m)) {
        return $pix->consultarPix($m[1]);
    }

    if ($method === 'GET' && $uri === '/pix') {
        return $pix->listarPix($_GET);
    }

    if ($method === 'PATCH' && preg_match('#^/cob/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        return $pix->atualizarCobranca($m[1], $body);
    }

    if ($method === 'PUT' && preg_match('#^/cob/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        return $pix->criarCobrancaComTxid($m[1], $body);
    }

    if ($method === 'GET' && preg_match('#^/cob/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        $revisao = isset($_GET['revisao']) ? (int)$_GET['revisao'] : null;
        return $pix->consultarCobranca($m[1], $revisao);
    }

    if ($method === 'POST' && $uri === '/cob') {
        return $pix->criarCobranca($body);
    }

    if ($method === 'GET' && $uri === '/cob') {
        return $pix->listarCobrancas($_GET);
    }

    if ($method === 'PUT' && preg_match('#^/cobv/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        return $pix->criarCobrancaVencimento($m[1], $body);
    }

    if ($method === 'PATCH' && preg_match('#^/cobv/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        return $pix->atualizarCobrancaVencimento($m[1], $body);
    }

    if ($method === 'GET' && preg_match('#^/cobv/([a-zA-Z0-9]{26,35})$#', $uri, $m)) {
        $revisao = isset($_GET['revisao']) ? (int)$_GET['revisao'] : null;
        return $pix->consultarCobrancaVencimento($m[1], $revisao);
    }

    if ($method === 'GET' && $uri === '/cobv') {
        return $pix->listarCobrancasVencimento($_GET);
    }

    if ($method === 'PUT' && preg_match('#^/webhook/(.+)$#', $uri, $m)) {
        if (empty($body['webhookUrl'])) {
            throw new InvalidArgumentException('Campo webhookUrl é obrigatório.');
        }
        return $pix->configurarWebhook(urldecode($m[1]), $body['webhookUrl']);
    }

    if ($method === 'GET' && preg_match('#^/webhook/(.+)$#', $uri, $m)) {
        return $pix->consultarWebhook(urldecode($m[1]));
    }

    if ($method === 'DELETE' && preg_match('#^/webhook/(.+)$#', $uri, $m)) {
        return $pix->removerWebhook(urldecode($m[1]));
    }

    if ($method === 'GET' && preg_match('#^/loc/(\d+)$#', $uri, $m)) {
        return $pix->consultarLocation((int)$m[1]);
    }

    if ($method === 'POST' && $uri === '/loc') {
        if (empty($body['tipoCob'])) {
            throw new InvalidArgumentException('Campo tipoCob é obrigatório (cob ou cobv).');
        }
        return $pix->criarLocation($body['tipoCob']);
    }

    http_response_code(404);
    return ['erro' => 'Rota não encontrada.'];
}
