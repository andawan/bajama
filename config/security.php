<?php
declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| BAJAMA SESSION SECURITY
|--------------------------------------------------------------------------
| Cloudflare terminates HTTPS before Apache/PHP.
| Therefore the application must explicitly mark the session cookie Secure.
|--------------------------------------------------------------------------
*/

if (session_status() !== PHP_SESSION_ACTIVE) {

    $forwardedProto = strtolower(
        trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))
    );

    $httpsRequest =
        (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $forwardedProto === 'https';

    $host = strtolower(
        (string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '')
    );

    $host = preg_replace('/:\d+$/', '', $host) ?: $host;

    if (in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        $httpsRequest = false;
    }

    $secureCookie = filter_var(
        env('SESSION_SECURE', $httpsRequest ? 'true' : 'false'),
        FILTER_VALIDATE_BOOLEAN,
        FILTER_NULL_ON_FAILURE
    );

    if ($secureCookie === null) {
        $secureCookie = $httpsRequest;
    }

    session_name(env('SESSION_NAME', 'BAJAMA_SESSION'));

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/*
|--------------------------------------------------------------------------
| CSRF
|--------------------------------------------------------------------------
*/

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['_csrf'];
}

function verify_csrf(string $token): void
{
    $sessionToken = (string) ($_SESSION['_csrf'] ?? '');

    /* Accept legacy page-specific tokens during the migration to the
     * single application-wide CSRF token. */
    $validTokens = [$sessionToken];
    foreach (['payment_csrf', 'traffic_policy_csrf', 'traffic_review_csrf', 'traffic_submit_csrf', 'csrf_token'] as $key) {
        if (!empty($_SESSION[$key])) {
            $validTokens[] = (string) $_SESSION[$key];
        }
    }

    $valid = $token !== '';
    if ($valid) {
        $valid = false;
        foreach ($validTokens as $validToken) {
            if ($validToken !== '' && hash_equals($validToken, $token)) {
                $valid = true;
                break;
            }
        }
    }

    if (!$valid) {
        http_response_code(419);
        exit('CSRF token tidak valid.');
    }
}

/*
| Compatibility alias
*/
function verifyCsrf(string $token): void
{
    verify_csrf($token);
}
