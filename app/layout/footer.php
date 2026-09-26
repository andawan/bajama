<style>
/* =========================================================
   BAJAMA PROFESSIONAL SAAS FOOTER
   ========================================================= */

.bajama-footer {
    width: 100%;
    margin-top: 40px;
    background: var(--bs-body-bg, #ffffff);
    border-top: 1px solid rgba(15, 23, 42, 0.08);
    color: var(--bs-body-color, #1e293b);
}

.bajama-footer-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 48px 32px 40px;

    display: grid;
    grid-template-columns:
        minmax(260px, 1.8fr)
        repeat(4, minmax(130px, 1fr));

    gap: 40px;
}


/* BRAND */

.bajama-footer-brand {
    max-width: 360px;
}

.bajama-footer-logo {
    display: flex;
    align-items: center;
    gap: 10px;

    font-size: 20px;
    font-weight: 800;
    letter-spacing: -0.4px;
}

.bajama-footer-logo-icon {
    width: 36px;
    height: 36px;

    display: inline-flex;
    align-items: center;
    justify-content: center;

    border-radius: 10px;

    background: rgba(13, 110, 253, 0.10);
    color: #0d6efd;

    font-size: 17px;
}

.bajama-footer-logo-text {
    letter-spacing: -0.5px;
}

.bajama-footer-tagline {
    margin: 12px 0 6px;

    font-size: 13px;
    font-weight: 600;

    color: #64748b;
}

.bajama-footer-description {
    margin: 0;

    max-width: 340px;

    font-size: 13px;
    line-height: 1.7;

    color: #94a3b8;
}


/* SYSTEM STATUS */

.bajama-footer-status {
    display: inline-flex;
    align-items: center;
    gap: 8px;

    margin-top: 20px;
    padding: 7px 11px;

    border: 1px solid rgba(34, 197, 94, 0.18);
    border-radius: 999px;

    background: rgba(34, 197, 94, 0.06);

    font-size: 11px;
    font-weight: 600;

    color: #64748b;
}

.bajama-status-dot {
    width: 7px;
    height: 7px;

    border-radius: 50%;

    background: #22c55e;

    box-shadow:
        0 0 0 3px rgba(34, 197, 94, 0.10);
}


/* COLUMNS */

.bajama-footer-column h6 {
    margin: 0 0 17px;

    font-size: 12px;
    font-weight: 700;

    text-transform: uppercase;
    letter-spacing: 0.7px;

    color: #334155;
}

.bajama-footer-column {
    display: flex;
    flex-direction: column;
    gap: 10px;
}

.bajama-footer-column a {
    width: fit-content;

    color: #64748b;

    font-size: 13px;
    line-height: 1.5;

    text-decoration: none;

    transition:
        color .2s ease,
        transform .2s ease;
}

.bajama-footer-column a:hover {
    color: #0d6efd;
    transform: translateX(2px);
}


/* BOTTOM */

.bajama-footer-bottom {
    max-width: 1400px;
    margin: 0 auto;

    padding: 20px 32px;

    border-top: 1px solid rgba(15, 23, 42, 0.07);

    display: flex;
    align-items: center;
    justify-content: space-between;

    gap: 20px;
}

.bajama-footer-copyright {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;

    color: #94a3b8;

    font-size: 11px;
}

.bajama-footer-separator {
    color: #cbd5e1;
}

.bajama-footer-links {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 17px;
}

.bajama-footer-links a {
    color: #64748b;

    font-size: 11px;

    text-decoration: none;

    transition: color .2s ease;
}

.bajama-footer-links a:hover {
    color: #0d6efd;
}

.bajama-footer-version {
    padding: 4px 8px;

    border: 1px solid rgba(15, 23, 42, 0.08);
    border-radius: 5px;

    background: rgba(15, 23, 42, 0.025);

    color: #94a3b8;

    font-size: 10px;
    font-weight: 600;
}


/* =========================================================
   DARK MODE
   ========================================================= */

[data-bs-theme="dark"] .bajama-footer,
.dark .bajama-footer {
    background: #0b1220;
    border-top-color: rgba(255,255,255,0.08);
}

[data-bs-theme="dark"] .bajama-footer-column h6,
.dark .bajama-footer-column h6 {
    color: #e2e8f0;
}

[data-bs-theme="dark"] .bajama-footer-column a,
.dark .bajama-footer-column a {
    color: #94a3b8;
}

[data-bs-theme="dark"] .bajama-footer-column a:hover,
.dark .bajama-footer-column a:hover {
    color: #60a5fa;
}

[data-bs-theme="dark"] .bajama-footer-tagline,
.dark .bajama-footer-tagline {
    color: #94a3b8;
}

[data-bs-theme="dark"] .bajama-footer-description,
.dark .bajama-footer-description {
    color: #64748b;
}

[data-bs-theme="dark"] .bajama-footer-bottom,
.dark .bajama-footer-bottom {
    border-top-color: rgba(255,255,255,0.07);
}

[data-bs-theme="dark"] .bajama-footer-version,
.dark .bajama-footer-version {
    border-color: rgba(255,255,255,0.08);
    background: rgba(255,255,255,0.03);
}


/* =========================================================
   TABLET
   ========================================================= */

@media (max-width: 992px) {

    .bajama-footer-container {
        grid-template-columns:
            1.5fr
            repeat(3, 1fr);

        gap: 32px;
    }

    .bajama-footer-brand {
        grid-column: 1 / -1;
        max-width: 600px;
    }

}


/* =========================================================
   MOBILE
   ========================================================= */

@media (max-width: 768px) {

    .bajama-footer-container {
        padding: 36px 20px 30px;

        grid-template-columns:
            repeat(2, 1fr);

        gap: 32px 24px;
    }

    .bajama-footer-brand {
        grid-column: 1 / -1;
    }

    .bajama-footer-bottom {
        padding: 18px 20px;

        flex-direction: column;
        align-items: flex-start;
    }

    .bajama-footer-links {
        gap: 12px;
    }

}


/* =========================================================
   SMALL MOBILE
   ========================================================= */

@media (max-width: 480px) {

    .bajama-footer-container {
        grid-template-columns: 1fr;
    }

    .bajama-footer-column {
        gap: 8px;
    }

    .bajama-footer-bottom {
        align-items: flex-start;
    }

    .bajama-footer-copyright {
        line-height: 1.7;
    }

    .bajama-footer-separator {
        display: none;
    }

}
</style>

<footer class="bajama-footer">

    <div class="bajama-footer-container">

        <!-- BRAND -->
        <div class="bajama-footer-brand">

            <div class="bajama-footer-logo">
                <span class="bajama-footer-logo-icon">
                    <i class="bi bi-broadcast-pin"></i>
                </span>

                <span class="bajama-footer-logo-text">
                    BAJAMA
                </span>
            </div>

            <p class="bajama-footer-tagline">
                Building Networks Together
            </p>

            <p class="bajama-footer-description">
                ISP Business & Network Operations Platform
                designed to simplify billing, customers,
                network management, and ISP operations.
            </p>

            <div class="bajama-footer-status">
                <span class="bajama-status-dot"></span>
                <span>All systems operational</span>
            </div>

        </div>


        <!-- PLATFORM -->
        <div class="bajama-footer-column">

            <h6>Platform</h6>

            <a href="dashboard.php">
                Dashboard
            </a>

            <a href="customers.php">
                Daftar Pelanggan
            </a>

            <a href="service_plans.php">
                Paket Internet
            </a>

            <a href="subscriptions.php">
                Langganan
            </a>

            <a href="invoices.php">
                Tagihan
            </a>

            <a href="payments.php">
                Pembayaran
            </a>

            <a href="isp_payment_methods.php">
                Metode Pembayaran
            </a>

            <a href="payment_reports.php">
                Laporan Pembayaran
            </a>

            <a href="revenue.php">
                Penghasilan
            </a>

        </div>


        <!-- NETWORK -->
        <div class="bajama-footer-column">

            <h6>Network</h6>

            <a href="mikrotik.php">
                MikroTik
            </a>

            <a href="network_olt.php">
                OLT Management
            </a>

            <a href="mikrotik.php?router_id=12&action=ppp.active">
                PPPoE
            </a>

            <a href="mikrotik.php?router_id=12&action=hotspot.active">
                Hotspot
            </a>

            <a href="mikrotik.php?router_id=12&action=queues.simple">
                Queue Management
            </a>

            <a href="network_monitoring.php">
                Network Monitoring
            </a>

        </div>


        <!-- RESOURCES -->
        <div class="bajama-footer-column">

            <h6>Download</h6>

            <a href="#">
                Android
            </a>

            <a href="#">
                Windows
            </a>

            <a href="#">
                Mac
            </a>

            <a href="#">
                Linux
            </a>

        </div>


        <!-- COMPANY -->
        <div class="bajama-footer-column">

            <h6>Layanan Servis</h6>

            <a href="index.php">
                Tentang BAJAMA
            </a>

            <a href="blog.php">
                Blog
            </a>

            <a href="https://wa.me/6287764241047">
                Whatsapp
            </a>

            <a href="https://t.me/teknoyusuf">
                Telegram
            </a>

            <a href="mailto:support@bgmnet.my.id">
                Email
            </a>

        </div>

    </div>


    <!-- FOOTER BOTTOM -->

    <div class="bajama-footer-bottom">

        <div class="bajama-footer-copyright">

            <span>
                © <?= date('Y') ?> BAJAMA.
                All rights reserved.
            </span>

            <span class="bajama-footer-separator">
                •
            </span>

            <span>
                ISP Business & Network Operations Platform
            </span>

        </div>


        <div class="bajama-footer-links">

            <a href="#">
                Privacy
            </a>

            <a href="#">
                Terms
            </a>

            <a href="#">
                Security
            </a>

            <a href="#">
                Cookies
            </a>

            <span class="bajama-footer-version">
                v1.0.0
            </span>

        </div>

    </div>

</footer>