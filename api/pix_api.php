<?php

require_once __DIR__ . '/var.php';

class PixApi
{
    private string $baseUrl;
    private string $clientId;
    private string $clientSecret;
    private string $certPath;
    private string $keyPath;
    private string $keyPassword;
    private string $caPath;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    public function __construct()
    {
        global $URL_API, $CLIENT_ID, $CLIENT_SECRET, $SENHA_CASH_IN;

        $this->baseUrl      = rtrim($URL_API, '/');
        $this->clientId     = trim($CLIENT_ID);
        $this->clientSecret = trim($CLIENT_SECRET);
        $this->keyPassword  = trim($SENHA_CASH_IN);
        $this->certPath     = __DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/BASSPAGO_230.crt';
        $this->keyPath      = __DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/BASSPAGO_230.key';
        $this->caPath       = __DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/onz_ca.pem';
    }

    // -------------------------------------------------------------------------
    // Autenticação OAuth2 client_credentials com mTLS
    // -------------------------------------------------------------------------

    private function getToken(): string
    {
        if ($this->accessToken && time() < $this->tokenExpiry - 30) {
            return $this->accessToken;
        }

        $response = $this->request('POST', '/oauth/token', [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
        ], 'form', false);

        if (empty($response['access_token'])) {
            throw new RuntimeException('Falha ao obter token de acesso: ' . json_encode($response));
        }

        $this->accessToken = $response['access_token'];
        $this->tokenExpiry = time() + (int)($response['expires_in'] ?? 300);

        return $this->accessToken;
    }

    // -------------------------------------------------------------------------
    // Cobranças imediatas (/cob)
    // -------------------------------------------------------------------------

    /** Cria cobrança imediata com txid definido pelo PSP (POST /cob) */
    public function criarCobranca(array $dados): array
    {
        return $this->request('POST', '/cob', $dados);
    }

    /** Cria cobrança imediata com txid fornecido (PUT /cob/{txid}) */
    public function criarCobrancaComTxid(string $txid, array $dados): array
    {
        $this->validarTxid($txid);
        return $this->request('PUT', "/cob/{$txid}", $dados);
    }

    /** Atualiza cobrança imediata (PATCH /cob/{txid}) */
    public function atualizarCobranca(string $txid, array $dados): array
    {
        $this->validarTxid($txid);
        return $this->request('PATCH', "/cob/{$txid}", $dados);
    }

    /** Consulta cobrança imediata (GET /cob/{txid}) */
    public function consultarCobranca(string $txid, ?int $revisao = null): array
    {
        $this->validarTxid($txid);
        $query = $revisao !== null ? "?revisao={$revisao}" : '';
        return $this->request('GET', "/cob/{$txid}{$query}");
    }

    /** Lista cobranças imediatas (GET /cob) */
    public function listarCobrancas(array $params): array
    {
        $this->validarParamsData($params);
        return $this->request('GET', '/cob?' . http_build_query($params));
    }

    // -------------------------------------------------------------------------
    // Cobranças com vencimento (/cobv)
    // -------------------------------------------------------------------------

    /** Cria cobrança com vencimento (PUT /cobv/{txid}) */
    public function criarCobrancaVencimento(string $txid, array $dados): array
    {
        $this->validarTxid($txid);
        return $this->request('PUT', "/cobv/{$txid}", $dados);
    }

    /** Atualiza cobrança com vencimento (PATCH /cobv/{txid}) */
    public function atualizarCobrancaVencimento(string $txid, array $dados): array
    {
        $this->validarTxid($txid);
        return $this->request('PATCH', "/cobv/{$txid}", $dados);
    }

    /** Consulta cobrança com vencimento (GET /cobv/{txid}) */
    public function consultarCobrancaVencimento(string $txid, ?int $revisao = null): array
    {
        $this->validarTxid($txid);
        $query = $revisao !== null ? "?revisao={$revisao}" : '';
        return $this->request('GET', "/cobv/{$txid}{$query}");
    }

    /** Lista cobranças com vencimento (GET /cobv) */
    public function listarCobrancasVencimento(array $params): array
    {
        $this->validarParamsData($params);
        return $this->request('GET', '/cobv?' . http_build_query($params));
    }

    // -------------------------------------------------------------------------
    // PIX recebidos (/pix)
    // -------------------------------------------------------------------------

    /** Consulta um PIX recebido pelo e2eid (GET /pix/{e2eid}) */
    public function consultarPix(string $e2eid): array
    {
        $this->validarE2eid($e2eid);
        return $this->request('GET', "/pix/{$e2eid}");
    }

    /** Lista PIX recebidos (GET /pix) */
    public function listarPix(array $params): array
    {
        $this->validarParamsData($params);
        return $this->request('GET', '/pix?' . http_build_query($params));
    }

    // -------------------------------------------------------------------------
    // Devoluções (/pix/{e2eid}/devolucao/{id})
    // -------------------------------------------------------------------------

    /** Solicita devolução (PUT /pix/{e2eid}/devolucao/{id}) */
    public function solicitarDevolucao(string $e2eid, string $idDevolucao, array $dados): array
    {
        $this->validarE2eid($e2eid);
        $idDevolucao = $this->sanitizarId($idDevolucao);
        return $this->request('PUT', "/pix/{$e2eid}/devolucao/{$idDevolucao}", $dados);
    }

    /** Consulta devolução (GET /pix/{e2eid}/devolucao/{id}) */
    public function consultarDevolucao(string $e2eid, string $idDevolucao): array
    {
        $this->validarE2eid($e2eid);
        $idDevolucao = $this->sanitizarId($idDevolucao);
        return $this->request('GET', "/pix/{$e2eid}/devolucao/{$idDevolucao}");
    }

    // -------------------------------------------------------------------------
    // Webhook (/webhook/{chave})
    // -------------------------------------------------------------------------

    /** Configura webhook para uma chave PIX (PUT /webhook/{chave}) */
    public function configurarWebhook(string $chave, string $webhookUrl): array
    {
        if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('URL do webhook inválida.');
        }
        $chave = urlencode($chave);
        return $this->request('PUT', "/webhook/{$chave}", ['webhookUrl' => $webhookUrl]);
    }

    /** Consulta webhook de uma chave PIX (GET /webhook/{chave}) */
    public function consultarWebhook(string $chave): array
    {
        $chave = urlencode($chave);
        return $this->request('GET', "/webhook/{$chave}");
    }

    /** Remove webhook de uma chave PIX (DELETE /webhook/{chave}) */
    public function removerWebhook(string $chave): array
    {
        $chave = urlencode($chave);
        return $this->request('DELETE', "/webhook/{$chave}");
    }

    // -------------------------------------------------------------------------
    // Payload Locations (/loc)
    // -------------------------------------------------------------------------

    /** Cria location do payload (POST /loc) */
    public function criarLocation(string $tipoCob): array
    {
        if (!in_array($tipoCob, ['cob', 'cobv'], true)) {
            throw new InvalidArgumentException('tipoCob deve ser "cob" ou "cobv".');
        }
        return $this->request('POST', '/loc', ['tipoCob' => $tipoCob]);
    }

    /** Consulta location pelo id (GET /loc/{id}) */
    public function consultarLocation(int $id): array
    {
        return $this->request('GET', "/loc/{$id}");
    }

    // -------------------------------------------------------------------------
    // Núcleo HTTP com mTLS
    // -------------------------------------------------------------------------

    /**
     * @param string      $method   GET|POST|PUT|PATCH|DELETE
     * @param string      $path     Path relativo (ex: /cob)
     * @param array|null  $body     Dados do corpo da requisição
     * @param string      $bodyType 'json' (padrão) ou 'form'
     * @param bool        $auth     Se deve enviar Bearer token
     * @return array
     */
    private function request(
        string $method,
        string $path,
        ?array $body = null,
        string $bodyType = 'json',
        bool $auth = true
    ): array {
        $url = $this->baseUrl . $path;
        $ch  = curl_init($url);

        $headers = [];

        if ($auth) {
            $token     = $this->getToken();
            $headers[] = "Authorization: Bearer {$token}";
        }

        if ($bodyType === 'json') {
            $headers[] = 'Content-Type: application/json';
            $headers[] = 'Accept: application/json';
        } else {
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }

        // CA root da ONZ Software (extraída do PFX — necessária pois o servidor usa CA privada)
        $caBundle = realpath($this->caPath);
        if ($caBundle !== false) {
            $caBundle = str_replace('\\', '/', $caBundle);
        } else {
            $caBundle = null;
        }

        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            // mTLS
            CURLOPT_SSLCERT        => $this->certPath,
            CURLOPT_SSLKEY         => $this->keyPath,
            CURLOPT_SSLKEYPASSWD   => $this->keyPassword,
        ];

        if ($caBundle !== null) {
            $curlOpts[CURLOPT_CAINFO] = $caBundle;
        }

        curl_setopt_array($ch, $curlOpts);

        if ($body !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $payload = $bodyType === 'json' ? json_encode($body) : http_build_query($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $responseBody = curl_exec($ch);
        $httpCode     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError    = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("Erro cURL: {$curlError}");
        }

        $decoded = json_decode($responseBody, true);

        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? json_encode($decoded) : $responseBody;
            throw new RuntimeException("HTTP {$httpCode}: {$msg}", $httpCode);
        }

        return is_array($decoded) ? $decoded : [];
    }

    // -------------------------------------------------------------------------
    // Validações de entrada
    // -------------------------------------------------------------------------

    private function validarTxid(string $txid): void
    {
        if (!preg_match('/^[a-zA-Z0-9]{26,35}$/', $txid)) {
            throw new InvalidArgumentException('txid inválido: deve conter 26 a 35 caracteres alfanuméricos.');
        }
    }

    private function validarE2eid(string $e2eid): void
    {
        if (!preg_match('/^[a-zA-Z0-9]{32}$/', $e2eid)) {
            throw new InvalidArgumentException('e2eid inválido: deve conter exatamente 32 caracteres alfanuméricos.');
        }
    }

    private function sanitizarId(string $id): string
    {
        if (!preg_match('/^[a-zA-Z0-9]{1,35}$/', $id)) {
            throw new InvalidArgumentException('Id inválido.');
        }
        return $id;
    }

    private function validarParamsData(array $params): void
    {
        foreach (['inicio', 'fim'] as $campo) {
            if (isset($params[$campo]) && !$this->isISO8601($params[$campo])) {
                throw new InvalidArgumentException("Parâmetro '{$campo}' deve ser uma data/hora no formato RFC 3339.");
            }
        }
        if (isset($params['cpf']) && !preg_match('/^\d{11}$/', $params['cpf'])) {
            throw new InvalidArgumentException('CPF inválido.');
        }
        if (isset($params['cnpj']) && !preg_match('/^\d{14}$/', $params['cnpj'])) {
            throw new InvalidArgumentException('CNPJ inválido.');
        }
        if (isset($params['cpf'], $params['cnpj'])) {
            throw new InvalidArgumentException('Não é possível filtrar por CPF e CNPJ simultaneamente.');
        }
    }

    private function isISO8601(string $value): bool
    {
        return (bool)preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value);
    }
}
