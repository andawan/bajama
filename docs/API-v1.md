# BAJAMA API v1

API ini adalah jalur integrasi untuk klien Android dan Windows. Backend tetap menjadi sumber aturan bisnis, izin, tenant, lisensi, audit, dan data perangkat. Klien tidak boleh terhubung langsung ke MySQL atau router.

## Deployment requirements

1. Terapkan `database/migrations/20260926_api_access.sql` sekali pada database BAJAMA.
2. Publikasikan document root Apache ke direktori `public/` dan aktifkan `mod_rewrite` serta `AllowOverride` untuk `public/api/.htaccess`; atau salin aturan rewrite ke konfigurasi virtual host.
3. Gunakan HTTPS untuk koneksi klien di luar localhost.
4. Opsional untuk aplikasi browser: set `API_CORS_ORIGINS` ke daftar origin spesifik yang dipisahkan koma. Native Android/Windows tidak memerlukan CORS.
5. Uji `GET /api/v1/health` setelah deployment.

## Protocol

- JSON UTF-8.
- Sukses: `{"ok":true,"data":...}`.
- Error: `{"ok":false,"error":{"message":"..."}}`.
- Untuk endpoint terlindungi, kirim `Authorization: Bearer <access_token>`.
- Tenant dan user ditentukan dari token di server, bukan dari `organization_id` kiriman klien.
- Access token berlaku 15 menit. Refresh token berlaku 30 hari dan harus dirotasi memakai endpoint refresh. Database menyimpan hash token, bukan token asli.

## Endpoints saat ini

### `GET /api/v1/health`

Pemeriksaan konektivitas API/database. Tidak memerlukan token.

### `POST /api/v1/auth/login`

Request:

- `identifier`: username atau email.
- `password`: password akun.
- `client_name`: opsional, misalnya `BAJAMA Android` atau `BAJAMA Windows`.

Response `data`: `access_token`, `refresh_token`, `token_type`, `expires_in`, `refresh_expires_in`, dan informasi user/organisasi. Lima kegagalan login per identifier+IP dalam 15 menit akan dibatasi.

### `POST /api/v1/auth/refresh`

Request `refresh_token`. Mengembalikan pasangan access/refresh token baru dan langsung merotasi hash token di server. Refresh token lama tidak dapat dipakai kembali.

### `POST /api/v1/auth/logout`

Butuh access token aktif. Mencabut sesi/token aplikasi.

### `GET /api/v1/me`

Butuh access token. Mengembalikan user, organisasi, roles, dan status/fitur lisensi untuk membentuk menu aplikasi.

### `GET /api/v1/billing/summary`

Butuh permission `billing.view` dan fitur billing. Ringkasan total invoice, unpaid/overdue, nilai outstanding, dan nilai paid untuk tenant aktif.

### `GET /api/v1/billing/invoices`

Butuh permission `billing.view` dan fitur billing. Mengembalikan sampai 100 invoice terbaru tenant aktif, termasuk pelanggan, status, tanggal jatuh tempo, dan total. Filter opsional: `q` dan `status`.

### `GET /api/v1/network/routers`

Butuh permission `mikrotik.view` dan fitur MikroTik. Mengembalikan daftar router tenant (metadata koneksi saja; password tidak pernah dikirim).

### `GET /api/v1/network/olts`

Butuh permission `network.view` dan fitur OLT. Mengembalikan inventaris OLT tenant. Endpoint ini belum menjalankan operasi vendor/OLT.

## Batas cakupan awal

Endpoint billing saat ini hanya menyediakan ringkasan dan daftar invoice baca-saja. CRUD pelanggan/subscription/invoice/pembayaran, pembayaran gateway, aksi RouterOS, monitoring aktif, FTTH/ONU, dan adapter per vendor OLT belum diekspos sebagai kontrak API v1. Tambahkan endpoint per use-case dengan validasi, tenant scope, permission, idempotency untuk tindakan finansial, dan audit sebelum mengaktifkan operasi tulis dari aplikasi.
