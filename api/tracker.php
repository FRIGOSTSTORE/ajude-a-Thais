
/
var
.php
preciso que esse arqiov dispare os eventos para o facebook para maios de 2 pixel como posso fazer
10:13
O var.php atual apenas guarda configurações. Ele ainda não envia eventos para o Facebook. Para disparar eventos para mais de 2 Pixels, faça assim:
1. Troque a configuração de um Pixel por uma lista
No var.php, substitua:
$FB_PIXEL_ID = "...";
$FB_ACCESS_TOKEN = "...";
​
por:
$FB_GRAPH_VERSION = "v23.0";

$FB_PIXELS = [
    [
        "pixel_id" => getenv("FB_PIXEL_1_ID"),
        "access_token" => getenv("FB_PIXEL_1_TOKEN"),
    ],
    [
        "pixel_id" => getenv("FB_PIXEL_2_ID"),
        "access_token" => getenv("FB_PIXEL_2_TOKEN"),
    ],
    [
        "pixel_id" => getenv("FB_PIXEL_3_ID"),
        "access_token" => getenv("FB_PIXEL_3_TOKEN"),
    ],
    // Adicione quantos Pixels precisar
];
​
No Vercel, crie as variáveis:
FB_PIXEL_1_ID
FB_PIXEL_1_TOKEN
FB_PIXEL_2_ID
FB_PIXEL_2_TOKEN
FB_PIXEL_3_ID
FB_PIXEL_3_TOKEN
​
Cada Pixel deve ter um token com permissão para enviar eventos para ele.
2. Crie uma função para enviar para todos os Pixels
Você pode colocar esta função em um arquivo como facebook-events.php:
<?php

require_once __DIR__ . "/var.php";

function enviarEventoFacebook(
    string $nomeEvento,
    array $customData = [],
    array $userData = [],
    ?string $eventId = null
): array {
    global $FB_PIXELS, $FB_GRAPH_VERSION;

    $eventId = $eventId ?: bin2hex(random_bytes(16));

    $dadosEvento = [
        "data" => [
            [
                "event_name" => $nomeEvento,
                "event_time" => time(),
                "event_id" => $eventId,
                "action_source" => "website",
                "event_source_url" =>
                    $_SERVER["HTTP_REFERER"] ??
                    "https://seudominio.com.br",
                "user_data" => $userData,
                "custom_data" => $customData,
            ],
        ],
    ];

    $resultados = [];

    foreach ($FB_PIXELS as $pixel) {
        $pixelId = trim($pixel["pixel_id"] ?? "");
        $accessToken = trim($pixel["access_token"] ?? "");

        if ($pixelId === "" || $accessToken === "") {
            continue;
        }

        $url = "https://graph.facebook.com/"
            . $FB_GRAPH_VERSION
            . "/"
            . rawurlencode($pixelId)
            . "/events?access_token="
            . rawurlencode($accessToken);

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Content-Type: application/json",
            ],
            CURLOPT_POSTFIELDS => json_encode(
                $dadosEvento,
                JSON_UNESCAPED_SLASHES
            ),
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        $resultados[] = [
            "pixel_id" => $pixelId,
            "http_code" => $httpCode,
            "success" => $httpCode >= 200 && $httpCode < 300,
            "response" => json_decode($response, true),
            "error" => $error ?: null,
        ];
    }

    return [
        "event_id" => $eventId,
        "pixels" => $resultados,
    ];
}
​
3. Chame a função no pagamento aprovado
Por exemplo, depois de confirmar o pagamento Pix:
require_once __DIR__ . "/facebook-events.php";

$resultadoFacebook = enviarEventoFacebook(
    "Purchase",
    [
        "value" => 49.90,
        "currency" => "BRL",
        "content_name" => "Produto Digital",
        "content_ids" => ["produto-001"],
        "content_type" => "product",
    ],
    [
        "client_ip_address" => $_SERVER["REMOTE_ADDR"] ?? null,
        "client_user_agent" => $_SERVER["HTTP_USER_AGENT"] ?? null,
        "fbp" => $_COOKIE["_fbp"] ?? null,
        "fbc" => $_COOKIE["_fbc"] ?? null,
    ],
    $_POST["event_id"] ?? null
);
​
A função enviará o mesmo evento para o Pixel 1, Pixel 2, Pixel 3 e todos os demais configurados.
4. Para o Pixel no navegador
Se também usar o Pixel no front-end, inicialize todos:
<script>
  !function(f,b,e,v,n,t,s) {
    if(f.fbq)return;
    n=f.fbq=function(){n.callMethod ?
      n.callMethod.apply(n,arguments) : n.queue.push(arguments)
    };
    if(!f._fbq)f._fbq=n;
    n.push=n;
    n.loaded=!0;
    n.version='2.0';
    n.queue=[];
    t=b.createElement(e);
    t.async=!0;
    t.src=v;
    s=b.getElementsByTagName(e)[0];
    s.parentNode.insertBefore(t,s)
  }(window, document, 'script',
    'https://connect.facebook.net/en_US/fbevents.js');

  fbq('init', 'PIXEL_1');
  fbq('init', 'PIXEL_2');
  fbq('init', 'PIXEL_3');

  fbq('track', 'PageView');
</script>
​
Para uma compra, gere o mesmo event_id no navegador e no PHP:
const eventId = crypto.randomUUID();

fbq(
  'track',
  'Purchase',
  {
    value: 49.90,
    currency: 'BRL'
  },
  {
    eventID: eventId
  }
);
​
Depois envie esse eventId para o PHP. Isso ajuda o Facebook a fazer a deduplicação entre o evento do navegador e o evento da Conversions API.
Atenção importante sobre segurança
O arquivo enviado contém credenciais aparentemente reais, incluindo:
Token da Conversions API;
Credenciais do Basspago;
Token da UTMify;
API key;
Token do Upstash Redis.
Recomendo revogar e gerar novos tokens imediatamente e mover tudo para as variáveis de ambiente da Vercel. Não deixe essas credenciais gravadas diretamente no var.php.
Também é importante colocar essa lógica no arquivo que realmente confirma o pedido/pagamento. O var.php sozinho não sabe quando ocorreu Purchase, AddToCart ou InitiateCheckout.
no caso nao preciso qu ecriei nada para enviar apenass criei os espaços para colocar novos pixel porem garanta que  todos vao receber se quiserm ando mais arquios
10:15
Entendi. Você não quer que eu crie uma nova função de envio agora. Você apenas deixou espaços para adicionar outros Pixels no futuro.
Pode enviar os demais arquivos. Para eu garantir que todos os Pixels recebam todos os eventos, preciso verificar principalmente os arquivos que contêm:
Purchase, PageView, AddToCart ou InitiateCheckout;
fbq(;
graph.facebook.com;
curl_init;
confirmação ou webhook do pagamento;
arquivos de checkout/API.
O var.php sozinho apenas armazena o Pixel e o token. A garantia de envio para todos depende do arquivo que efetivamente dispara o evento. Nele vou confirmar que:
Todos os Pixels cadastrados serão percorridos;
Cada Pixel usará seu próprio ID e token;
Se um Pixel falhar, os outros continuarão recebendo;
Os eventos não serão enviados duplicados;
Pixels adicionados depois também serão incluídos automaticamente, sem precisar alterar novamente o código de envio.
Pode mandar os outros arquivos. Se possível, envie também o arquivo responsável pela confirmação do pedido ou pagamento. Evite enviar novamente tokens e senhas reais; substitua por TOKEN_AQUI antes do envio.
webhook_pix
.php
_debug_ssl
.php
configurar_webhook
.php
tracker
.php
pix_api
.php
var
.php
cacert
.pem
verificar_pagamento
.php
transaction_store
.php
gerar_pix
.php
index
.php
maantenha o pixel q ja esa e coloque esse novo tambem id 1762981111690468
token > EAAUJvQOQFFABSFRpx0DmZC9QlMwrSD0RNFz6YXZAj2E2SZAZCeuKCL5X3W1ZAfZARPTpMLUo4bvPEOBvPzrZCRvHqlVqDPkgAj9cNVXPvIAScCtg57ZAPZAGHDAFY8zW4pSb3GZBTWZAR6FEixgneq9PHDeJdujdFwpKqZCSkMuxbKlZCuTDLOtrwJhoGoveUKoryXwZDZD
10:18
var
.php
tracker
.php
Processo concluído
Pronto. Mantive o Pixel existente e adicionei o novo:
Pixel antigo: mantido
Novo Pixel: 1762981111690468
O tracker.php agora percorre todos os Pixels configurados e envia o mesmo evento para cada um.
O webhook_pix.php não precisou ser alterado, pois ele já chama o Tracker quando o pagamento é confirmado.
Se um Pixel apresentar erro, o sistema continua tentando enviar para os demais.
Os arquivos atualizados estão disponíveis acima para download:
var.php
tracker.php
Também conferi a configuração por análise estática. O ambiente de teste não possui o interpretador PHP instalado para executar o php -l.
Importante: como os tokens ficaram expostos nos arquivos enviados, gere novos tokens do Facebook e atualize-os depois, preferencialmente usando variáveis de ambiente da Vercel.
gi
<?php
​
require_once __DIR__ . '/var.php';
​
class Tracker
{
    private array $fbPixels;
    private string $utmifyToken;
    private ?string $caBundle;
​
    public function __construct()
    {
        global $FB_PIXELS, $FB_PIXEL_ID, $FB_ACCESS_TOKEN, $UTMIFY_API_TOKEN;
​
        // Mantém compatibilidade com a configuração antiga de um único Pixel.
        $pixels = $FB_PIXELS ?? [];
        if (empty($pixels) && !empty($FB_PIXEL_ID) && !empty($FB_ACCESS_TOKEN)) {
            $pixels = [
                [
                    'id' => $FB_PIXEL_ID,
                    'access_token' => $FB_ACCESS_TOKEN,
                ],
            ];
        }
​
        $this->fbPixels = [];
        foreach ($pixels as $pixel) {
            $id = trim((string)($pixel['id'] ?? $pixel['pixel_id'] ?? ''));
            $token = trim((string)($pixel['access_token'] ?? $pixel['token'] ?? ''));
​
            if ($id !== '' && $token !== '') {
                $this->fbPixels[] = [
                    'id' => $id,
                    'access_token' => $token,
                ];
            }
        }
​
        $this->utmifyToken = trim($UTMIFY_API_TOKEN ?? '');
​
        // cacert.pem Mozilla para APIs externas (Facebook, UTMify)
        $ca = realpath(__DIR__ . '/cacert.pem');
        $this->caBundle = $ca !== false ? str_replace('\\', '/', $ca) : null;
    }
​
​
    public function initiateCheckout(array $data): void
    {
        $this->sendFacebook('InitiateCheckout', $data);
        $this->sendUtmify('waiting_payment', $data);
    }
​
    public function purchase(array $data): void
    {
        $this->sendFacebook('Purchase', $data);
        $this->sendUtmify('paid', $data);
    }
​
    // -------------------------------------------------------------------------
    // Facebook Conversions API
    // -------------------------------------------------------------------------
​
    private function sendFacebook(string $eventName, array $data): void
    {
        if (empty($this->fbPixels)) {
            error_log('[Tracker/FB] Pulado: nenhum Pixel configurado.');
            return;
        }
​
        $userData = [];
​
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
​
        // Garante ao menos um identificador mínimo
        if (empty($userData)) {
            $userData['client_ip_address'] = '0.0.0.0';
        }
​
        $eventId = $data['eventId'] ?? ($data['txid'] ?? uniqid('ev_', true));
​
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
​
        // Envia o mesmo evento, com o mesmo event_id, para todos os Pixels.
        // Se um Pixel falhar, o loop continua para os demais.
        foreach ($this->fbPixels as $pixel) {
            $url = sprintf(
                'https://graph.facebook.com/v19.0/%s/events?access_token=%s',
                urlencode($pixel['id']),
                urlencode($pixel['access_token'])
            );
​
            [$httpCode, $resp, $curlErr] = $this->httpPost($url, $payload);
​
            error_log(sprintf(
                '[Tracker/FB] pixel=%s event=%s txid=%s http=%s resp=%s',
                $pixel['id'],
                $eventName,
                $data['txid'] ?? '-',
                $httpCode,
                $curlErr ?: $resp
            ));
        }
    }
​
    // -------------------------------------------------------------------------
    // UTMify
    // -------------------------------------------------------------------------
​
    private function sendUtmify(string $status, array $data): void
    {
        if (empty($this->utmifyToken)) {
            error_log('[Tracker/UTMify] Pulado: token vazio.');
            return;
        }
​
        global $NOME_PRODUTO, $ID_PRODUTO;
​
        $valor    = (float)($data['valor'] ?? 0);
        $centavos = (int)round($valor * 100);
        $agora    = date('Y-m-d H:i:s');
​
        // Converte string vazia para null (UTMify rejeita "" em campos opcionais)
        $utm = function(string $key) use ($data): ?string {
            $v = $data[$key] ?? null;
            return ($v !== null && $v !== '') ? (string)$v : null;
        };
​
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
                'id'           => $ID_PRODUTO   ?? 'produto-001',
                'name'         => $NOME_PRODUTO ?? ($data['descricao'] ?: 'Produto Digital'),
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
​
        [$httpCode, $resp, $curlErr] = $this->httpPost(
            'https://api.utmify.com.br/api-credentials/orders',
            $body,
            ['x-api-token: ' . $this->utmifyToken]
        );
​
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
​
    // -------------------------------------------------------------------------
    // HTTP helper
    // -------------------------------------------------------------------------
​
    private function httpPost(string $url, array $body, array $extraHeaders = []): array
    {
        $ch      = curl_init($url);
        $headers = array_merge(['Content-Type: application/json', 'Accept: application/json'], $extraHeaders);
​
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ];
​
        if ($this->caBundle !== null) {
            $opts[CURLOPT_CAINFO] = $this->caBundle;
        }
​
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
​
        return [$httpCode, (string)($response ?: ''), $curlErr];
    }
}
​
A IA do Notion terminou.
