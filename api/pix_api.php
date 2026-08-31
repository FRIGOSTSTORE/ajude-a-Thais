<?php

require_once __DIR__ . '/var.php';

/**
 * Adaptador PIX para a API da FlevoPay.
 * Mantem a mesma interface usada pelo restante do projeto.
 */
class PixApi
{
    private string $baseUrl;
    private string $apiKey;

    public function __construct()
    {
        global $FLEVOPAY_API_URL, $FLEVOPAY_SECRET_KEY;

        $this->baseUrl = rtrim((string)$FLEVOPAY_API_URL, '/');
        $this->apiKey  = trim((string)$FLEVOPAY_SECRET_KEY);

        if ($this->baseUrl === '' || $this->apiKey === '') {
            throw new RuntimeException('FlevoPay não configurada: informe URL e Secret Key em var.php.');
        }
    }

    /** Cria uma transação PIX na FlevoPay. */
    public function criarCobrancaComTxid(string $txid, array $dados): array
    {
        $this->validarReference($txid);

        $amount = (int)round(((float)($dados['valor']['original'] ?? 0)) * 100);
        if ($amount < 1) {
            throw new InvalidArgumentException('Valor da cobrança inválido.');
        }

        $description = trim((string)($dados['solicitacaoPagador'] ?? ''));
        if ($description === '') {
            $description = 'Doação';
        }

        $customer = $dados['customer'] ?? [];
        $name     = trim((string)($customer['name'] ?? 'Doador'));
        $email    = trim((string)($customer['email'] ?? ''));
        $document = preg_replace('/\D+/', '', (string)($customer['document'] ?? ''));
        $phone    = preg_replace('/\D+/', '', (string)($customer['phone'] ?? ''));

        if ($name === '' || $email === '' || $document === '' || $phone === '') {
            throw new InvalidArgumentException('Nome, e-mail, documento e telefone são necessários para criar o PIX na FlevoPay.');
        }

        $body = [
            'amount'      => $amount,
            'description' => $description,
            'reference'   => $txid,
            'source'      => 'api_externa',
            'customer'    => [
                'name'     => $name,
                'email'    => $email,
                'document' => $document,
                'phone'    => $phone,
            ],
        ];

        if (!empty($dados['postback_url'])) {
            $body['postback_url'] = $dados['postback_url'];
        }

        if (!empty($dados['tracking']) && is_array($dados['tracking'])) {
            $body['tracking'] = $dados['tracking'];
        }

        $response = $this->request('POST', '/api/v1/transaction', $body);

        if (($response['status'] ?? '') !== 'success') {
            throw new RuntimeException('FlevoPay recusou a transação: ' . json_encode($response, JSON_UNESCAPED_UNICODE), 502);
        }

        // Normaliza a resposta para o formato que o checkout já espera.
        $response['txid'] = $response['id'] ?? $txid;
        $response['pixCopiaECola'] = $response['qr_code'] ?? $response['pix_code'] ?? null;
        $response['calendario'] = [
            'expiracao' => $response['expires_at'] ?? 3600,
        ];

        return $response;
    }

    /** Consulta uma transação pela referência usada na criação. */
    public function consultarCobranca(string $txid, ?int $revisao = null): array
    {
        $this->validarReference($txid);

        $items = $this->request(
            'GET',
            '/api/v1/query?action=list_transactions&external_id=' . rawurlencode($txid)
        );

        $lista = is_array($items) && array_is_list($items) ? $items : ($items['data'] ?? []);
        if (!is_array($lista)) {
            $lista = [];
        }

        $transaction = $lista[0] ?? null;
        if (!$transaction) {
            return [
                'status' => 'pending',
                'reference' => $txid,
            ];
        }

        $status = strtolower((string)($transaction['status'] ?? 'pending'));
        $normalized = [
            'status' => $status,
            'txid' => $txid,
            'id' => $transaction['id'] ?? null,
            'external_id' => $transaction['external_id'] ?? $txid,
            'amount' => $transaction['amount'] ?? null,
            'amount_in_reais' => $transaction['amount_in_reais'] ?? null,
            'created_at' => $transaction['created_at'] ?? null,
            'updated_at' => $transaction['updated_at'] ?? null,
        ];

        return $normalized;
    }

    // Métodos mantidos para compatibilidade com api/index.php.
    public function criarCobranca(array $dados): array
    {
        $reference = 'AJD' . bin2hex(random_bytes(14));
        return $this->criarCobrancaComTxid($reference, $dados);
    }

    public function atualizarCobranca(string $txid, array $dados): array
    {
        throw new RuntimeException('Atualização de cobrança não é suportada por esta integração FlevoPay.', 405);
    }

    public function listarCobrancas(array $params): array
    {
        throw new RuntimeException('Listagem de cobranças não está exposta por esta integração.', 405);
    }

    public function criarCobrancaVencimento(string $txid, array $dados): array
    {
        throw new RuntimeException('Cobranças com vencimento não são suportadas por esta integração.', 405);
    }

    public function atualizarCobrancaVencimento(string $txid, array $dados): array
    {
        throw new RuntimeException('Cobranças com vencimento não são suportadas por esta integração.', 405);
    }

    public function consultarCobrancaVencimento(string $txid, ?int $revisao = null): array
    {
        throw new RuntimeException('Cobranças com vencimento não são suportadas por esta integração.', 405);
    }

    public function listarCobrancasVencimento(array $params): array
    {
        throw new RuntimeException('Cobranças com vencimento não são suportadas por esta integração.', 405);
    }

    public function consultarPix(string $e2eid): array
    {
        throw new RuntimeException('Consulta por e2eid não é suportada por esta integração.', 405);
    }

    public function listarPix(array $params): array
    {
        throw new RuntimeException('Listagem de PIX recebidos não é suportada por esta integração.', 405);
    }

    public function solicitarDevolucao(string $e2eid, string $idDevolucao, array $dados): array
    {
        throw new RuntimeException('Reembolso deve ser feito pelo endpoint /api/v1/refund da FlevoPay.', 405);
    }

    public function consultarDevolucao(string $e2eid, string $idDevolucao): array
    {
        throw new RuntimeException('Consulta de devolução não está implementada neste adaptador.', 405);
    }

    public function configurarWebhook(string $chave, string $webhookUrl): array
    {
        throw new RuntimeException('Configure o webhook da FlevoPay pelo painel da conta.', 405);
    }

    public function consultarWebhook(string $chave): array
    {
        throw new RuntimeException('Webhook é gerenciado pelo painel da FlevoPay.', 405);
    }

    public function removerWebhook(string $chave): array
    {
        throw new RuntimeException('Webhook é gerenciado pelo painel da FlevoPay.', 405);
    }

    public function criarLocation(string $tipoCob): array
    {
        throw new RuntimeException('Locations não são usadas pela API FlevoPay.', 405);
    }

    public function consultarLocation(int $id): array
    {
        throw new RuntimeException('Locations não são usadas pela API FlevoPay.', 405);
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Não foi possível inicializar o cURL.');
        }

        $headers = [
            'X-API-Key: ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json',
        ];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($body !== null && $method !== 'GET') {
            $options[CURLOPT_POSTFIELDS] = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno) {
            throw new RuntimeException('Erro de comunicação com a FlevoPay: ' . $error, 502);
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Resposta inválida da FlevoPay (HTTP ' . $httpCode . ').', 502);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? json_encode($decoded, JSON_UNESCAPED_UNICODE);
            throw new RuntimeException('FlevoPay retornou HTTP ' . $httpCode . ': ' . $message, $httpCode);
        }

        return $decoded;
    }

    private function validarReference(string $reference): void
    {
        if (!preg_match('/^[a-zA-Z0-9._-]{1,255}$/', $reference)) {
            throw new InvalidArgumentException('Referência/txid inválido.');
        }
    }
}
