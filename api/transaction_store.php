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

    /**
     * Salva (ou sobrescreve) os dados de uma transação.
     *
     * Faz retry com backoff curto: essa gravação acontece na criação do PIX
     * (com o utm_source/utm_campaign/etc. capturados na hora) e é a ÚNICA
     * fonte desses dados para quando o pagamento for confirmado depois
     * (webhook ou polling). Se ela falhar e não houver retry, a leitura no
     * momento do "paid" vem vazia, e como sendUtmify() sempre envia
     * trackingParameters (a UTMify exige o campo), isso sobrescreve com
     * null a campanha que já tinha sido corretamente registrada no evento
     * "waiting_payment" - ou seja, a venda fica marcada como paga, mas sem
     * campanha nenhuma associada.
     */
    public function salvar(string $txid, array $dados, int $tentativas = 3): void
    {
        $chave = $this->chave($txid);
        $valor = json_encode($dados, JSON_UNESCAPED_UNICODE);

        $ultimaExcecao = null;
        for ($i = 1; $i <= $tentativas; $i++) {
            try {
                // Comando Redis: SET chave valor EX ttl
                $this->comando(['SET', $chave, $valor, 'EX', (string)$this->ttlSeconds]);
                return;
            } catch (Throwable $e) {
                $ultimaExcecao = $e;
                error_log(sprintf(
                    '[TransactionStore] Falha ao salvar txid=%s (tentativa %d/%d): %s',
                    $txid,
                    $i,
                    $tentativas,
                    $e->getMessage()
                ));
                if ($i < $tentativas) {
                    usleep(150000 * $i);
                }
            }
        }

        // Esgotou as tentativas: propaga para o chamador. gerar_pix.php
        // continua tratando isso com try/catch (não derruba a resposta ao
        // usuário), mas agora pelo menos o log deixa claro que a campanha
        // desta venda específica vai ficar sem atribuição.
        throw $ultimaExcecao ?? new RuntimeException('Falha desconhecida ao salvar transação.');
    }

    /**
     * Carrega os dados de uma transação. Retorna [] se não existir.
     *
     * Faz retry com backoff curto em caso de falha de rede/instabilidade do
     * Upstash, porque uma falha aqui faz o resto do fluxo (tracker, UTMify)
     * seguir sem os UTMs originais, mandando trackingParameters todo null e
     * "perdendo" a campanha que já tinha sido registrada.
     */
    public function carregar(string $txid, int $tentativas = 3): array
    {
        $chave = $this->chave($txid);

        $ultimaExcecao = null;
        for ($i = 1; $i <= $tentativas; $i++) {
            try {
                $resposta = $this->comando(['GET', $chave]);

                $valor = $resposta['result'] ?? null;
                if ($valor === null) {
                    return [];
                }

                $dados = json_decode($valor, true);
                return is_array($dados) ? $dados : [];
            } catch (Throwable $e) {
                $ultimaExcecao = $e;
                error_log(sprintf(
                    '[TransactionStore] Falha ao carregar txid=%s (tentativa %d/%d): %s',
                    $txid,
                    $i,
                    $tentativas,
                    $e->getMessage()
                ));
                if ($i < $tentativas) {
                    // Backoff curto: 150ms, 300ms, ... - suficiente para picos
                    // passageiros de latência do Upstash, sem travar o request.
                    usleep(150000 * $i);
                }
            }
        }

        // Esgotou as tentativas: propaga o erro para quem chamou decidir
        // (ex.: verificar_pagamento.php já trata isso com try/catch e segue
        // com o status salvo localmente, se houver).
        throw $ultimaExcecao ?? new RuntimeException('Falha desconhecida ao carregar transação.');
    }

    /**
     * Tenta adquirir um lock atômico para processar o pagamento de um txid.
     *
     * Usa SET key value NX EX ttl, que no Redis só grava se a chave ainda
     * não existir - operação atômica, então mesmo que webhook_pix.php e
     * verificar_pagamento.php cheguem "ao mesmo tempo", só um dos dois
     * consegue o lock. O outro deve tratar false como "já está sendo
     * processado por outro caminho, não repita o Purchase".
     *
     * O TTL é uma trava de segurança: se o processo que pegou o lock cair
     * no meio do caminho (timeout, erro fatal) sem liberar, o lock expira
     * sozinho e uma tentativa futura consegue adquirir de novo.
     */
    public function adquirirLock(string $txid, int $ttlSeconds = 15): bool
    {
        $chave = $this->chave($txid) . ':lock';

        try {
            $resposta = $this->comando(['SET', $chave, '1', 'NX', 'EX', (string)$ttlSeconds]);
        } catch (Throwable $e) {
            // Se o Upstash estiver indisponível, não bloqueia o fluxo de
            // pagamento por causa do lock - loga e deixa seguir sem lock
            // (pior caso: volta ao comportamento antigo, sem proteção).
            error_log('[TransactionStore] Falha ao adquirir lock txid=' . $txid . ': ' . $e->getMessage());
            return true;
        }

        // Upstash retorna {"result":"OK"} quando SET NX grava, e
        // {"result":null} quando a chave já existia (lock ocupado).
        return ($resposta['result'] ?? null) === 'OK';
    }

    /** Libera o lock de um txid (chamar depois de concluir o processamento). */
    public function liberarLock(string $txid): void
    {
        $chave = $this->chave($txid) . ':lock';
        try {
            $this->comando(['DEL', $chave]);
        } catch (Throwable $e) {
            // Não é crítico: o TTL do lock garante que ele expira sozinho.
            error_log('[TransactionStore] Falha ao liberar lock txid=' . $txid . ': ' . $e->getMessage());
        }
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
