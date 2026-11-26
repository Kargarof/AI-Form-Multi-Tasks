<?php
declare(strict_types=1);

/*
 * Iniciar sesión con Google (OpenID Connect, flujo con código + PKCE).
 * Funciona por redirección, también dentro de la app instalada en iPhone y Android.
 */

function google_cfg(string $key, ?string $default = null): ?string
{
    global $config;
    return $config['google'][$key] ?? $default;
}

function b64url(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** URL a la que se manda al usuario para elegir su cuenta de Google. */
function google_auth_url(): string
{
    $state    = b64url(random_bytes(24));
    $verifier = b64url(random_bytes(48));
    $nonce    = b64url(random_bytes(16));
    $_SESSION['google_oauth'] = ['state' => $state, 'verifier' => $verifier, 'nonce' => $nonce, 'at' => time()];

    $params = [
        'client_id'             => google_cfg('client_id'),
        'redirect_uri'          => google_cfg('redirect_uri'),
        'response_type'         => 'code',
        'scope'                 => 'openid email profile',
        'state'                 => $state,
        'nonce'                 => $nonce,
        'code_challenge'        => b64url(hash('sha256', $verifier, true)),
        'code_challenge_method' => 'S256',
        'prompt'                => 'select_account',
    ];
    return google_cfg('auth_url', 'https://accounts.google.com/o/oauth2/v2/auth') . '?' . http_build_query($params);
}

/**
 * Comprueba la vuelta de Google y devuelve los datos de la cuenta verificada.
 * @return array{sub:string,email:string,name:?string,picture:?string}
 */
function google_handle_callback(array $query): array
{
    $saved = $_SESSION['google_oauth'] ?? null;
    unset($_SESSION['google_oauth']);

    if (isset($query['error'])) {
        throw new RuntimeException('Has cancelado el inicio de sesión con Google.');
    }
    if (!$saved || !hash_equals((string) $saved['state'], (string) ($query['state'] ?? '')) || time() - (int) $saved['at'] > 900) {
        throw new RuntimeException('La solicitud ha caducado. Vuelve a intentarlo.');
    }
    $code = (string) ($query['code'] ?? '');
    if ($code === '') {
        throw new RuntimeException('Google no ha devuelto ningún código.');
    }

    $response = http_post_form(google_cfg('token_url', 'https://oauth2.googleapis.com/token'), [
        'code'          => $code,
        'client_id'     => google_cfg('client_id'),
        'client_secret' => google_cfg('client_secret'),
        'redirect_uri'  => google_cfg('redirect_uri'),
        'grant_type'    => 'authorization_code',
        'code_verifier' => $saved['verifier'],
    ]);
    $idToken = $response['id_token'] ?? null;
    if (!is_string($idToken) || substr_count($idToken, '.') !== 2) {
        throw new RuntimeException('Google no ha devuelto la identificación de la cuenta.');
    }

    // El token llega directamente de Google por HTTPS (servidor a servidor), así que
    // basta con validar su contenido (OpenID Connect Core, apartado 3.1.3.7).
    [, $payloadB64] = explode('.', $idToken);
    $claims = json_decode(b64url_decode($payloadB64), true);
    $issOk  = in_array($claims['iss'] ?? '', ['https://accounts.google.com', 'accounts.google.com'], true);
    $audOk  = ($claims['aud'] ?? '') === google_cfg('client_id');
    $expOk  = (int) ($claims['exp'] ?? 0) > time() - 60;
    $nonceOk = hash_equals((string) $saved['nonce'], (string) ($claims['nonce'] ?? ''));

    if (!is_array($claims) || !$issOk || !$audOk || !$expOk || !$nonceOk || empty($claims['sub'])) {
        throw new RuntimeException('No se ha podido verificar la cuenta de Google.');
    }
    if (empty($claims['email']) || ($claims['email_verified'] ?? false) !== true) {
        throw new RuntimeException('La cuenta de Google no tiene un email verificado.');
    }

    return [
        'sub'     => (string) $claims['sub'],
        'email'   => strtolower((string) $claims['email']),
        'name'    => isset($claims['name']) ? mb_substr((string) $claims['name'], 0, 80) : null,
        'picture' => isset($claims['picture']) ? mb_substr((string) $claims['picture'], 0, 500) : null,
    ];
}

function http_post_form(string $url, array $fields): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        error_log('[AI Form Multi Tasks] Google token: ' . $err);
        throw new RuntimeException('No se ha podido conectar con Google.');
    }
    $data = json_decode((string) $body, true);
    if ($status !== 200 || !is_array($data)) {
        error_log('[AI Form Multi Tasks] Google token HTTP ' . $status . ': ' . mb_substr((string) $body, 0, 300));
        throw new RuntimeException('Google ha rechazado el inicio de sesión. Revisa la configuración.');
    }
    return $data;
}
