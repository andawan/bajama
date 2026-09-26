# BAJAMA Windows

Klien desktop WPF/.NET 8 untuk Windows x64. Backend BAJAMA tetap menjadi sumber data, aturan bisnis, izin, dan integrasi perangkat; aplikasi tidak pernah mengakses MySQL secara langsung.

## Apa yang termasuk

- Login BAJAMA dan tenant context.
- Dashboard ringkasan organisasi/lisensi.
- Ringkasan dan daftar invoice baca-saja.
- Daftar router MikroTik dan inventaris OLT baca-saja.
- Access token 15 menit dan refresh token rotasi 30 hari; token/password hanya di memori. URL server disimpan di `%LOCALAPPDATA%\BAJAMA\settings.json`.
- Installer Windows x64 self-contained, sehingga pelanggan tidak perlu memasang .NET Runtime.

## Siapkan server (admin BAJAMA)

Sebelum pengguna login, backend harus sudah siap:

1. Terapkan `database/migrations/20260926_api_access.sql` satu kali.
2. Publikasikan Apache document root ke `public/`, aktifkan `mod_rewrite` dan aturan `public/api/.htaccess`, atau pasang aturan rewrite ekuivalen pada virtual host.
3. Pasang sertifikat HTTPS yang valid. Klien hanya menerima HTTPS selain localhost.
4. Pastikan login API berjalan dengan membuka `https://domain-anda/api/v1/health`; respons yang diharapkan berisi `"status":"healthy"`.

Lihat kontrak, permission, dan rincian endpoint di `docs/API-v1.md`.

## Cara mendapatkan installer

Repository ini membuat installer melalui GitHub Actions pada Windows runner:

1. Push perubahan ke remote repository GitHub.
2. Di tab **Actions**, pilih workflow **Build BAJAMA Windows** lalu tekan **Run workflow**. Atau buat/push tag seperti `windows-v1.0.0`.
3. Buka run yang sukses dan unduh artifact `bajama-windows-installer-win-x64`.
4. Ekstrak artifact dan jalankan `BAJAMA-Setup-1.0.0-win-x64.exe`.
5. Installer membuat shortcut Start Menu (shortcut Desktop opsional). Jalankan BAJAMA, masukkan URL HTTPS server, lalu login dengan akun masing-masing.

Workflow build: `.github/workflows/build-windows.yml`; skrip installer: `clients/windows/installer/Bajama.iss`.

## Build manual di Windows

Pasang .NET 8 SDK dan Inno Setup 6, lalu dari root repository jalankan:

```powershell
dotnet publish clients/windows/Bajama.Windows/Bajama.Windows.csproj --configuration Release --runtime win-x64 --self-contained true -p:PublishReadyToRun=true -p:PublishDir=clients/windows/publish/win-x64/
New-Item -ItemType Directory -Force artifacts | Out-Null
& "${env:ProgramFiles(x86)}\Inno Setup 6\ISCC.exe" /DAppVersion=1.0.0 "/O$PWD\artifacts" clients/windows/installer/Bajama.iss
```

Installer akan dibuat di `artifacts/`.

## Catatan rilis

Build ini ditargetkan Windows x64. Installer belum ditandatangani sertifikat code-signing, jadi Windows SmartScreen mungkin menampilkan peringatan pada distribusi awal. Untuk pelanggan luas, tandatangani installer dan executable dengan sertifikat code-signing publisher BAJAMA. Modul API saat ini masih baca-saja untuk data invoice/router/OLT; pembayaran, perubahan router, dan kontrol vendor OLT belum tersedia pada klien ini.
