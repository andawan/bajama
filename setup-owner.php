<?php

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$orgName = $argv[1] ?? 'BAJAMA ISP';
$ownerUsername = strtolower(trim($argv[2] ?? 'owner'));
$ownerEmail = strtolower(trim($argv[3] ?? 'owner@example.com'));

$slug = strtolower(
    preg_replace('/[^a-z0-9]+/', '-', $orgName)
);

$slug = trim($slug, '-');

function generatePassword(int $length = 20): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    $result = '';

    for ($i = 0; $i < $length; $i++) {
        $result .= $chars[random_int(0, strlen($chars) - 1)];
    }

    return $result;
}

$password = generatePassword();

$pdo = db();

$pdo->beginTransaction();

try {

    /*
    |--------------------------------------------------------------------------
    | ORGANIZATION
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM organizations
         WHERE slug = ?
         LIMIT 1'
    );

    $stmt->execute([$slug]);

    $existingOrg = $stmt->fetch();

    if ($existingOrg) {
        throw new RuntimeException(
            "Organization dengan slug '$slug' sudah ada."
        );
    }

    $stmt = $pdo->prepare(
        'INSERT INTO organizations
        (
            name,
            slug,
            email,
            status
        )
        VALUES (?, ?, ?, "ACTIVE")'
    );

    $stmt->execute([
        $orgName,
        $slug,
        $ownerEmail
    ]);

    $organizationId = (int)$pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | OWNER ROLE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM roles
         WHERE name = "OWNER"
         LIMIT 1'
    );

    $stmt->execute();

    $ownerRoleId = (int)$stmt->fetchColumn();

    if (!$ownerRoleId) {
        throw new RuntimeException(
            'Role OWNER tidak ditemukan.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | OWNER USER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM users
         WHERE username = ?
         LIMIT 1'
    );

    $stmt->execute([
        $ownerUsername
    ]);

    if ($stmt->fetch()) {
        throw new RuntimeException(
            "Username '$ownerUsername' sudah ada."
        );
    }

    $stmt = $pdo->prepare(
        'INSERT INTO users
        (
            organization_id,
            username,
            email,
            password_hash,
            full_name,
            status
        )
        VALUES (?, ?, ?, ?, ?, "ACTIVE")'
    );

    $stmt->execute([
        $organizationId,
        $ownerUsername,
        $ownerEmail,
        password_hash(
            $password,
            PASSWORD_DEFAULT
        ),
        'BAJAMA Owner'
    ]);

    $userId = (int)$pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | USER ROLE
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'INSERT INTO user_roles
        (
            user_id,
            role_id
        )
        VALUES (?, ?)'
    );

    $stmt->execute([
        $userId,
        $ownerRoleId
    ]);


    /*
    |--------------------------------------------------------------------------
    | ORGANIZATION OWNER
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'UPDATE organizations
         SET owner_user_id = ?
         WHERE id = ?'
    );

    $stmt->execute([
        $userId,
        $organizationId
    ]);


    /*
    |--------------------------------------------------------------------------
    | TRIAL PLAN
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'SELECT id
         FROM license_plans
         WHERE code = "TRIAL"
         LIMIT 1'
    );

    $stmt->execute();

    $planId = (int)$stmt->fetchColumn();

    if (!$planId) {
        throw new RuntimeException(
            'License plan TRIAL tidak ditemukan.'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | LICENSE
    |--------------------------------------------------------------------------
    */

    $licenseKey =
        'BAJAMA-TRIAL-' .
        strtoupper(bin2hex(random_bytes(8)));

    $features = json_encode([
        'dashboard' => true,
        'billing' => true,
        'mikrotik' => true,
        'pppoe' => true,
        'hotspot' => true,
        'static' => true,
        'fiber' => true,
        'olt' => true,
        'onu' => true,
        'noc' => true,
        'api' => true
    ], JSON_UNESCAPED_UNICODE);


    $stmt = $pdo->prepare(
        'INSERT INTO licenses
        (
            organization_id,
            plan_id,
            license_key,
            status,
            issued_at,
            expires_at,
            features
        )
        VALUES
        (
            ?,
            ?,
            ?,
            "TRIAL",
            NOW(),
            DATE_ADD(NOW(), INTERVAL 14 DAY),
            ?
        )'
    );

    $stmt->execute([
        $organizationId,
        $planId,
        $licenseKey,
        $features
    ]);

    $licenseId = (int)$pdo->lastInsertId();


    /*
    |--------------------------------------------------------------------------
    | LICENSE EVENT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'INSERT INTO license_events
        (
            organization_id,
            license_id,
            event_type,
            new_status,
            details
        )
        VALUES
        (
            ?,
            ?,
            "LICENSE_CREATED",
            "TRIAL",
            ?
        )'
    );

    $stmt->execute([
        $organizationId,
        $licenseId,
        json_encode([
            'plan' => 'TRIAL',
            'days' => 14
        ])
    ]);


    /*
    |--------------------------------------------------------------------------
    | AUDIT
    |--------------------------------------------------------------------------
    */

    $stmt = $pdo->prepare(
        'INSERT INTO audit_logs
        (
            organization_id,
            user_id,
            action,
            module,
            target_type,
            target_id,
            details
        )
        VALUES
        (
            ?,
            ?,
            "organization.created",
            "Core",
            "organization",
            ?,
            ?
        )'
    );

    $stmt->execute([
        $organizationId,
        $userId,
        $organizationId,
        json_encode([
            'organization' => $orgName,
            'username' => $ownerUsername
        ])
    ]);


    $pdo->commit();


    echo PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo "          BAJAMA OWNER CREATED" . PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo "Organization : $orgName" . PHP_EOL;
    echo "Tenant ID    : $organizationId" . PHP_EOL;
    echo "Owner User   : $ownerUsername" . PHP_EOL;
    echo "Password     : $password" . PHP_EOL;
    echo "License      : $licenseKey" . PHP_EOL;
    echo "License      : TRIAL" . PHP_EOL;
    echo "Trial        : 14 hari" . PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo "SIMPAN PASSWORD OWNER INI." . PHP_EOL;
    echo "==============================================" . PHP_EOL;
    echo PHP_EOL;

} catch (Throwable $e) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(
        STDERR,
        PHP_EOL .
        "ERROR: " .
        $e->getMessage() .
        PHP_EOL
    );

    exit(1);
}
