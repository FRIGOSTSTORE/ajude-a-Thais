<?php

require_once __DIR__ . '/var.php';

class Tracker
{
    private string $fbPixelId;
    private string $fbAccessToken;
    private string $utmifyToken;
    private ?string $caBundle;

    public function __construct()
    {
        global $FB_PIXEL_ID, $FB_ACCESS_TOKEN, $UTMIFY_API_TOKEN;

        $this->fbPixelId     = trim($FB_PIXEL_ID      ?? '');
        $this->fbAccessToken = trim($FB_ACCESS_TOKEN  ?? '');
        $this->utmifyToken   = trim($UTMIFY_API_TOKEN ?? '');

        // cacert.pem Mozilla para APIs externas (Facebook, UTMify)
        $ca = realpath(__DIR__ . '/cacert.pem');
        $this->caBundle = $ca !== false ? str_replace('\\', '/', $ca) : null;
    }


    public function initiateCheckout(array $data): void
    {
        $this->sendFacebook('InitiateCheckout', $data);
        $this->sendUtmify('waiting_payment', $data);
    }

    public function purchase(array $data): void
    {
        $this->sendFacebook('Purchase', $data);
        $this->sendUtmify('paid', $data);
    }

    // -------------------------------------------------------------------------
    // Facebook Conversions API
    // -------------------------------------------------------------------------

    private function sendFacebook(string $eventName, array $data): void
    {
        if (empty($this->fbPixelId) || empty($this->fbAccessToken)) {
            error_log('[Tracker/FB] Pulado: pixel ou token vazio.');
            return;
        }

        $userData = [];

        // Identifiers — todos hasheados em SHA-256 conforme exigido pelo FB
        if (!empty($data['email'])) {
            $userData['em'] = [hash('sha256', strtolower(trim($data['email'])))];
        }
        if (!empty($data['phone'])) {
            $userData['ph'] = [hash('sha256', preg_replace('/\D/', '', $data['phone']))];
        }
        if (!empty($data['ua'])) {
            $userData['client_user_agent'] = $data['ua'];
        }
        if (!empty($data['fbc'])) {
            $userData['fbc'] = $data['fbc'];
        }
        if (!empty($data['fbp'])) {
            $userData['fbp'] = $data['fbp'];
        }

        // Garante ao menos um identificador mínimo
        if (empty($userData)) {
            $userData['client_ip_address'] = '0.0.0.0';
        }

        $eventId = $data['eventId'] ?? ($data['txid'] ?? uniqid('ev_', true));

        $payload = [
            'data' => [[
                'event_name'       => $eventName,
                'event_time'       => time(),
                'event_id'         => $eventId,   // para deduplicação com pixel browser
                'action_source'    => 'website',
                'event_source_url' => $data['url'] ?? '',
                'user_data'        => $userData,
                'custom_data'      => [
                    'currency' => 'BRL',
                    'value'    => (float)($data['valor'] ?? 0),
                    'order_id' => $data['txid'] ?? '',
                    'content_name' => $data['descricao'] ?? 'Pagamento PIX',
                ],
            ]],
        ];

        $url = sprintf(
            'https://graph.facebook.com/v19.0/%s/events?access_token=%s',
            urlencode($this->fbPixelId),
            urlencode($this->fbAccessToken)
        );

        [$httpCode, $resp, $curlErr] = $this->httpPost($url, $payload);

        error_log(sprintf(
            '[Tracker/FB] event=%s txid=%s http=%s resp=%s',
            $eventName,
            $data['txid'] ?? '-',
            $httpCode,
            $curlErr ?: $resp
        ));
    }

    // -------------------------------------------------------------------------
    // UTMify
    // -------------------------------------------------------------------------

    private function sendUtmify(string $status, array $data): void
    {
        if (empty($this->utmifyToken)) {
            error_log('[Tracker/UTMify] Pulado: token vazio.');
            return;
        }

        global $NOME_PRODUTO, $ID_PRODUTO;

        $valor    = (float)($data['valor'] ?? 0);
        $centavos = (int)round($valor * 100);
        $agora    = date('Y-m-d H:i:s');

        // Converte string vazia para null (UTMify rejeita "" em campos opcionais)
        $utm = function(string $key) use ($data): ?string {
            $v = $data[$key] ?? null;
            return ($v !== null && $v !== '') ? (string)$v : null;
        };

        $body = [
            'orderId'       => $data['txid']      ?? uniqid('pix_'),
            'platform'      => 'custom',
            'paymentMethod' => 'pix',
            'status'        => $status,
            'createdAt'     => $data['createdAt'] ?? $agora,
            'approvedDate'  => $status === 'paid' ? ($data['paidAt'] ?? $agora) : null,
            'refundedAt'    => null,
            'customer'      => [
                'name'     => $data['nome']     ?: 'Cliente',
                'email'    => $data['email'] ?: ('pix.' . substr($data['txid'] ?? uniqid(), 0, 20) . '@noreply.invalid'),
                'phone'    => $data['phone']    ?: null,
                'document' => $data['document'] ?: null,
            ],
            'products' => [[
                'id'           => $ID_PRODUTO   ?? 'produto-002',
                'name'         => $NOME_PRODUTO ?? ($data['descricao'] ?: 'Produto Digital 02'),
                'planId'       => null,
                'planName'     => null,
                'quantity'     => 1,
                'priceInCents' => $centavos,
            ]],
            'trackingParameters' => [
                'utm_source'   => $utm('utm_source'),
                'utm_medium'   => $utm('utm_medium'),
                'utm_campaign' => $utm('utm_campaign'),
                'utm_content'  => $utm('utm_content'),
                'utm_term'     => $utm('utm_term'),
                'src'          => $utm('src'),
                'sck'          => $utm('sck'),
            ],
            'commission' => [
                'totalPriceInCents'     => $centavos,
                'gatewayFeeInCents'     => 0,
                'userCommissionInCents' => $centavos,
            ],
        ];

        [$httpCode, $resp, $curlErr] = $this->httpPost(
            'https://api.utmify.com.br/api-credentials/orders',
            $body,
            ['x-api-token: ' . $this->utmifyToken]
        );

        // Log vai para os Logs da Vercel (Functions → Logs), não mais para arquivo,
        // já que não há disco persistente no ambiente serverless.
        error_log(sprintf(
            '[Tracker/UTMify] status=%s txid=%s http=%s resp=%s',
            $status,
            $data['txid'] ?? '-',
            $httpCode,
            $curlErr ?: $resp
        ));
    }

    // -------------------------------------------------------------------------
    // HTTP helper
    // -------------------------------------------------------------------------

    private function httpPost(string $url, array $body, array $extraHeaders = []): array
    {
        $ch      = curl_init($url);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders);

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];

        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        return [$httpCode, (string)($response ?: ''), $curlErr];
    }
}
