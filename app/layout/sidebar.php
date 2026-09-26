<?php
$currentPage = basename($_SERVER['PHP_SELF']);
?>

<?php
$db = db();
$currentRoles = \BAJAMA\Core\RBAC::roles($db);
$isSuperAdmin = in_array('SUPER_ADMIN', $currentRoles, true);
?>

<aside class="bajama-sidebar" id="bajamaSidebar">

    <div class="brand-area">

        <div class="brand-mark">
            <i class="bi bi-broadcast-pin"></i>
        </div>

        <div>
            <div class="brand-name">BAJAMA</div>
            <div class="brand-subtitle">Building Networks Together</div>
        </div>

    </div>

    <div class="sidebar-menu">

        <!-- =====================================================
             OVERVIEW
        ====================================================== -->

        <div class="menu-label">
            OVERVIEW
        </div>

        <a href="dashboard.php"
           class="menu-item <?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <i class="bi bi-grid-1x2-fill"></i>
            <span>Dashboard</span>
        </a>


        <?php if (!$isSuperAdmin): ?>
            <!-- =====================================================
                 BUSINESS
            ====================================================== -->

            <div class="menu-label">
                BUSINESS
            </div>

            <a href="customers.php"
               class="menu-item <?= in_array($currentPage, ['customers.php', 'customer_form.php']) ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                <span>Customers</span>
            </a>

            <a href="service_plans.php"
               class="menu-item <?= in_array($currentPage, ['service_plans.php', 'service_plan_form.php']) ? 'active' : '' ?>">
                <i class="bi bi-box-seam-fill"></i>
                <span>Service Plans</span>
            </a>

            <a href="subscriptions.php"
               class="menu-item <?= in_array($currentPage, ['subscriptions.php', 'subscription_form.php']) ? 'active' : '' ?>">
                <i class="bi bi-receipt-cutoff"></i>
                <span>Subscriptions</span>
            </a>

            <a href="invoices.php"
               class="menu-item <?= $currentPage === 'invoices.php' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-text-fill"></i>
                <span>Invoices</span>
            </a>

            <a href="revenue.php"
               class="menu-item <?= $currentPage === 'revenue.php' ? 'active' : '' ?>">
                <i class="bi bi-graph-up-arrow"></i>
                <span>Revenue</span>
            </a>

            <a href="payment_reports.php"
               class="menu-item <?= $currentPage === 'payment_reports.php' ? 'active' : '' ?>">
                <i class="bi bi-file-earmark-bar-graph"></i>
                <span>Payment Reports</span>
            </a>
            <a href="payments.php"
               class="menu-item <?= $currentPage === 'payments.php' ? 'active' : '' ?>">
                <i class="bi bi-credit-card-fill"></i>
                <span>Payments</span>
            </a>

            <a href="isp_payment_methods.php"
               class="menu-item <?= $currentPage === 'isp_payment_methods.php' ? 'active' : '' ?>">
                <i class="bi bi-wallet2"></i>
                <span>Payment Methods</span>
            </a>


            <div class="menu-label">TRAFFIC & PELANGGAN AKTIF</div>
            <a href="traffic_split.php" class="menu-item <?= $currentPage === 'traffic_split.php' ? 'active' : '' ?>"><i class="bi bi-diagram-3-fill"></i><span>Pisah Trafik</span></a>
            <a href="loadbalance.php" class="menu-item <?= $currentPage === 'loadbalance.php' ? 'active' : '' ?>"><i class="bi bi-shuffle"></i><span>Load Balance</span></a>
            <a href="pppoe_active.php" class="menu-item <?= $currentPage === 'pppoe_active.php' ? 'active' : '' ?>"><i class="bi bi-person-check-fill"></i><span>PPPoE Aktif</span></a>
            <a href="hotspot_active.php" class="menu-item <?= $currentPage === 'hotspot_active.php' ? 'active' : '' ?>"><i class="bi bi-wifi"></i><span>Hotspot Aktif</span></a>
            <a href="static_ip_active.php" class="menu-item <?= $currentPage === 'static_ip_active.php' ? 'active' : '' ?>"><i class="bi bi-pc-display-horizontal"></i><span>Static IP Aktif</span></a>
        <?php endif; ?>

        <div class="menu-label">NETWORK & MIKROTIK</div>
        <details class="bajama-nav-dropdown" <?= in_array($currentPage, ['network_routeros_control.php','mikrotik.php','mikrotik_routers.php'], true) ? 'open' : '' ?> >
            <summary class="menu-item bajama-nav-summary"><i class="bi bi-router-fill"></i><span>BAJAMA Network & MikroTik</span><i class="bi bi-chevron-down ms-auto"></i></summary>
            <div class="bajama-nav-dropdown-items">
                <a href="network_routeros_control.php" class="menu-item <?= $currentPage === 'network_routeros_control.php' ? 'active' : '' ?>"><i class="bi bi-sliders2-vertical"></i><span>RouterOS Control Center</span></a>
                <a href="mikrotik.php" class="menu-item <?= $currentPage === 'mikrotik.php' ? 'active' : '' ?>"><i class="bi bi-router"></i><span>MikroTik</span></a>
                <a href="mikrotik_routers.php" class="menu-item <?= $currentPage === 'mikrotik_routers.php' ? 'active' : '' ?>"><i class="bi bi-hdd-network"></i><span>Router Management</span></a>
            </div>
        </details>


        <?php if ($isSuperAdmin): ?>
            <!-- =====================================================
                 PLATFORM
            ====================================================== -->

            <div class="menu-label">
                PLATFORM
            </div>

              <a href="users_roles.php"
                  class="menu-item <?= $currentPage === 'users_roles.php' ? 'active' : '' ?>">
                <i class="bi bi-person-badge-fill"></i>
                <span>Users & Roles</span>
            </a>

            <a href="customers.php"
                  class="menu-item <?= in_array($currentPage, ['customers.php', 'customer_form.php']) ? 'active' : '' ?>">
                <i class="bi bi-people-fill"></i>
                <span>Customers</span>
            </a>

            <a href="organizations.php"
                  class="menu-item <?= $currentPage === 'organizations.php' ? 'active' : '' ?>">
                <i class="bi bi-building-fill"></i>
                <span>Organizations</span>
            </a>

              <a href="licenses.php"
                  class="menu-item <?= $currentPage === 'licenses.php' ? 'active' : '' ?>">
                <i class="bi bi-patch-check-fill"></i>
                <span>License</span>
            </a>

                        <a href="superadmin_billing.php"
                                    class="menu-item <?= $currentPage === 'superadmin_billing.php' ? 'active' : '' ?>">
                                <i class="bi bi-receipt-cutoff"></i>
                                <span>Billing Global</span>
                        </a>

                        <a href="superadmin_isp.php"
                                    class="menu-item <?= $currentPage === 'superadmin_isp.php' ? 'active' : '' ?>">
                                <i class="bi bi-router-fill"></i>
                                <span>ISP Control</span>
                        </a>

            <a href="license_plans.php"
                  class="menu-item <?= $currentPage === 'license_plans.php' ? 'active' : '' ?>">
                <i class="bi bi-boxes"></i>
                <span>License Plans</span>
            </a>

            <a href="payment_methods.php"
                  class="menu-item <?= $currentPage === 'payment_methods.php' ? 'active' : '' ?>">
                <i class="bi bi-credit-card-2-front-fill"></i>
                <span>Payment Methods</span>
            </a>

            <a href="registrations.php"
                  class="menu-item <?= $currentPage === 'registrations.php' ? 'active' : '' ?>">
                <i class="bi bi-person-lines-fill"></i>
                <span>Registrations</span>
            </a>

              <a href="audit_logs.php"
                  class="menu-item <?= $currentPage === 'audit_logs.php' ? 'active' : '' ?>">
                <i class="bi bi-journal-text"></i>
                <span>Audit Log</span>
            </a>

            <a href="blog_admin.php"
                  class="menu-item <?= $currentPage === 'blog_admin.php' ? 'active' : '' ?>">
                <i class="bi bi-journal-richtext"></i>
                <span>Blog Admin</span>
            </a>
        <?php endif; ?>

    </div>


    <!-- =========================================================
         SIDEBAR BOTTOM
    ========================================================== -->

    <div class="sidebar-bottom">

        <div class="network-status">
            <span class="status-dot"></span>

            <div>
                <strong>BAJAMA Core</strong>
                <small>System Online</small>
            </div>
        </div>

        <a href="logout.php" class="logout-item">
            <i class="bi bi-box-arrow-right"></i>
            <span>Logout</span>
        </a>

    </div>

</aside>
