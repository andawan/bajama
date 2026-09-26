<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\RouterOSProvisioningService;

Auth::requireLogin();
$db = db();
License::requireFeature($db, 'mikrotik');
RBAC::require($db, 'network.view');
$organizationId = (int)Tenant::id();
$canManage = RBAC::hasPermission($db, 'network.manage');
$message = '';
$error = '';
$selectedRouterId = (int)($_GET['router_id'] ?? $_POST['router_id'] ?? 0);

function hs_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$stmt = $db->prepare('SELECT id,name,host,status FROM mikrotik_routers WHERE organization_id=? ORDER BY name');
$stmt->execute([$organizationId]);
$routers = $stmt->fetchAll(PDO::FETCH_ASSOC);
if ($selectedRouterId <= 0 && $routers) {
    $selectedRouterId = (int)$routers[0]['id'];
}

$router = $selectedRouterId > 0 ? MikroTik::find($db, $organizationId, $selectedRouterId) : null;
$profiles = [];
$hotspotServers = [];
$routerError = '';
if ($router) {
    try {
        $api = MikroTik::connect($router);
        try {
            $profiles = $api->raw('/ip/hotspot/user/profile/print');
            $hotspotServers = $api->hotspotServers();
        } finally {
            $api->disconnect();
        }
    } catch (Throwable $exception) {
        $routerError = $exception->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        verify_csrf((string)($_POST['_csrf'] ?? $_POST['csrf'] ?? ''));
        if (!$canManage) {
            throw new RuntimeException('Anda tidak memiliki izin mengelola hotspot.');
        }
        $action = trim((string)($_POST['action'] ?? ''));
        $routerId = (int)($_POST['router_id'] ?? 0);
        $router = MikroTik::find($db, $organizationId, $routerId);
        if (!$router) {
            throw new RuntimeException('Router MikroTik tidak valid.');
        }
        $service = new RouterOSProvisioningService($db, $organizationId);

        if (in_array($action, ['profile_save', 'profile_delete'], true)) {
            $api = MikroTik::connect($router);
            try {
                if ($action === 'profile_delete') {
                    $profileId = trim((string)($_POST['profile_id'] ?? ''));
                    if ($profileId === '' || $profileId[0] !== '*') {
                        throw new RuntimeException('ID profile RouterOS tidak valid.');
                    }
                    $api->raw('/ip/hotspot/user/profile/remove', ['.id' => $profileId]);
                    $message = 'User profile berhasil dihapus dari MikroTik.';
                } else {
                    $profileId = trim((string)($_POST['profile_id'] ?? ''));
                    $attributes = [
                        'name' => trim((string)($_POST['profile_name'] ?? '')),
                        'rate-limit' => trim((string)($_POST['rate_limit'] ?? '')),
                        'shared-users' => max(1, (int)($_POST['shared_users'] ?? 1)),
                        'session-timeout' => trim((string)($_POST['session_timeout'] ?? '')),
                        'idle-timeout' => trim((string)($_POST['idle_timeout'] ?? '')),
                        'keepalive-timeout' => trim((string)($_POST['keepalive_timeout'] ?? '')),
                    ];
                    if ($attributes['name'] === '') {
                        throw new RuntimeException('Nama profile wajib diisi.');
                    }
                    $attributes = array_filter($attributes, static fn($value) => $value !== '');
                    if ($profileId !== '') {
                        if ($profileId[0] !== '*') {
                            throw new RuntimeException('ID profile RouterOS tidak valid.');
                        }
                        $attributes['.id'] = $profileId;
                        $api->raw('/ip/hotspot/user/profile/set', $attributes);
                        $message = 'User profile berhasil diperbarui di MikroTik.';
                    } else {
                        $api->raw('/ip/hotspot/user/profile/add', $attributes);
                        $message = 'User profile berhasil ditambahkan ke MikroTik.';
                    }
                }
            } finally {
                $api->disconnect();
            }
        } elseif (in_array($action, ['delete', 'toggle'], true)) {
            $id = (int)($_POST['id'] ?? 0);
            $check = $db->prepare('SELECT enabled FROM network_hotspot_vouchers WHERE id=? AND organization_id=? LIMIT 1');
            $check->execute([$id, $organizationId]);
            $currentEnabled = (int)$check->fetchColumn();
            if ($action === 'delete') {
                $service->remove('network_hotspot_voucher', $id);
                $db->prepare('DELETE FROM network_hotspot_vouchers WHERE id=? AND organization_id=?')->execute([$id, $organizationId]);
                $message = 'Voucher dan user Hotspot berhasil dihapus.';
            } else {
                $db->prepare('UPDATE network_hotspot_vouchers SET enabled=CASE WHEN enabled=1 THEN 0 ELSE 1 END,status=CASE WHEN enabled=1 THEN "DISABLED" ELSE "READY" END WHERE id=? AND organization_id=?')->execute([$id, $organizationId]);
                $service->setEnabled('network_hotspot_voucher', $id, !$currentEnabled);
                $message = 'Status voucher berhasil diubah.';
            }
        } elseif (in_array($action, ['generate', 'save'], true)) {
            $profile = trim((string)($_POST['profile'] ?? 'default')) ?: 'default';
            $server = trim((string)($_POST['hotspot_server'] ?? '')) ?: null;
            $dnsName = trim((string)($_POST['hotspot_dns_name'] ?? '')) ?: null;
            $expiresRaw = trim((string)($_POST['expires_at'] ?? ''));
            $expiresAt = $expiresRaw !== '' ? date('Y-m-d H:i:s', strtotime($expiresRaw)) : null;
            $insert = $db->prepare('INSERT INTO network_hotspot_vouchers (organization_id,router_id,profile,username,password,address,mac_address,limit_uptime,limit_bytes_total,hotspot_server,hotspot_dns_name,status,expires_at,note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            if ($action === 'generate') {
                $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['prefix'] ?? 'VCH')) ?: 'VCH';
                $quantity = max(1, min(500, (int)($_POST['quantity'] ?? 1)));
                $uptime = trim((string)($_POST['limit_uptime'] ?? '')) ?: null;
                $bytes = trim((string)($_POST['limit_bytes_total'] ?? ''));
                $bytes = $bytes === '' ? null : max(1, (int)$bytes);
                for ($n = 0; $n < $quantity; $n++) {
                    $username = $prefix . '-' . strtoupper(bin2hex(random_bytes(3)));
                    $insert->execute([$organizationId, $routerId, $profile, $username, $username, null, null, $uptime, $bytes, $server, $dnsName, 'READY', $expiresAt, 'Generated by BAJAMA']);
                }
                $message = $quantity . ' voucher berhasil dibuat.';
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $username = trim((string)($_POST['username'] ?? ''));
                $address = trim((string)($_POST['address'] ?? ''));
                $mac = strtoupper(trim((string)($_POST['mac_address'] ?? '')));
                $uptime = trim((string)($_POST['limit_uptime'] ?? '')) ?: null;
                $bytes = trim((string)($_POST['limit_bytes_total'] ?? ''));
                $bytes = $bytes === '' ? null : max(1, (int)$bytes);
                $status = strtoupper(trim((string)($_POST['status'] ?? 'READY')));
                if ($username === '' || !in_array($status, ['READY', 'USED', 'EXPIRED', 'DISABLED'], true)) {
                    throw new RuntimeException('Username dan status voucher wajib valid.');
                }
                if ($address !== '' && filter_var($address, FILTER_VALIDATE_IP) === false) {
                    throw new RuntimeException('IP binding tidak valid.');
                }
                if ($mac !== '' && !preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) {
                    throw new RuntimeException('MAC binding tidak valid.');
                }
                $values = [$routerId, $profile, $username, $username, $address ?: null, $mac ?: null, $uptime, $bytes, $server, $dnsName, $status, $expiresAt];
                if ($id > 0) {
                    $db->prepare('UPDATE network_hotspot_vouchers SET router_id=?,profile=?,username=?,password=?,address=?,mac_address=?,limit_uptime=?,limit_bytes_total=?,hotspot_server=?,hotspot_dns_name=?,status=?,expires_at=? WHERE id=? AND organization_id=?')->execute([...$values, $id, $organizationId]);
                } else {
                    $insert->execute([$organizationId, ...$values, 'Manual by BAJAMA']);
                    $id = (int)$db->lastInsertId();
                }
                $message = 'Voucher berhasil disimpan.';
            }
        } else {
            throw new RuntimeException('Aksi Hotspot tidak dikenal.');
        }
    } catch (Throwable $exception) {
        $error = $exception->getMessage();
    }
}

$stmt = $db->prepare('SELECT v.*,r.name router_name FROM network_hotspot_vouchers v INNER JOIN mikrotik_routers r ON r.id=v.router_id AND r.organization_id=v.organization_id WHERE v.organization_id=? ORDER BY v.id DESC');
$stmt->execute([$organizationId]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
$csrf = csrf_token();
$pageTitle = 'Hotspot & Vouchers';
$networkLayout = true;
$networkActive = 'hotspot';
ob_start();
?>
<div class="container-fluid py-4">
    <div class="mb-4"><h1>Hotspot & Voucher</h1><p class="text-muted">Username dan password voucher sama persis.</p></div>
    <?php if ($message): ?><div class="alert alert-success"><?= hs_h($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= hs_h($error) ?></div><?php endif; ?>
    <?php if ($routerError): ?><div class="alert alert-warning">Router profile/DNS belum dapat dibaca: <?= hs_h($routerError) ?></div><?php endif; ?>
    <div class="card mb-4"><div class="card-body"><form method="get" class="row g-2 align-items-end"><div class="col-md-5"><label class="form-label">Router aktif</label><select name="router_id" class="form-select" onchange="this.form.submit()"><?php foreach ($routers as $item): ?><option value="<?= (int)$item['id'] ?>" <?= $selectedRouterId === (int)$item['id'] ? 'selected' : '' ?>><?= hs_h($item['name'] . ' - ' . $item['host']) ?></option><?php endforeach; ?></select></div><div class="col-md-7 small text-muted">Profile dan DNS Hotspot diambil dari router yang dipilih.</div></form></div></div>
+    <div class="card mb-4"><div class="card-body"><h2 class="h5">Generate Voucher</h2><form method="post" class="row g-3"><input type="hidden" name="_csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="generate"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><div class="col-md-3"><label class="form-label">Profile aktif</label><select class="form-select" name="profile" required><?php foreach ($profiles as $profile): ?><option value="<?= hs_h($profile['name'] ?? '') ?>"><?= hs_h($profile['name'] ?? '') ?></option><?php endforeach; ?></select></div><div class="col-md-3"><label class="form-label">DNS Hotspot</label><select class="form-select" name="hotspot_server" onchange="setDns(this)"><option value="">Semua server</option><?php foreach ($hotspotServers as $server): ?><option value="<?= hs_h($server['name'] ?? '') ?>" data-dns="<?= hs_h($server['dns-name'] ?? '') ?>"><?= hs_h(($server['name'] ?? '') . ' - ' . ($server['dns-name'] ?? 'tanpa DNS')) ?></option><?php endforeach; ?></select><input type="hidden" name="hotspot_dns_name" id="generateDns"></div><div class="col-md-2"><label class="form-label">Prefix</label><input class="form-control" name="prefix" value="VCH"></div><div class="col-md-1"><label class="form-label">Jumlah</label><input class="form-control" type="number" name="quantity" min="1" max="500" value="10"></div><div class="col-md-2"><label class="form-label">Uptime</label><input class="form-control" name="limit_uptime" placeholder="1d 00:00:00"></div><div class="col-md-1 d-flex align-items-end"><button class="btn btn-primary" <?= $canManage ? '' : 'disabled' ?>>Generate</button></div></form></div></div>
+    <div class="card mb-4"><div class="card-body"><h2 class="h5">User Voucher Manual</h2><form method="post" class="row g-3"><input type="hidden" name="_csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="save"><input type="hidden" name="id" value="0"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><div class="col-md-2"><label class="form-label">Profile</label><select class="form-select" name="profile" required><?php foreach ($profiles as $profile): ?><option value="<?= hs_h($profile['name'] ?? '') ?>"><?= hs_h($profile['name'] ?? '') ?></option><?php endforeach; ?></select></div><div class="col-md-2"><label class="form-label">Username</label><input class="form-control" name="username" required></div><div class="col-md-2"><label class="form-label">Password</label><input class="form-control" value="Sama dengan username" readonly></div><div class="col-md-2"><label class="form-label">IP Binding</label><input class="form-control" name="address"></div><div class="col-md-2"><label class="form-label">MAC Binding</label><input class="form-control" name="mac_address" placeholder="AA:BB:CC:DD:EE:FF"></div><div class="col-md-2 d-flex align-items-end"><button class="btn btn-outline-primary w-100" <?= $canManage ? '' : 'disabled' ?>>Simpan</button></div><div class="col-md-3"><label class="form-label">DNS Hotspot</label><select class="form-select" name="hotspot_server" onchange="setDns(this)"><option value="">Semua server</option><?php foreach ($hotspotServers as $server): ?><option value="<?= hs_h($server['name'] ?? '') ?>" data-dns="<?= hs_h($server['dns-name'] ?? '') ?>"><?= hs_h(($server['name'] ?? '') . ' - ' . ($server['dns-name'] ?? 'tanpa DNS')) ?></option><?php endforeach; ?></select><input type="hidden" name="hotspot_dns_name" id="manualDns"></div></form></div></div>
+    <div class="card mb-4"><div class="card-body"><h2 class="h5">User Profile MikroTik</h2><form method="post" class="row g-2"><input type="hidden" name="_csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="profile_save"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><input type="hidden" name="profile_id" id="profileId"><div class="col-md-2"><input class="form-control" name="profile_name" id="profileName" placeholder="Nama profile" required></div><div class="col-md-2"><input class="form-control" name="rate_limit" placeholder="Rate limit, contoh 2M/10M"></div><div class="col-md-1"><input class="form-control" type="number" min="1" name="shared_users" value="1" title="Shared users"></div><div class="col-md-2"><input class="form-control" name="session_timeout" placeholder="Session timeout"></div><div class="col-md-2"><input class="form-control" name="idle_timeout" placeholder="Idle timeout"></div><div class="col-md-2"><input class="form-control" name="keepalive_timeout" placeholder="Keepalive timeout"></div><div class="col-md-1"><button class="btn btn-outline-primary w-100">Simpan</button></div></form><div class="table-responsive mt-3"><table class="table table-sm"><thead><tr><th>Profile</th><th>Rate</th><th>Shared</th><th>Session</th><th>Aksi</th></tr></thead><tbody><?php foreach ($profiles as $profile): ?><tr><td><?= hs_h($profile['name'] ?? '') ?></td><td><?= hs_h($profile['rate-limit'] ?? '-') ?></td><td><?= hs_h($profile['shared-users'] ?? '-') ?></td><td><?= hs_h($profile['session-timeout'] ?? '-') ?></td><td><button type="button" class="btn btn-sm btn-outline-secondary" onclick='editProfile(<?= json_encode($profile, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>)'>Edit</button><form class="d-inline" method="post"><input type="hidden" name="_csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="action" value="profile_delete"><input type="hidden" name="router_id" value="<?= $selectedRouterId ?>"><input type="hidden" name="profile_id" value="<?= hs_h($profile['.id'] ?? '') ?>"><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus profile RouterOS?')">Hapus</button></form></td></tr><?php endforeach; ?></tbody></table></div></div></div>
+    <div class="card"><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th>User</th><th>Password</th><th>Profile</th><th>Link Voucher</th><th>Router</th><th>Status</th><th>Aksi</th></tr></thead><tbody><?php foreach ($items as $item): $link = ($item['hotspot_dns_name'] ? 'http://' . $item['hotspot_dns_name'] . '/login?username=' . rawurlencode($item['username']) . '&password=' . rawurlencode($item['password']) : ''); ?><tr><td><code><?= hs_h($item['username']) ?></code></td><td><code><?= hs_h($item['password']) ?></code></td><td><?= hs_h($item['profile']) ?></td><td><?php if ($link): ?><a href="<?= hs_h($link) ?>" target="_blank"><?= hs_h($link) ?></a><?php else: ?>- <?php endif; ?></td><td><?= hs_h($item['router_name']) ?></td><td><?= $item['enabled'] ? 'ENABLED' : 'DISABLED' ?> / <?= hs_h($item['status']) ?></td><td class="text-nowrap"><button type="button" class="btn btn-sm btn-outline-info qr-button" data-text="<?= hs_h($link ?: ('username=' . $item['username'] . '&password=' . $item['password'])) ?>">QR</button><form class="d-inline" method="post"><input type="hidden" name="_csrf" value="<?= hs_h($csrf) ?>"><input type="hidden" name="router_id" value="<?= (int)$item['router_id'] ?>"><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><button name="action" value="toggle" class="btn btn-sm btn-outline-warning">Enable</button><button name="action" value="delete" class="btn btn-sm btn-outline-danger" onclick="return confirm('Hapus voucher?')">Hapus</button></form></td></tr><?php endforeach; ?><?php if (!$items): ?><tr><td colspan="7" class="text-center text-muted py-4">Belum ada voucher.</td></tr><?php endif; ?></tbody></table></div></div>
+</div>
+<div class="modal fade" id="qrModal" tabindex="-1"><div class="modal-dialog modal-sm modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">QR Voucher</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body text-center"><div id="qrCode" class="d-flex justify-content-center mb-2"></div><div id="qrText" class="small text-break"></div></div></div></div></div>
+<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script><script>function setDns(select){var option=select.options[select.selectedIndex];var target=select.form.querySelector('input[name="hotspot_dns_name"]');if(target)target.value=option.dataset.dns||'';}function editProfile(p){document.getElementById('profileId').value=p['.id']||'';document.getElementById('profileName').value=p.name||'';}document.querySelectorAll('.qr-button').forEach(function(b){b.onclick=function(){document.getElementById('qrCode').innerHTML='';new QRCode(document.getElementById('qrCode'),{text:b.dataset.text,width:220,height:220});document.getElementById('qrText').textContent=b.dataset.text;bootstrap.Modal.getOrCreateInstance(document.getElementById('qrModal')).show();};});</script>
<?php $content = str_replace("\n+", "\n", ob_get_clean()); $networkLayout = false; require __DIR__ . '/../app/layout/layout.php';
