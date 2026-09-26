<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\RouterOsLiveData;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'mikrotik.view');

$organizationId = (int)Tenant::id();
$routers = MikroTik::all($db, $organizationId);
$routerId = (int)($_POST['router_id'] ?? $_GET['router_id'] ?? ($routers[0]['id'] ?? 0));
$error = '';
$data = [];
$ispProfiles = [];
$categories = [];
$catalogByCategory = [];
$existingIspByCategory = [];
$csrf = csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf((string)($_POST['csrf'] ?? $_POST['_csrf'] ?? ''));
}

try {
    if ($routerId > 0) {
        $data = RouterOsLiveData::read($db, $organizationId, $routerId);
        $ispStmt = $db->prepare(
            'SELECT id, name, interface_name, gateway, ip_address, subnet
             FROM network_isps
             WHERE organization_id = ? AND router_id = ? AND enabled = 1
             ORDER BY id
             LIMIT 2'
        );
        $ispStmt->execute([$organizationId, $routerId]);
        $ispProfiles = $ispStmt->fetchAll(PDO::FETCH_ASSOC);

        $categoryStmt = $db->query(
            'SELECT id, name, slug, description, icon, sort_order
             FROM traffic_categories
             WHERE enabled = 1
             ORDER BY sort_order, name, id'
        );
        $categories = $categoryStmt->fetchAll(PDO::FETCH_ASSOC);
        $categoryOrder = [
            'social' => 10, 'sosmed' => 10, 'stream' => 20, 'streaming' => 20,
            'game' => 30, 'bank' => 40, 'banking' => 40, 'ewallet' => 50,
            'e-wallet' => 50, 'chat' => 60,
        ];
        usort($categories, static function (array $left, array $right) use ($categoryOrder): int {
            $leftSlug = strtolower((string)$left['slug']);
            $rightSlug = strtolower((string)$right['slug']);
            $leftRank = $categoryOrder[$leftSlug] ?? (100 + (int)$left['sort_order']);
            $rightRank = $categoryOrder[$rightSlug] ?? (100 + (int)$right['sort_order']);
            return $leftRank <=> $rightRank ?: strcasecmp((string)$left['name'], (string)$right['name']);
        });

        $catalogStmt = $db->prepare(
            'SELECT id, category_id, entry_type, value, normalized_value, port, protocol
             FROM traffic_catalog
             WHERE status = "ACTIVE" AND category_id = ?
             ORDER BY id'
        );
        foreach ($categories as $category) {
            $categoryId = (int)$category['id'];
            $catalogStmt->execute([$categoryId]);
            $catalogByCategory[$categoryId] = $catalogStmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $policyStmt = $db->prepare(
            'SELECT tp.category_id, tp.isp_id
             FROM traffic_policies tp
             WHERE tp.organization_id = ? AND tp.router_id = ?'
        );
        $policyStmt->execute([$organizationId, $routerId]);
        foreach ($policyStmt->fetchAll(PDO::FETCH_ASSOC) as $policy) {
            $existingIspByCategory[(int)$policy['category_id']] = (int)$policy['isp_id'];
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$interfaces = [];
foreach (($data['interfaces'] ?? []) as $row) {
    $name = trim((string)($row['name'] ?? ''));
    if ($name !== '' && strtolower((string)($row['disabled'] ?? 'no')) !== 'yes') {
        $interfaces[] = $name;
    }
}

$defaultRoutes = [];
foreach (($data['routes'] ?? []) as $row) {
    if ((string)($row['dst-address'] ?? '') !== '0.0.0.0/0'
        || strtolower((string)($row['disabled'] ?? 'no')) === 'yes') {
        continue;
    }
    $gateway = trim((string)($row['gateway'] ?? $row['immediate-gw'] ?? ''));
    $interface = trim((string)($row['interface'] ?? ''));
    if ($interface === '' && strpos($gateway, '%') !== false) {
        [, $interface] = explode('%', $gateway, 2);
    }
    if ($interface !== '') {
        $defaultRoutes[strtolower($interface)] = $gateway;
    }
}

$routerVersion = (string)($data['resource'][0]['version'] ?? '7.0');
$isRouterOs7 = version_compare($routerVersion, '7.0', '>=');

function ts_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function ts_ip_with_prefix(array $profile): string
{
    $ip = trim((string)($profile['ip_address'] ?? ''));
    $subnet = trim((string)($profile['subnet'] ?? ''));
    if ($ip === '' || strpos($ip, '/') !== false || $subnet === '') {
        return $ip;
    }
    if (ctype_digit($subnet) && (int)$subnet <= 32) {
        return $ip . '/' . $subnet;
    }
    if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $bits = 0;
        foreach (explode('.', $subnet) as $octet) {
            $bits += substr_count(decbin((int)$octet), '1');
        }
        return $ip . '/' . $bits;
    }
    return $ip;
}

function ts_valid_ipv4_cidr(string $value): bool
{
    $parts = explode('/', $value, 2);
    if (count($parts) > 2 || filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
        return false;
    }
    return count($parts) === 1 || (ctype_digit($parts[1]) && (int)$parts[1] >= 0 && (int)$parts[1] <= 32);
}

function ts_slug(string $value): string
{
    $slug = strtoupper(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $value), '-'));
    return $slug !== '' ? $slug : 'TRAFFIC';
}

$ispInputs = [];
$queryIsps = is_array($_POST['isps'] ?? null) ? $_POST['isps'] : (is_array($_GET['isps'] ?? null) ? $_GET['isps'] : []);
for ($index = 0; $index < 2; $index++) {
    $profile = $ispProfiles[$index] ?? [];
    $queryIsp = is_array($queryIsps[$index] ?? null) ? $queryIsps[$index] : [];
    $defaultInterface = (string)($profile['interface_name'] ?? ($interfaces[$index] ?? ''));
    $ispInputs[$index] = [
        'name' => (string)($profile['name'] ?? ('Koneksi ' . ($index + 1))),
        'interface' => trim((string)($queryIsp['interface'] ?? $defaultInterface)),
        'ip_address' => trim((string)($queryIsp['ip_address'] ?? ts_ip_with_prefix($profile))),
        'gateway' => trim((string)($queryIsp['gateway'] ?? ($profile['gateway'] ?? ($defaultRoutes[strtolower($defaultInterface)] ?? '')))),
    ];
}

$policyInputs = [];
$extraAddressInputs = [];
$queryPolicies = is_array($_POST['policies'] ?? null) ? $_POST['policies'] : (is_array($_GET['policies'] ?? null) ? $_GET['policies'] : []);
$queryExtras = is_array($_POST['addresslists'] ?? null) ? $_POST['addresslists'] : (is_array($_GET['addresslists'] ?? null) ? $_GET['addresslists'] : []);
foreach ($categories as $category) {
    $categoryId = (int)$category['id'];
    $queryPolicy = is_array($queryPolicies[$categoryId] ?? null) ? $queryPolicies[$categoryId] : [];
    $existingIndex = 0;
    foreach ($ispProfiles as $ispIndex => $profile) {
        if ((int)($profile['id'] ?? 0) === (int)($existingIspByCategory[$categoryId] ?? 0)) {
            $existingIndex = $ispIndex;
            break;
        }
    }
    $policyInputs[$categoryId] = (string)($queryPolicy['connection'] ?? (string)($existingIndex + 1));
    $extraAddressInputs[$categoryId] = (string)($queryExtras[$categoryId] ?? '');
}

$script = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    if (count($ispProfiles) < 2) {
        $error = 'Router ini harus memiliki dua ISP aktif pada menu WAN / ISP sebelum script dapat dibuat.';
    }

    foreach ($ispInputs as $index => $isp) {
        if (!in_array($isp['interface'], $interfaces, true)) {
            $error = 'Interface WAN koneksi ' . ($index + 1) . ' tidak valid.';
            break;
        }
        if (!ts_valid_ipv4_cidr($isp['ip_address'])) {
            $error = 'IP address koneksi ' . ($index + 1) . ' harus IPv4 dengan prefix, misalnya 192.0.2.2/24.';
            break;
        }
        if (filter_var($isp['gateway'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $error = 'IP gateway koneksi ' . ($index + 1) . ' tidak valid.';
            break;
        }
    }
    if ($error === '' && strcasecmp($ispInputs[0]['interface'], $ispInputs[1]['interface']) === 0) {
        $error = 'Koneksi 1 dan koneksi 2 harus memakai interface WAN yang berbeda.';
    }

    foreach ($categories as $category) {
        $categoryId = (int)$category['id'];
        if (!in_array($policyInputs[$categoryId] ?? '', ['1', '2'], true)) {
            $error = 'Pilihan koneksi untuk kategori ' . (string)$category['name'] . ' tidak valid.';
            break;
        }
        $extra = trim($extraAddressInputs[$categoryId] ?? '');
        if ($extra === '') {
            continue;
        }
        foreach (preg_split('/[\s,;]+/', $extra) ?: [] as $cidr) {
            if ($cidr !== '' && !ts_valid_ipv4_cidr($cidr)) {
                $error = 'Address List tambahan kategori ' . (string)$category['name'] . ' harus berisi IPv4/CIDR yang valid.';
                break 2;
            }
        }
    }

    if ($error === '') {
        $quote = static function (string $value): string {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        };
        $marker = 'BAJAMA:EGA:SPLIT';
        $scriptLines = [
            '# Pisah trafik berdasarkan kategori aplikasi - tepat dua routing mark',
            '# RouterOS ' . ($isRouterOs7 ? '7+' : '6.x'),
            '# Bersihkan rule lama BAJAMA Channel yang memakai mark tunggal',
            '/ip firewall mangle remove [find where comment~"BAJAMA:TRAFFIC:CHANNEL"]',
            '/ip route remove [find where comment~"BAJAMA:TRAFFIC:CHANNEL"]',
            '/ip firewall address-list remove [find where comment~"' . $marker . '"]',
            '/ip firewall mangle remove [find where comment~"' . $marker . '"]',
            '/ip route remove [find where comment~"' . $marker . ':ROUTE"]',
        ];
        if ($isRouterOs7) {
            $scriptLines[] = '/routing table remove [find where name="BAJAMA-CHANNEL"]';
        }

        foreach ($ispInputs as $index => $isp) {
            $number = $index + 1;
            $table = 'BAJAMA-EGA-ISP' . $number;
            $gateway = $isp['gateway'] . '%' . $isp['interface'];
            $scriptLines[] = ':if ([:len [/ip address find where address=' . $quote($isp['ip_address'])
                . ' and interface=' . $quote($isp['interface']) . ']] = 0) do={/ip address add address='
                . $quote($isp['ip_address']) . ' interface=' . $quote($isp['interface']) . ' comment='
                . $quote($marker . ':ADDRESS:ISP' . $number) . '}';
            if ($isRouterOs7) {
                $scriptLines[] = ':if ([:len [/routing table find where name=' . $quote($table)
                    . ']] = 0) do={/routing table add name=' . $quote($table) . ' fib=yes comment='
                    . $quote($marker . ':TABLE:ISP' . $number) . '}';
                $scriptLines[] = '/ip route add dst-address=0.0.0.0/0 gateway=' . $quote($gateway)
                    . ' routing-table=' . $quote($table) . ' distance=1 comment='
                    . $quote($marker . ':ROUTE:ISP' . $number);
            } else {
                $scriptLines[] = '/ip route add dst-address=0.0.0.0/0 gateway=' . $quote($gateway)
                    . ' routing-mark=' . $quote($table) . ' distance=1 comment='
                    . $quote($marker . ':ROUTE:ISP' . $number);
            }
        }

        foreach ($categories as $category) {
            $categoryId = (int)$category['id'];
            $categorySlug = ts_slug((string)$category['slug']);
            $addressList = 'BAJAMA-EGA-' . $categorySlug;
            $routingMark = 'BAJAMA-EGA-ISP' . (int)$policyInputs[$categoryId];
            $commentPrefix = $marker . ':' . $categorySlug;
            $addressCount = 0;

            foreach ($catalogByCategory[$categoryId] ?? [] as $entry) {
                $entryType = strtoupper((string)$entry['entry_type']);
                $value = trim((string)($entry['normalized_value'] ?: $entry['value']));
                if ($value === '') {
                    continue;
                }
                if (in_array($entryType, ['DOMAIN', 'HOST', 'IP', 'CIDR'], true)) {
                    $scriptLines[] = '/ip firewall address-list add list=' . $quote($addressList)
                        . ' address=' . $quote($value) . ' comment=' . $quote($commentPrefix . ':CATALOG:' . (int)$entry['id']);
                    $addressCount++;
                }
            }

            foreach (preg_split('/[\s,;]+/', trim($extraAddressInputs[$categoryId] ?? '')) ?: [] as $extraIndex => $cidr) {
                if ($cidr === '') {
                    continue;
                }
                $scriptLines[] = '/ip firewall address-list add list=' . $quote($addressList)
                    . ' address=' . $quote($cidr) . ' comment=' . $quote($commentPrefix . ':EXTRA:' . ($extraIndex + 1));
                $addressCount++;
            }

            if ($addressCount > 0) {
                $scriptLines[] = '/ip firewall mangle add chain=prerouting dst-address-list=' . $quote($addressList)
                    . ' action=mark-routing new-routing-mark=' . $quote($routingMark)
                    . ' passthrough=no comment=' . $quote($commentPrefix . ':ADDRESS-MANGLE');
            }

            foreach ($catalogByCategory[$categoryId] ?? [] as $entry) {
                if (strtoupper((string)$entry['entry_type']) !== 'PORT') {
                    continue;
                }
                $portValue = trim((string)($entry['normalized_value'] ?: $entry['value']));
                if (!preg_match('/^(\d{1,5})(?:-(\d{1,5}))?$/', $portValue, $portMatch)) {
                    continue;
                }
                $firstPort = (int)$portMatch[1];
                $lastPort = isset($portMatch[2]) && $portMatch[2] !== ''
                    ? (int)$portMatch[2]
                    : $firstPort;
                if ($firstPort < 1 || $lastPort > 65535 || $firstPort > $lastPort) {
                    continue;
                }
                $portExpression = $firstPort === $lastPort
                    ? (string)$firstPort
                    : $firstPort . '-' . $lastPort;
                $line = '/ip firewall mangle add chain=prerouting dst-port=' . $quote($portExpression);
                $protocol = strtoupper((string)($entry['protocol'] ?? 'ANY'));
                if (in_array($protocol, ['TCP', 'UDP'], true)) {
                    $line .= ' protocol=' . strtolower($protocol);
                }
                if ($addressCount > 0) {
                    $line .= ' dst-address-list=' . $quote($addressList);
                }
                $scriptLines[] = $line . ' action=mark-routing new-routing-mark=' . $quote($routingMark)
                    . ' passthrough=no comment=' . $quote($commentPrefix . ':PORT:' . (int)$entry['id']);
            }
        }
        $script = implode("\n", $scriptLines) . "\n";
    }
}

$pageTitle = 'Pisah Trafik';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="mb-4">
        <h1 class="fw-bold">Pisah Trafik per Kategori</h1>
        <p class="text-muted mb-0">
            Pilih koneksi untuk Sosmed, Stream, Game, Bank, E-Wallet, Chat, dan kategori aktif lainnya. Domain/host serta port game diambil dari Traffic Catalog; CIDR tambahan bersifat opsional.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= ts_h($error) ?></div>
    <?php endif; ?>

    <?php if (!$routers): ?>
        <div class="alert alert-warning">Belum ada router MikroTik.</div>
    <?php else: ?>
        <?php if (count($ispProfiles) < 2): ?>
            <div class="alert alert-warning">Tambahkan dan aktifkan dua ISP di menu WAN / ISP agar koneksi 1 dan 2 dapat dipakai.</div>
        <?php endif; ?>
        <form class="card border-0 shadow-sm p-3 mb-4" method="post">
            <input type="hidden" name="csrf" value="<?= ts_h($csrf) ?>">
            <div class="row g-3 align-items-end mb-3">
                <div class="col-lg-5">
                    <label class="form-label">Router MikroTik</label>
                    <select name="router_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($routers as $router): ?>
                            <option value="<?= (int)$router['id'] ?>" <?= $routerId === (int)$router['id'] ? 'selected' : '' ?>><?= ts_h($router['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <h2 class="h5 mb-3">Dua koneksi internet</h2>
            <div class="row g-3">
                <?php foreach ($ispInputs as $index => $isp): ?>
                    <div class="col-lg-6">
                        <div class="border rounded p-3 h-100">
                            <h3 class="h6">Koneksi <?= $index + 1 ?> — <?= ts_h($isp['name']) ?></h3>
                            <label class="form-label">Interface WAN</label>
                            <select name="isps[<?= $index ?>][interface]" class="form-select mb-2" required>
                                <?php foreach ($interfaces as $interface): ?>
                                    <option value="<?= ts_h($interface) ?>" <?= strcasecmp($isp['interface'], $interface) === 0 ? 'selected' : '' ?>><?= ts_h($interface) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="form-label">IP address / prefix</label>
                            <input name="isps[<?= $index ?>][ip_address]" class="form-control mb-2" value="<?= ts_h($isp['ip_address']) ?>" placeholder="192.0.2.2/24" required>
                            <label class="form-label">IP gateway</label>
                            <input name="isps[<?= $index ?>][gateway]" class="form-control" value="<?= ts_h($isp['gateway']) ?>" placeholder="192.0.2.1" required>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <hr class="my-4">
            <div class="d-flex flex-wrap align-items-end justify-content-between gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Arah trafik kategori</h2>
                    <div class="small text-muted">Kolom CIDR tambahan boleh dikosongkan; bila kosong, tidak ada CIDR tambahan yang dimasukkan.</div>
                </div>
                <div class="small text-muted">Hanya ada routing mark BAJAMA-EGA-ISP1 dan BAJAMA-EGA-ISP2.</div>
            </div>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Kategori</th>
                            <th>Domain / host</th>
                            <th>Port</th>
                            <th>Address List tambahan (IP/CIDR, opsional)</th>
                            <th style="min-width: 190px">Arahkan ke koneksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $category): ?>
                        <?php
                        $categoryId = (int)$category['id'];
                        $domainCount = 0;
                        $portCount = 0;
                        foreach ($catalogByCategory[$categoryId] ?? [] as $entry) {
                            if (in_array(strtoupper((string)$entry['entry_type']), ['DOMAIN', 'HOST'], true)) {
                                $domainCount++;
                            }
                            if (strtoupper((string)$entry['entry_type']) === 'PORT') {
                                $portCount++;
                            }
                        }
                        ?>
                        <tr>
                            <td><strong><?= ts_h($category['name']) ?></strong><div class="small text-muted"><?= ts_h($category['slug']) ?></div></td>
                            <td><?= $domainCount ?> katalog aktif</td>
                            <td><?= $portCount ?> katalog aktif</td>
                            <td><input name="addresslists[<?= $categoryId ?>]" class="form-control" value="<?= ts_h($extraAddressInputs[$categoryId] ?? '') ?>" placeholder="203.0.113.0/24; 198.51.100.5"></td>
                            <td>
                                <select name="policies[<?= $categoryId ?>][connection]" class="form-select">
                                    <option value="1" <?= ($policyInputs[$categoryId] ?? '1') === '1' ? 'selected' : '' ?>>Koneksi 1<?= isset($ispProfiles[0]) ? ' — ' . ts_h($ispProfiles[0]['name']) : '' ?></option>
                                    <option value="2" <?= ($policyInputs[$categoryId] ?? '1') === '2' ? 'selected' : '' ?>>Koneksi 2<?= isset($ispProfiles[1]) ? ' — ' . ts_h($ispProfiles[1]['name']) : '' ?></option>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$categories): ?>
                        <tr><td colspan="5" class="text-center text-muted">Belum ada kategori aktif di Traffic Catalog.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="d-flex justify-content-end mt-3">
                <button type="submit" name="generate" value="1" class="btn btn-primary">Buat Script Pisah Trafik</button>
            </div>
        </form>

        <?php if ($script !== ''): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Script RouterOS <?= $isRouterOs7 ? '7+' : '6.x' ?></h2>
                    <p class="small text-muted">Script membangun address-list dari semua domain/host/IP/CIDR katalog aktif dan rule port dari katalog Game, lalu mengarahkan tiap kategori ke koneksi yang dipilih. Tinjau sebelum ditempel ke MikroTik.</p>
                    <textarea class="form-control font-monospace" rows="24" readonly><?= ts_h($script) ?></textarea>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
require __DIR__ . '/../app/layout/layout.php';
