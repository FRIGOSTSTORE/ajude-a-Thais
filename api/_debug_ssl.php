<?php
require_once __DIR__ . '/var.php';

$caPath   = str_replace('\\', '/', realpath(__DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/onz_ca.pem'));
$certPath = __DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/BASSPAGO_230.crt';
$keyPath  = __DIR__ . '/Certificados/BASSPAGO/PROD/QRCODES-MTLS/BASSPAGO_230.key';

global $CLIENT_ID, $CLIENT_SECRET, $SENHA_CASH_IN;

$ch = curl_init('https://api.pix.basspago.com.br/oauth/token');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_CAINFO         => $caPath,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSLCERT        => $certPath,
    CURLOPT_SSLKEY         => $keyPath,
    CURLOPT_SSLKEYPASSWD   => trim($SENHA_CASH_IN),
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => trim($CLIENT_ID),
        'client_secret' => trim($CLIENT_SECRET),
        'grant_type'    => 'client_credentials',
    ]),
]);
curl_exec($ch);
echo 'HTTP: '  . curl_getinfo($ch, CURLINFO_HTTP_CODE) . PHP_EOL;
echo 'Erro: '  . curl_error($ch) . PHP_EOL;
$cert = curl_getinfo($ch, CURLINFO_CERTINFO);
if ($cert) {
    echo 'Issuer: ' . ($cert[0]['Issuer'] ?? 'n/a') . PHP_EOL;
    echo 'Subject: ' . ($cert[0]['Subject'] ?? 'n/a') . PHP_EOL;
}
curl_close($ch);
