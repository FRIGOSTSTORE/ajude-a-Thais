<?php
/**
 * transaction_store.php
 *
 * Armazenamento de transações PIX compatível com ambiente serverless (Vercel).
 * Usa Upstash Redis via REST API (funciona por HTTP puro, sem extensão Redis).
 *
 * Configure em var.php (ou nas Environment Variables da Vercel):
 *   $UPSTASH_REDIS_REST_URL   = "https://xxxx.upstash.io";
 *   $UPSTASH_REDIS_REST_TOKEN = "xxxxxxxx";
 *
 * Cada transação é salva com expiração automática de 24h (TTL), suficiente
 * para o ciclo de vida de um QR Code PIX (a cobrança expira em 1h por padrão).
 */

require_once __DIR__ . '/var.php';

class TransactionStore
{
    private string $baseUrl;
    private string $token;
    private int $ttlSeconds;

    public function __construct(int $ttlSeconds = 86400)
    {
        global $UPSTASH_REDIS_REST_URL, $UPSTASH_REDIS_REST_TOKEN;

        if (empty($UPSTASH_REDIS_REST_URL) || empty($UPSTASH_REDIS_REST_TOKEN)) {
            throw new RuntimeException(
                'Upstash não configurado. Defina $UPSTASH_REDIS_REST_URL e $UPSTASH_REDIS_REST_TOKEN em var.php.'
            );
        }

        $this->baseUrl    = rtrim($UPSTASH_REDIS_REST_URL, '/');
        $this->token      = $UPSTASH_REDIS_REST_TOKEN;
        $this->ttlSeconds = $ttlSeconds;
    }

    private function chave(string $txid): string
    {
        $txid = preg_replace('/[^a-zA-Z0-9]/', '', $txid);
        return "pix_tx:{$txid}";
    }

    /** Salva (ou sobrescreve) os dados de uma transação. */
    public function salvar(string $txid, array $dados): void
    {
        $chave = $this->chave($txid);
        $valor = json_encode($dados, JSON_UNESCAPED_UNICODE);

        // Comando Redis: SET chave valor EX ttl
        $this->comando(['SET', $chave, $valor, 'EX', (string)$this->ttlSeconds]);
    }

    /** Carrega os dados de uma transação. Retorna [] se não existir. */
    public function carregar(string $txid): array
    {
        $chave = $this->chave($txid);
        $resposta = $this->comando(['GET', $chave]);

        $valor = $resposta['result'] ?? null;
        if ($valor === null) {
            return [];
        }

        $dados = json_decode($valor, true);
        return is_array($dados) ? $dados : [];
    }

    /** Executa um comando Redis via REST API do Upstash. */
    private function comando(array $partes): array
    {
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($partes),
        ]);

        $body      = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            throw new RuntimeException("Erro ao acessar Upstash: {$curlError}");
        }

        $decoded = json_decode($body, true);

        if ($httpCode >= 400) {
            $msg = is_array($decoded) ? json_encode($decoded) : $body;
            throw new RuntimeException("Upstash HTTP {$httpCode}: {$msg}");
        }

        return is_array($decoded) ? $decoded : [];
    }
}
