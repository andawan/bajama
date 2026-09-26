<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\Tenant;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\License;

Auth::requireLogin();
License::requireFeature(\db(), 'dashboard');

/* BAJAMA_RBAC_ENFORCED */
RBAC::require($db, 'dashboard.view');

$org = Tenant::organization($db);
$roles = RBAC::roles($db);
$license = License::current($db);
$licenseStatus = License::status($db);

$stmt = $db->prepare(
    'SELECT COUNT(*)
     FROM customers
     WHERE organization_id = ?'
);
$stmt->execute([Tenant::id()]);
$customerCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare(
    'SELECT COUNT(*)
     FROM subscriptions
     WHERE organization_id = ?'
);
$stmt->execute([Tenant::id()]);
$subscriptionCount = (int)$stmt->fetchColumn();

$stmt = $db->prepare(
    'SELECT COUNT(*)
     FROM invoices
     WHERE organization_id = ?'
);
$stmt->execute([Tenant::id()]);
$invoiceCount = (int)$stmt->fetchColumn();

function e($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>BAJAMA Core</title>

<link
href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/css/bootstrap.min.css"
rel="stylesheet">

<link
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"
rel="stylesheet">

<style>
body {
    background:#f5f7fb;
}

.sidebar {
    min-height:100vh;
    background:#111827;
}

.sidebar a {
    color:#cbd5e1;
    text-decoration:none;
    display:block;
    padding:.7rem 1rem;
    border-radius:.5rem;
    margin-bottom:.25rem;
}

.sidebar a:hover {
    background:#1f2937;
    color:#fff;
}

.brand {
    font-size:1.4rem;
    font-weight:800;
    color:#fff;
}

.card {
    border:0;
    box-shadow:0 4px 20px rgba(15,23,42,.06);
}

.stat-number {
    font-size:1.8rem;
    font-weight:800;
}
</style>
</head>

<body>

<div class="container-fluid">
<div class="row">

<aside class="col-lg-2 col-md-3 sidebar p-3">

<div class="brand mb-4">
    BAJAMA
</div>

<div class="small text-secondary mb-3">
    Bangun Jaringan Bersama
</div>

<a href="core.php">
    <i class="bi bi-speedometer2 me-2"></i>
    Dashboard
</a>

<a href="#">
    <i class="bi bi-people me-2"></i>
    Customers
</a>

<a href="#">
    <i class="bi bi-receipt me-2"></i>
    Billing
</a>

<a href="#">
    <i class="bi bi-router me-2"></i>
    MikroTik
</a>

<a href="#">
    <i class="bi bi-diagram-3 me-2"></i>
    OLT / ONU
</a>

<a href="#">
    <i class="bi bi-activity me-2"></i>
    NOC
</a>

<hr class="border-secondary">

<a href="logout.php">
    <i class="bi bi-box-arrow-right me-2"></i>
    Logout
</a>

</aside>

<main class="col-lg-10 col-md-9 p-4">

<div class="d-flex justify-content-between align-items-center mb-4">

<div>
<h3 class="fw-bold mb-1">
Dashboard
</h3>

<div class="text-muted">
<?= e($org['name'] ?? 'BAJAMA ISP') ?>
</div>
</div>

<div class="text-end">
<div class="fw-semibold">
<?= e(Auth::username()) ?>
</div>

<div class="small text-muted">
<?= e(implode(', ', $roles)) ?>
</div>
</div>

</div>

<?php if ($licenseStatus === 'EXPIRED'): ?>
<div class="alert alert-danger">
<i class="bi bi-exclamation-triangle me-2"></i>
License BAJAMA sudah expired.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">

<div class="col-xl-3 col-md-6">
<div class="card p-4">
<div class="text-muted">Customers</div>
<div class="stat-number">
<?= $customerCount ?>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card p-4">
<div class="text-muted">Subscriptions</div>
<div class="stat-number">
<?= $subscriptionCount ?>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card p-4">
<div class="text-muted">Invoices</div>
<div class="stat-number">
<?= $invoiceCount ?>
</div>
</div>
</div>

<div class="col-xl-3 col-md-6">
<div class="card p-4">

<div class="text-muted">
License
</div>

<div class="stat-number">
<?= e($license['plan_name'] ?? 'NONE') ?>
</div>

<span class="badge
<?= $licenseStatus === 'EXPIRED'
    ? 'text-bg-danger'
    : 'text-bg-success' ?>">
<?= e($licenseStatus) ?>
</span>

</div>
</div>

</div>

<div class="row g-4">

<div class="col-lg-8">

<div class="card p-4">

<h5 class="fw-bold">
BAJAMA ISP Business & Network Operations Platform
</h5>

<p class="text-muted">
Building Networks Together
</p>

<div class="row g-3 mt-2">

<div class="col-md-4">
<div class="border rounded p-3">
<i class="bi bi-cash-stack fs-3"></i>
<div class="fw-bold mt-2">Billing</div>
<div class="small text-muted">
Customer, subscription, invoice & payment
</div>
</div>
</div>

<div class="col-md-4">
<div class="border rounded p-3">
<i class="bi bi-router fs-3"></i>
<div class="fw-bold mt-2">Network</div>
<div class="small text-muted">
MikroTik, PPPoE, Hotspot & Static
</div>
</div>
</div>

<div class="col-md-4">
<div class="border rounded p-3">
<i class="bi bi-diagram-3 fs-3"></i>
<div class="fw-bold mt-2">Fiber</div>
<div class="small text-muted">
OLT, ONU, GPON & provisioning
</div>
</div>
</div>

</div>

</div>

</div>

<div class="col-lg-4">

<div class="card p-4">

<h5 class="fw-bold mb-3">
License Information
</h5>

<table class="table table-sm">

<tr>
<td>Plan</td>
<td class="fw-semibold">
<?= e($license['plan_name'] ?? '-') ?>
</td>
</tr>

<tr>
<td>Status</td>
<td>
<span class="badge text-bg-success">
<?= e($licenseStatus) ?>
</span>
</td>
</tr>

<tr>
<td>Expires</td>
<td>
<?= e($license['expires_at'] ?? '-') ?>
</td>
</tr>

<tr>
<td>Max Customers</td>
<td>
<?= e($license['max_customers'] ?? '-') ?>
</td>
</tr>

<tr>
<td>Max Routers</td>
<td>
<?= e($license['max_routers'] ?? '-') ?>
</td>
</tr>

<tr>
<td>Max OLT</td>
<td>
<?= e($license['max_olts'] ?? '-') ?>
</td>
</tr>

</table>

</div>

</div>

</div>

</main>
</div>
</div>

<script
src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.8/dist/js/bootstrap.bundle.min.js">
</script>

</body>
</html>
