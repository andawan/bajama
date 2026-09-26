<?php

declare(strict_types=1);

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Core\Audit;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function api_v1_reply(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_v1_error(string $message, int $status): void
{
    api_v1_reply(['ok' => false, 'error' => ['message' => $message]], $status);
}

function api_v1_success($data, int $status = 200): void
{
    api_v1_reply(['ok' => true, 'data' => $data], $status);
}

function api_v1_body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $body = json_decode($raw, true);
    if (!is_array($body)) {
        api_v1_error('Body harus berupa JSON object yang valid.', 400);
    }
    return $body;
}

function api_v1_bearer(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if ($header === '' && function_exists('getallheaders')) {
        $headers = getallheaders();
        $header = (string)($headers['Authorization'] ?? $headers['authorization'] ?? '');
    }
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($header), $matches)
        ? strtolower($matches[1])
        : '';
}

function api_v1_authenticate(PDO $db): array
{
    $token = api_v1_bearer();
    if ($token === '') {
        api_v1_error('Bearer token diperlukan.', 401);
    }

    $stmt = $db->prepare(
        'SELECT t.id AS token_id, t.user_id, t.organization_id,
                u.status AS user_status, o.status AS organization_status
         FROM api_access_tokens t
         INNER JOIN users u ON u.id = t.user_id AND u.organization_id = t.organization_id
         INNER JOIN organizations o ON o.id = t.organization_id
         WHERE t.token_hash = ? AND t.revoked_at IS NULL AND t.expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([hash('sha256', $token)]);
    $identity = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$identity || $identity['user_status'] !== 'ACTIVE' || $identity['organization_status'] !== 'ACTIVE') {
        api_v1_error('Token tidak valid, kedaluwarsa, atau akun tidak aktif.', 401);
    }

    // Reuse the existing account/tenant consistency check and permission services.
    $_SESSION['user_id'] = (int)$identity['user_id'];
    $_SESSION['organization_id'] = (int)$identity['organization_id'];
    if (!Auth::check()) {
        api_v1_error('Akun atau organisasi tidak aktif.', 401);
    }

    $db->prepare('UPDATE api_access_tokens SET last_used_at = NOW() WHERE id = ?')
        ->execute([(int)$identity['token_id']]);

    return $identity;
}

function api_v1_require_permission(PDO $db, string $permission): void
{
    if (!RBAC::hasPermission($db, $permission)) {
        api_v1_error('Anda tidak memiliki izin untuk operasi ini.', 403);
    }
}

function api_v1_require_feature(PDO $db, string $feature): void
{
    if (!License::hasFeature($db, $feature)) {
        api_v1_error('Modul tidak aktif pada lisensi organisasi ini.', 403);
    }
}

function api_v1_route(): string
{
    if (isset($_GET['path'])) {
        $path = (string)$_GET['path'];
    } else {
        $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        $path = preg_replace('~^.*?/api/v1/?~', '', $path) ?: '';
    }
    return trim(preg_replace('~/+~', '/', $path), '/');
}

try {
    require_once __DIR__ . '/../../../app/bootstrap.php';

    // Authentication context is bearer-token based. Do not persist the
    // temporary identity written for the legacy RBAC helpers into a browser session.
    $originalSession = $_SESSION;
    register_shutdown_function(static function () use ($originalSession): void {
        $_SESSION = $originalSession;
    });

    $origin = trim((string)env('API_CORS_ORIGINS', ''));
    $requestOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($requestOrigin !== '' && $origin !== '') {
        $allowedOrigins = array_filter(array_map('trim', explode(',', $origin)));
        if (in_array($requestOrigin, $allowedOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $requestOrigin);
            header('Vary: Origin');
            header('Access-Control-Allow-Headers: Authorization, Content-Type');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        }
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }

    $db = db();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $route = api_v1_route();

    if ($route === 'health' && $method === 'GET') {
        $db->query('SELECT 1')->fetchColumn();
        api_v1_success(['service' => 'BAJAMA API', 'status' => 'healthy', 'time' => gmdate('c')]);
    }

    if ($route === 'auth/login' && $method === 'POST') {
        $body = api_v1_body();
        $identifier = strtolower(trim((string)($body['identifier'] ?? $body['username'] ?? '')));
        $password = (string)($body['password'] ?? '');
        $clientName = trim((string)($body['client_name'] ?? 'BAJAMA client'));
        $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 45);

        if ($identifier === '' || $password === '') {
            api_v1_error('Username/email dan password wajib diisi.', 422);
        }
        $clientName = substr($clientName !== '' ? $clientName : 'BAJAMA client', 0, 120);

        $db->prepare('DELETE FROM api_login_attempts WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 1 DAY)')->execute();
        $rate = $db->prepare(
            'SELECT COUNT(*) FROM api_login_attempts
             WHERE identifier = ? AND ip_address = ? AND successful = 0
               AND attempted_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)'
        );
        $rate->execute([$identifier, $ip]);
        if ((int)$rate->fetchColumn() >= 5) {
            api_v1_error('Terlalu banyak percobaan login. Coba lagi beberapa menit.', 429);
        }

        $userStmt = $db->prepare(
            'SELECT u.id, u.organization_id, u.username, u.email, u.full_name,
                    u.password_hash, u.status, o.name AS organization_name, o.status AS organization_status
             FROM users u INNER JOIN organizations o ON o.id = u.organization_id
             WHERE (u.username = ? OR u.email = ?) LIMIT 1'
        );
        $userStmt->execute([$identifier, $identifier]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || $user['status'] !== 'ACTIVE' || $user['organization_status'] !== 'ACTIVE'
            || !password_verify($password, (string)$user['password_hash'])) {
            $db->prepare('INSERT INTO api_login_attempts (identifier, ip_address, successful) VALUES (?, ?, 0)')
                ->execute([$identifier, $ip]);
            api_v1_error('Kredensial tidak valid.', 401);
        }

        $plainToken = bin2hex(random_bytes(32));
        $plainRefreshToken = bin2hex(random_bytes(32));
        $tokenStmt = $db->prepare(
            'INSERT INTO api_access_tokens
                (user_id, organization_id, token_hash, refresh_token_hash, client_name, expires_at, refresh_expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 15 MINUTE), DATE_ADD(NOW(), INTERVAL 30 DAY))'
        );
        $tokenStmt->execute([
            (int)$user['id'],
            (int)$user['organization_id'],
            hash('sha256', $plainToken),
            hash('sha256', $plainRefreshToken),
            $clientName,
        ]);
        $db->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
        $db->prepare('INSERT INTO api_login_attempts (identifier, ip_address, successful) VALUES (?, ?, 1)')
            ->execute([$identifier, $ip]);

        try {
            Audit::log(
                $db,
                'API_LOGIN',
                'auth',
                'user',
                (int)$user['id'],
                ['client_name' => $clientName],
                (int)$user['organization_id'],
                (int)$user['id']
            );
        } catch (Throwable $auditError) {
            error_log('BAJAMA API login audit error: ' . $auditError->getMessage());
        }

        api_v1_success([
            'access_token' => $plainToken,
            'refresh_token' => $plainRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'refresh_expires_in' => 2592000,
            'user' => [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'full_name' => (string)($user['full_name'] ?? ''),
                'organization_id' => (int)$user['organization_id'],
                'organization_name' => (string)$user['organization_name'],
            ],
        ]);
    }

    if ($route === 'auth/refresh' && $method === 'POST') {
        $body = api_v1_body();
        $refreshToken = strtolower(trim((string)($body['refresh_token'] ?? '')));
        if (!preg_match('/^[a-f0-9]{64}$/', $refreshToken)) {
            api_v1_error('Refresh token tidak valid.', 401);
        }

        $db->beginTransaction();
        $refreshStmt = $db->prepare(
            'SELECT t.id, t.user_id, t.organization_id, u.status AS user_status,
                    o.status AS organization_status
             FROM api_access_tokens t
             INNER JOIN users u ON u.id = t.user_id AND u.organization_id = t.organization_id
             INNER JOIN organizations o ON o.id = t.organization_id
             WHERE t.refresh_token_hash = ? AND t.revoked_at IS NULL
               AND t.refresh_expires_at > NOW()
             LIMIT 1 FOR UPDATE'
        );
        $refreshStmt->execute([hash('sha256', $refreshToken)]);
        $identity = $refreshStmt->fetch(PDO::FETCH_ASSOC);
        if (!$identity || $identity['user_status'] !== 'ACTIVE' || $identity['organization_status'] !== 'ACTIVE') {
            $db->rollBack();
            api_v1_error('Refresh token tidak valid atau kedaluwarsa.', 401);
        }

        $newAccessToken = bin2hex(random_bytes(32));
        $newRefreshToken = bin2hex(random_bytes(32));
        $rotate = $db->prepare(
            'UPDATE api_access_tokens
             SET token_hash = ?, refresh_token_hash = ?,
                 expires_at = DATE_ADD(NOW(), INTERVAL 15 MINUTE),
                 refresh_expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY),
                 last_used_at = NOW()
             WHERE id = ? AND revoked_at IS NULL'
        );
        $rotate->execute([
            hash('sha256', $newAccessToken),
            hash('sha256', $newRefreshToken),
            (int)$identity['id'],
        ]);
        $db->commit();

        api_v1_success([
            'access_token' => $newAccessToken,
            'refresh_token' => $newRefreshToken,
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'refresh_expires_in' => 2592000,
        ]);
    }

    if ($route === 'auth/logout' && $method === 'POST') {
        api_v1_authenticate($db);
        $token = api_v1_bearer();
        $db->prepare('UPDATE api_access_tokens SET revoked_at = NOW() WHERE token_hash = ? AND revoked_at IS NULL')
            ->execute([hash('sha256', $token)]);
        api_v1_success(['message' => 'Sesi aplikasi telah dicabut.']);
    }

    if ($route === 'me' && $method === 'GET') {
        api_v1_authenticate($db);
        $userId = (int)Auth::userId();
        $organizationId = (int)Tenant::id();
        $userStmt = $db->prepare(
            'SELECT u.id, u.username, u.email, u.full_name, u.organization_id,
                    o.name AS organization_name, o.currency, o.timezone
             FROM users u INNER JOIN organizations o ON o.id = u.organization_id
             WHERE u.id = ? AND u.organization_id = ? LIMIT 1'
        );
        $userStmt->execute([$userId, $organizationId]);
        $user = $userStmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            api_v1_error('Pengguna tidak ditemukan.', 404);
        }
        $license = License::current($db);
        api_v1_success([
            'user' => [
                'id' => (int)$user['id'],
                'username' => (string)$user['username'],
                'email' => (string)($user['email'] ?? ''),
                'full_name' => (string)($user['full_name'] ?? ''),
            ],
            'organization' => [
                'id' => (int)$user['organization_id'],
                'name' => (string)$user['organization_name'],
                'currency' => (string)$user['currency'],
                'timezone' => (string)$user['timezone'],
            ],
            'roles' => array_values(RBAC::roles($db)),
            'license' => [
                'status' => License::status($db),
                'plan' => (string)($license['plan_name'] ?? ''),
                'expires_at' => (string)($license['expires_at'] ?? ''),
                'features' => License::features($db),
            ],
        ]);
    }

    if ($route === 'billing/summary' && $method === 'GET') {
        api_v1_authenticate($db);
        api_v1_require_permission($db, 'billing.view');
        api_v1_require_feature($db, 'billing');
        $organizationId = (int)Tenant::id();
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS total_invoices,
                    COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL','OVERDUE') THEN 1 ELSE 0 END),0) AS unpaid_invoices,
                    COALESCE(SUM(CASE WHEN status = 'OVERDUE' THEN 1 ELSE 0 END),0) AS overdue_invoices,
                    COALESCE(SUM(CASE WHEN status IN ('UNPAID','PARTIAL','OVERDUE') THEN total ELSE 0 END),0) AS outstanding_amount,
                    COALESCE(SUM(CASE WHEN status = 'PAID' THEN total ELSE 0 END),0) AS paid_amount
             FROM invoices WHERE organization_id = ?"
        );
        $stmt->execute([$organizationId]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['total_invoices', 'unpaid_invoices', 'overdue_invoices'] as $key) {
            $summary[$key] = (int)($summary[$key] ?? 0);
        }
        foreach (['outstanding_amount', 'paid_amount'] as $key) {
            $summary[$key] = (float)($summary[$key] ?? 0);
        }
        api_v1_success($summary);
    }

    if ($route === 'billing/invoices' && $method === 'GET') {
        api_v1_authenticate($db);
        api_v1_require_permission($db, 'billing.view');
        api_v1_require_feature($db, 'billing');
        $organizationId = (int)Tenant::id();
        $search = trim((string)($_GET['q'] ?? ''));
        $status = strtoupper(trim((string)($_GET['status'] ?? '')));
        $allowedStatuses = ['DRAFT', 'UNPAID', 'PARTIAL', 'PAID', 'OVERDUE', 'CANCELLED'];
        $sql =
            'SELECT i.id, i.invoice_number, i.issue_date, i.due_date, i.total, i.status,
                    c.name AS customer_name, c.customer_code
             FROM invoices i
             INNER JOIN customers c ON c.id = i.customer_id AND c.organization_id = i.organization_id
             WHERE i.organization_id = ?';
        $params = [$organizationId];
        if ($search !== '') {
            $sql .= ' AND (i.invoice_number LIKE ? OR c.name LIKE ? OR c.customer_code LIKE ?)';
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if (in_array($status, $allowedStatuses, true)) {
            $sql .= ' AND i.status = ?';
            $params[] = $status;
        }
        $sql .= " ORDER BY CASE WHEN i.status = 'OVERDUE' THEN 1 WHEN i.status IN ('UNPAID','PARTIAL') THEN 2 ELSE 3 END, i.id DESC LIMIT 100";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        api_v1_success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($route === 'network/routers' && $method === 'GET') {
        api_v1_authenticate($db);
        api_v1_require_permission($db, 'mikrotik.view');
        api_v1_require_feature($db, 'mikrotik');
        $stmt = $db->prepare(
            'SELECT id, name, host, port, status, use_ssl, timeout_seconds
             FROM mikrotik_routers WHERE organization_id = ? ORDER BY name'
        );
        $stmt->execute([(int)Tenant::id()]);
        api_v1_success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($route === 'network/olts' && $method === 'GET') {
        api_v1_authenticate($db);
        api_v1_require_permission($db, 'network.view');
        api_v1_require_feature($db, 'olt');
        $stmt = $db->prepare(
            'SELECT o.id, o.name, o.vendor, o.management_ip, o.status, o.enabled,
                    o.router_id, r.name AS router_name
             FROM network_olts o
             LEFT JOIN mikrotik_routers r ON r.id = o.router_id AND r.organization_id = o.organization_id
             WHERE o.organization_id = ? ORDER BY o.name'
        );
        $stmt->execute([(int)Tenant::id()]);
        api_v1_success($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    if ($route === 'auth/login' || $route === 'auth/refresh' || $route === 'auth/logout' || $route === 'me'
        || $route === 'billing/summary' || $route === 'billing/invoices'
        || $route === 'network/routers' || $route === 'network/olts') {
        api_v1_error('Method tidak diizinkan.', 405);
    }
    api_v1_error('Endpoint tidak ditemukan.', 404);
} catch (Throwable $exception) {
    error_log('BAJAMA API v1 error: ' . $exception->getMessage());
    api_v1_error('Terjadi kesalahan internal pada BAJAMA API.', 500);
}
