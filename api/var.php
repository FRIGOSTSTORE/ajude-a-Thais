<?php

$URL_API = "https://api.pix.basspago.com.br";
$SENHA_CASH_IN = "d.WWxgdpH_r3nv2HCMJcYxH-K8xkJqPJJuAH7V2Tp2CNbugdDWc!nbvH7a2X";
$CLIENT_ID = "00011193760124794000127";
$CLIENT_SECRET = "mNhMDcwNTItYTc4Ni00MjE1LThlOGEtZ";
 
$CHAVE_PIX = "fcd47d4c-bd68-440a-8480-9c5a6c184abc";
 
// ── Facebook Conversions API ──────────────────────────────────────────────────
// Todos os Pixels que devem receber os eventos da Conversions API.
// Para adicionar outro Pixel, inclua mais um bloco nesta lista.
$FB_PIXELS = [
    [
        'id' => '1520472869160223',
        'access_token' => 'EAASgeovzo6wBSBCy4b5MX4V6BZB7rbhshXzQDIIquGvuQSySYcHMB8JC2X8imGEvaPOaPVYy4k4IRupPmA4ZBnskxJ7sCeukG5Nqx2NMjKBjQboZBlpx5fOcvZAZCJNgsexv1VR2C6EF2Vsc6IKuBFSAmpZCpl9MMZAi9IzSc1wZBv1ZAovKovoSHAKThLX0yugZDZD',
    ],
    [
        'id' => '1762981111690468',
        'access_token' => 'EAAUJvQOQFFABSFRpx0DmZC9QlMwrSD0RNFz6YXZAj2E2SZAZCeuKCL5X3W1ZAfZARPTpMLUo4bvPEOBvPzrZCRvHqlVqDPkgAj9cNVXPvIAScCtg57ZAPZAGHDAFY8zW4pSb3GZBTWZAR6FEixgneq9PHDeJdujdFwpKqZCSkMuxbKlZCuTDLOtrwJhoGoveUKoryXwZDZD',
    ],
];
 
// ── UTMify ────────────────────────────────────────────────────────────────────
$UTMIFY_API_TOKEN = "grPOoJRIww4P97updndeR0Oc2x5ySV3UvClr";
$NOME_PRODUTO = "02 Produto Digital";
$ID_PRODUTO   = "produto-002";
 
$API_KEY = "a4f5d95862b7c5238cd957db82b3482e0b7de358fe65c50f5c6d08985f85dd3c";

// ── Upstash Redis (armazenamento de transações PIX — necessário na Vercel) ────
// Crie uma conta grátis em https://upstash.com, crie um banco Redis
// e cole aqui a REST URL e o REST TOKEN que aparecem no painel do banco.
$UPSTASH_REDIS_REST_URL   = "https://secure-ape-166176.upstash.io";
$UPSTASH_REDIS_REST_TOKEN = "gQAAAAAAAokgAAIgcDFmYmYyZjhmY2JlYzc0ZGFiOTU5OGRmZjUyZWRhZGVkZA";

?>

