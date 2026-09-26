(() => {
    const locale = window.BAJAMA_LOCALE || document.documentElement.lang || 'id';

    const dictionaries = {
        id: {
            'Dashboard': 'Dasbor', 'Customers': 'Pelanggan', 'Service Plans': 'Paket Layanan',
            'Subscriptions': 'Langganan', 'Invoices': 'Invoice', 'Revenue': 'Pendapatan',
            'Payment Reports': 'Laporan Pembayaran', 'Payments': 'Pembayaran', 'Payment Methods': 'Metode Pembayaran',
            'Traffic Catalog': 'Katalog Trafik', 'Traffic Policy': 'Kebijakan Trafik', 'Submit Traffic': 'Kirim Trafik',
            'Review Traffic': 'Review Trafik', 'NETWORK & MIKROTIK': 'JARINGAN & MIKROTIK',
            'BAJAMA Network & MikroTik': 'Jaringan & MikroTik BAJAMA', 'ISP / WAN': 'ISP / WAN',
            'LAN / VLAN': 'LAN / VLAN', 'Routing': 'Routing', 'Load Balance': 'Load Balance', 'Firewall': 'Firewall',
            'Monitoring': 'Monitoring', 'Static IP Customers': 'Pelanggan IP Statis', 'Hotspot & Voucher': 'Hotspot & Voucher',
            'FTTH Services': 'Layanan FTTH', 'OLT Management': 'Manajemen OLT', 'RouterOS Control Center': 'Pusat Kontrol RouterOS',
            'MikroTik': 'MikroTik', 'Router Management': 'Manajemen Router', 'Users & Roles': 'User & Role',
            'Organizations': 'Organisasi', 'License': 'Lisensi', 'License Plans': 'Paket Lisensi',
            'Registrations': 'Pendaftaran', 'Audit Log': 'Log Audit', 'Blog Admin': 'Admin Blog',
            'PLATFORM': 'PLATFORM', 'BUSINESS': 'BISNIS', 'OVERVIEW': 'RINGKASAN', 'TRAFFIC INTELLIGENCE': 'INTELIJEN TRAFIK',
            'System Online': 'Sistem Online', 'Logout': 'Keluar', 'Settings': 'Pengaturan', 'Profile': 'Profil',
            'Online': 'Online', 'Actions': 'Aksi', 'Save': 'Simpan', 'Update': 'Perbarui', 'Delete': 'Hapus',
            'Edit': 'Edit', 'Add': 'Tambah', 'Search': 'Cari', 'Back to dashboard': 'Kembali ke dasbor',
            'No data available.': 'Belum ada data.'
        },
        en: {
            'Dashboard': 'Dashboard', 'Customers': 'Customers', 'Service Plans': 'Service Plans', 'Subscriptions': 'Subscriptions',
            'Invoices': 'Invoices', 'Revenue': 'Revenue', 'Payment Reports': 'Payment Reports', 'Payments': 'Payments',
            'Payment Methods': 'Payment Methods', 'Traffic Catalog': 'Traffic Catalog', 'Traffic Policy': 'Traffic Policy',
            'Submit Traffic': 'Submit Traffic', 'Review Traffic': 'Review Traffic', 'NETWORK & MIKROTIK': 'NETWORK & MIKROTIK',
            'BAJAMA Network & MikroTik': 'BAJAMA Network & MikroTik', 'PLATFORM': 'PLATFORM', 'BUSINESS': 'BUSINESS',
            'OVERVIEW': 'OVERVIEW', 'TRAFFIC INTELLIGENCE': 'TRAFFIC INTELLIGENCE', 'Users & Roles': 'Users & Roles',
            'Organizations': 'Organizations', 'License': 'License', 'License Plans': 'License Plans',
            'Registrations': 'Registrations', 'Audit Log': 'Audit Log', 'Blog Admin': 'Blog Admin',
            'System Online': 'System Online', 'Profile': 'Profile', 'Settings': 'Settings', 'Logout': 'Log out',
            'Online': 'Online', 'Beranda': 'Home', 'Kembali ke Dashboard': 'Back to dashboard',
            'Pelanggan': 'Customers', 'Organisasi': 'Organization', 'User & Role': 'Users & Roles',
            'Lisensi': 'License', 'Billing': 'Billing', 'Pembayaran': 'Payments', 'Invoice': 'Invoice',
            'Metode Pembayaran': 'Payment methods', 'Status': 'Status', 'Aksi': 'Actions', 'Simpan': 'Save',
            'Update': 'Update', 'Edit': 'Edit', 'Hapus': 'Delete', 'Tambah': 'Add', 'Cari': 'Search',
            'Belum ada data.': 'No data available.', 'Belum ada data pelanggan.': 'No customer data available.',
            'Belum ada data lisensi.': 'No license data available.', 'Belum ada invoice.': 'No invoices available.',
            'Belum ada data pembayaran.': 'No payment data available.', 'Kelola profil': 'Manage profile',
            'Ringkasan': 'Summary', 'Pelanggan Lisensi': 'License customers', 'Nomor lisensi': 'License number',
            'Status lisensi': 'License status', 'Kadaluarsa': 'Expires', 'Peran': 'Role', 'Telepon': 'Phone',
            'Email': 'Email', 'Nama': 'Name', 'Nama lengkap': 'Full name', 'Password': 'Password',
            'Konfirmasi password': 'Confirm password', 'Username': 'Username', 'Simpan Profile': 'Save profile',
            'Lupa Sandi': 'Forgot password', 'Kembali ke login': 'Back to login', 'Kirim Instruksi': 'Send instructions'
        },
        ms: {
            'Dashboard': 'Papan pemuka', 'Customers': 'Pelanggan', 'Service Plans': 'Pelan perkhidmatan', 'Subscriptions': 'Langganan',
            'Invoices': 'Invois', 'Revenue': 'Hasil', 'Payment Reports': 'Laporan pembayaran', 'Payments': 'Pembayaran',
            'Payment Methods': 'Kaedah pembayaran', 'Traffic Catalog': 'Katalog trafik', 'Traffic Policy': 'Dasar trafik',
            'Submit Traffic': 'Hantar trafik', 'Review Traffic': 'Semak trafik', 'NETWORK & MIKROTIK': 'RANGKAIAN & MIKROTIK',
            'BAJAMA Network & MikroTik': 'Rangkaian & MikroTik BAJAMA', 'PLATFORM': 'PLATFORM', 'BUSINESS': 'PERNIAGAAN',
            'OVERVIEW': 'RINGKASAN', 'TRAFFIC INTELLIGENCE': 'KECERDASAN TRAFIK', 'Users & Roles': 'Pengguna & Peranan',
            'Organizations': 'Organisasi', 'License': 'Lesen', 'License Plans': 'Pelan lesen',
            'Registrations': 'Pendaftaran', 'Audit Log': 'Log audit', 'Blog Admin': 'Admin blog',
            'System Online': 'Sistem dalam talian', 'Profile': 'Profil', 'Settings': 'Tetapan', 'Logout': 'Log keluar',
            'Online': 'Dalam talian', 'Beranda': 'Laman Utama', 'Kembali ke Dashboard': 'Kembali ke papan pemuka',
            'Pelanggan': 'Pelanggan', 'Organisasi': 'Organisasi', 'User & Role': 'Pengguna & Peranan',
            'Lisensi': 'Lesen', 'Billing': 'Bil', 'Pembayaran': 'Pembayaran', 'Invoice': 'Invois',
            'Metode Pembayaran': 'Kaedah pembayaran', 'Status': 'Status', 'Aksi': 'Tindakan', 'Simpan': 'Simpan',
            'Update': 'Kemas kini', 'Edit': 'Edit', 'Hapus': 'Padam', 'Tambah': 'Tambah', 'Cari': 'Cari',
            'Belum ada data.': 'Tiada data.', 'Belum ada data pelanggan.': 'Tiada data pelanggan.',
            'Belum ada data lisensi.': 'Tiada data lesen.', 'Belum ada invoice.': 'Tiada invois.',
            'Belum ada data pembayaran.': 'Tiada data pembayaran.', 'Kelola profil': 'Urus profil',
            'Ringkasan': 'Ringkasan', 'Nomor lisensi': 'Nombor lesen', 'Status lisensi': 'Status lesen',
            'Kadaluarsa': 'Tamat tempoh', 'Peran': 'Peranan', 'Telepon': 'Telefon', 'Email': 'E-mel',
            'Nama': 'Nama', 'Nama lengkap': 'Nama penuh', 'Password': 'Kata laluan',
            'Konfirmasi password': 'Sahkan kata laluan', 'Simpan Profile': 'Simpan profil',
            'Lupa Sandi': 'Lupa kata laluan', 'Kembali ke login': 'Kembali ke log masuk', 'Kirim Instruksi': 'Hantar arahan'
        }
    };
    // Common application vocabulary. The PHP I18n class controls the active
    // locale; this catalog covers legacy pages whose literal labels are not
    // yet wrapped in PHP t() calls.
    const commonTranslations = {
        id: {
            'Dashboard': 'Dasbor', 'Customers': 'Pelanggan', 'Customer': 'Pelanggan', 'Service Plans': 'Paket Layanan',
            'Subscriptions': 'Langganan', 'Invoices': 'Invoice', 'Revenue': 'Pendapatan', 'Payments': 'Pembayaran',
            'Payment Methods': 'Metode Pembayaran', 'Users & Roles': 'Pengguna & Peran', 'Organizations': 'Organisasi',
            'Add': 'Tambah', 'Create': 'Buat', 'Edit': 'Edit', 'Save': 'Simpan', 'Update': 'Perbarui',
            'Delete': 'Hapus', 'Cancel': 'Batal', 'Close': 'Tutup', 'Submit': 'Kirim', 'Search': 'Cari',
            'View': 'Lihat', 'Actions': 'Aksi', 'Status': 'Status', 'Name': 'Nama', 'Email': 'Email',
            'Username': 'Nama Pengguna', 'Password': 'Kata Sandi', 'Confirm': 'Konfirmasi', 'Yes': 'Ya', 'No': 'Tidak',
            'Back': 'Kembali', 'Next': 'Berikutnya', 'Previous': 'Sebelumnya', 'Profile': 'Profil',
            'Settings': 'Pengaturan', 'Logout': 'Keluar', 'Online': 'Online', 'Role': 'Peran', 'Organization': 'Organisasi',
            'Date': 'Tanggal', 'Amount': 'Jumlah', 'Description': 'Deskripsi', 'Active': 'Aktif', 'Inactive': 'Tidak Aktif',
            'No data available.': 'Belum ada data.', 'Select': 'Pilih', 'Choose': 'Pilih',
            'Overview': 'Ringkasan', 'Business': 'Bisnis', 'Network': 'Jaringan', 'Platform': 'Platform',
            'Network Operations': 'Operasional Jaringan', 'Router Management': 'Manajemen Router', 'Interface List': 'Daftar Interface',
            'Interfaces': 'Interface', 'Addresses': 'Alamat IP', 'Routes': 'Rute', 'IP Pools': 'IP Pool',
            'PPP Profiles': 'Profil PPP', 'PPPoE Secrets': 'Secret PPPoE', 'Servers': 'Server', 'Users': 'Pengguna',
            'Simple Queues': 'Simple Queue', 'Filter Rules': 'Aturan Filter', 'NAT': 'NAT', 'Mangle': 'Mangle',
            'DHCP Servers': 'Server DHCP', 'DHCP Leases': 'Lease DHCP', 'DHCP Networks': 'Jaringan DHCP',
            'Identity': 'Identitas', 'Resource': 'Resource', 'Health': 'Kesehatan', 'Tools': 'Alat', 'Auto Sync': 'Sinkronisasi Otomatis',
            'Sync Status': 'Status Sinkronisasi', 'Menu & Permissions': 'Menu & Izin', 'User Permissions': 'Izin Pengguna',
            'Refresh': 'Muat Ulang', 'Export': 'Ekspor', 'Import': 'Impor', 'Reset': 'Atur Ulang', 'Apply': 'Terapkan',
            'Clear': 'Bersihkan', 'Enable': 'Aktifkan', 'Disable': 'Nonaktifkan', 'Enabled': 'Aktif', 'Disabled': 'Nonaktif',
            'Online': 'Online', 'Offline': 'Offline', 'Pending': 'Menunggu', 'Approved': 'Disetujui', 'Rejected': 'Ditolak',
            'Paid': 'Lunas', 'Unpaid': 'Belum Dibayar', 'Partial': 'Sebagian', 'Overdue': 'Jatuh Tempo', 'Cancelled': 'Dibatalkan',
            'Failed': 'Gagal', 'Success': 'Berhasil', 'Draft': 'Draf', 'Total': 'Total', 'Details': 'Detail',
            'Description': 'Deskripsi', 'Notes': 'Catatan', 'Phone': 'Telepon', 'Address': 'Alamat', 'Code': 'Kode',
            'Start Date': 'Tanggal Mulai', 'End Date': 'Tanggal Selesai', 'Created At': 'Dibuat Pada', 'Updated At': 'Diperbarui Pada',
            'Add Customer': 'Tambah Pelanggan', 'Customer List': 'Daftar Pelanggan', 'Add ISP': 'Tambah ISP', 'Add Router': 'Tambah Router',
            'Add Plan': 'Tambah Paket', 'Add Payment': 'Tambah Pembayaran', 'Generate Voucher': 'Buat Voucher',
            'Read-only discovery': 'Penemuan hanya-baca', 'View Details': 'Lihat Detail', 'Back to dashboard': 'Kembali ke Dasbor',
            'Read more': 'Baca selengkapnya', 'Register Now': 'Daftar Sekarang', 'View Plans': 'Lihat Paket',
            'Secure & Reliable': 'Aman & Terpercaya', 'Easy & Fast': 'Mudah & Cepat', 'Support': 'Dukungan',
            'License Payment': 'Pembayaran Lisensi', 'Payment Total': 'Total Pembayaran'
        },
        en: {
            'Dasbor': 'Dashboard', 'Pelanggan': 'Customers', 'Paket Layanan': 'Service Plans', 'Langganan': 'Subscriptions',
            'Pendapatan': 'Revenue', 'Pembayaran': 'Payments', 'Metode Pembayaran': 'Payment Methods', 'Pengguna & Peran': 'Users & Roles',
            'Pengguna dan Peran': 'Users & Roles', 'Organisasi': 'Organizations', 'Tambah': 'Add', 'Buat': 'Create',
            'Simpan': 'Save', 'Perbarui': 'Update', 'Hapus': 'Delete', 'Batal': 'Cancel', 'Tutup': 'Close',
            'Kirim': 'Submit', 'Cari': 'Search', 'Lihat': 'View', 'Aksi': 'Actions', 'Nama': 'Name',
            'Nama Pengguna': 'Username', 'Kata Sandi': 'Password', 'Konfirmasi': 'Confirm', 'Ya': 'Yes', 'Tidak': 'No',
            'Kembali': 'Back', 'Berikutnya': 'Next', 'Sebelumnya': 'Previous', 'Pengaturan': 'Settings', 'Keluar': 'Logout',
            'Peran': 'Role', 'Tanggal': 'Date', 'Jumlah': 'Amount', 'Deskripsi': 'Description', 'Aktif': 'Active',
            'Tidak Aktif': 'Inactive', 'Belum ada data.': 'No data available.', 'Pilih': 'Select',
            'Ringkasan': 'Overview', 'Bisnis': 'Business', 'Jaringan': 'Network', 'Operasional Jaringan': 'Network Operations',
            'Manajemen Router': 'Router Management', 'Daftar Interface': 'Interface List', 'Alamat IP': 'Addresses', 'Rute': 'Routes',
            'Server DHCP': 'DHCP Servers', 'Lease DHCP': 'DHCP Leases', 'Identitas': 'Identity', 'Kesehatan': 'Health',
            'Alat': 'Tools', 'Sinkronisasi Otomatis': 'Auto Sync', 'Status Sinkronisasi': 'Sync Status', 'Izin Pengguna': 'User Permissions',
            'Muat Ulang': 'Refresh', 'Ekspor': 'Export', 'Impor': 'Import', 'Atur Ulang': 'Reset', 'Terapkan': 'Apply',
            'Bersihkan': 'Clear', 'Aktifkan': 'Enable', 'Nonaktifkan': 'Disable', 'Aktif': 'Enabled', 'Nonaktif': 'Disabled',
            'Menunggu': 'Pending', 'Disetujui': 'Approved', 'Ditolak': 'Rejected', 'Lunas': 'Paid', 'Belum Dibayar': 'Unpaid',
            'Sebagian': 'Partial', 'Jatuh Tempo': 'Overdue', 'Dibatalkan': 'Cancelled', 'Gagal': 'Failed', 'Berhasil': 'Success',
            'Draf': 'Draft', 'Detail': 'Details', 'Catatan': 'Notes', 'Telepon': 'Phone', 'Alamat': 'Address', 'Kode': 'Code',
            'Tanggal Mulai': 'Start Date', 'Tanggal Selesai': 'End Date', 'Dibuat Pada': 'Created At', 'Diperbarui Pada': 'Updated At',
            'Tambah Pelanggan': 'Add Customer', 'Daftar Pelanggan': 'Customer List', 'Tambah ISP': 'Add ISP', 'Tambah Router': 'Add Router',
            'Tambah Paket': 'Add Plan', 'Tambah Pembayaran': 'Add Payment', 'Buat Voucher': 'Generate Voucher',
            'Penemuan hanya-baca': 'Read-only discovery', 'Lihat Detail': 'View Details', 'Baca selengkapnya': 'Read more',
            'Daftar Sekarang': 'Register Now', 'Lihat Paket': 'View Plans', 'Aman & Terpercaya': 'Secure & Reliable',
            'Mudah & Cepat': 'Easy & Fast', 'Dukungan': 'Support', 'Pembayaran Lisensi': 'License Payment', 'Total Pembayaran': 'Payment Total'
        },
        ms: {
            'Dashboard': 'Papan Pemuka', 'Dasbor': 'Papan Pemuka', 'Customers': 'Pelanggan', 'Service Plans': 'Pelan Perkhidmatan',
            'Subscriptions': 'Langganan', 'Invoices': 'Invois', 'Revenue': 'Hasil', 'Payments': 'Pembayaran',
            'Payment Methods': 'Kaedah Pembayaran', 'Users & Roles': 'Pengguna & Peranan', 'Organizations': 'Organisasi',
            'Add': 'Tambah', 'Create': 'Cipta', 'Edit': 'Edit', 'Save': 'Simpan', 'Update': 'Kemas Kini', 'Delete': 'Padam',
            'Cancel': 'Batal', 'Close': 'Tutup', 'Submit': 'Hantar', 'Search': 'Cari', 'View': 'Lihat', 'Actions': 'Tindakan',
            'Name': 'Nama', 'Email': 'E-mel', 'Username': 'Nama Pengguna', 'Password': 'Kata Laluan', 'Confirm': 'Sahkan',
            'Back': 'Kembali', 'Next': 'Seterusnya', 'Previous': 'Sebelumnya', 'Profile': 'Profil', 'Settings': 'Tetapan',
            'Logout': 'Log Keluar', 'Role': 'Peranan', 'Date': 'Tarikh', 'Amount': 'Jumlah', 'Description': 'Penerangan',
            'Active': 'Aktif', 'Inactive': 'Tidak Aktif', 'No data available.': 'Tiada data.', 'Select': 'Pilih',
            'Overview': 'Ringkasan', 'Business': 'Perniagaan', 'Network': 'Rangkaian', 'Network Operations': 'Operasi Rangkaian',
            'Router Management': 'Pengurusan Penghala', 'Interface List': 'Senarai Antara Muka', 'Addresses': 'Alamat', 'Routes': 'Laluan',
            'Refresh': 'Muat Semula', 'Export': 'Eksport', 'Import': 'Import', 'Reset': 'Tetapkan Semula', 'Apply': 'Gunakan',
            'Clear': 'Kosongkan', 'Enable': 'Dayakan', 'Disable': 'Lumpuhkan', 'Enabled': 'Aktif', 'Disabled': 'Tidak Aktif',
            'Pending': 'Menunggu', 'Approved': 'Diluluskan', 'Rejected': 'Ditolak', 'Paid': 'Dibayar', 'Unpaid': 'Belum Dibayar',
            'Partial': 'Sebahagian', 'Overdue': 'Tertunggak', 'Cancelled': 'Dibatalkan', 'Failed': 'Gagal', 'Success': 'Berjaya',
            'Draft': 'Draf', 'Total': 'Jumlah', 'Details': 'Butiran', 'Description': 'Penerangan', 'Notes': 'Nota', 'Phone': 'Telefon',
            'Address': 'Alamat', 'Code': 'Kod', 'Start Date': 'Tarikh Mula', 'End Date': 'Tarikh Tamat', 'Created At': 'Dibuat Pada',
            'Updated At': 'Dikemas Kini Pada', 'Add Customer': 'Tambah Pelanggan', 'Customer List': 'Senarai Pelanggan',
            'Add ISP': 'Tambah ISP', 'Add Router': 'Tambah Penghala', 'Add Plan': 'Tambah Pelan', 'Add Payment': 'Tambah Pembayaran',
            'Generate Voucher': 'Jana Baucar', 'View Details': 'Lihat Butiran', 'Back to dashboard': 'Kembali ke papan pemuka',
            'Read more': 'Baca selanjutnya', 'Register Now': 'Daftar Sekarang', 'View Plans': 'Lihat Pelan',
            'Secure & Reliable': 'Selamat & Dipercayai', 'Easy & Fast': 'Mudah & Pantas', 'Support': 'Sokongan',
            'License Payment': 'Pembayaran Lesen', 'Payment Total': 'Jumlah Pembayaran'
        },
        zh: {
            'Dashboard': '仪表板', 'Dasbor': '仪表板', 'Customers': '客户', 'Pelanggan': '客户', 'Service Plans': '服务套餐',
            'Subscriptions': '订阅', 'Invoices': '发票', 'Revenue': '收入', 'Payments': '付款', 'Payment Methods': '付款方式',
            'Users & Roles': '用户和角色', 'Organizations': '组织', 'Add': '添加', 'Tambah': '添加', 'Create': '创建',
            'Edit': '编辑', 'Save': '保存', 'Simpan': '保存', 'Update': '更新', 'Delete': '删除', 'Hapus': '删除',
            'Cancel': '取消', 'Close': '关闭', 'Submit': '提交', 'Search': '搜索', 'View': '查看', 'Actions': '操作',
            'Name': '名称', 'Email': '电子邮件', 'Username': '用户名', 'Password': '密码', 'Confirm': '确认', 'Back': '返回',
            'Profile': '个人资料', 'Settings': '设置', 'Logout': '退出', 'Role': '角色', 'Date': '日期', 'Amount': '金额',
            'Description': '描述', 'Active': '启用', 'Inactive': '停用', 'No data available.': '暂无数据', 'Select': '选择'
        },
        ja: {
            'Dashboard': 'ダッシュボード', 'Dasbor': 'ダッシュボード', 'Customers': '顧客', 'Pelanggan': '顧客', 'Service Plans': 'サービスプラン',
            'Subscriptions': 'サブスクリプション', 'Invoices': '請求書', 'Revenue': '収益', 'Payments': '支払い', 'Payment Methods': '支払方法',
            'Users & Roles': 'ユーザーと役割', 'Organizations': '組織', 'Add': '追加', 'Tambah': '追加', 'Create': '作成',
            'Edit': '編集', 'Save': '保存', 'Simpan': '保存', 'Update': '更新', 'Delete': '削除', 'Hapus': '削除', 'Cancel': 'キャンセル',
            'Close': '閉じる', 'Submit': '送信', 'Search': '検索', 'View': '表示', 'Actions': '操作', 'Name': '名前', 'Email': 'メール',
            'Username': 'ユーザー名', 'Password': 'パスワード', 'Confirm': '確認', 'Back': '戻る', 'Profile': 'プロフィール',
            'Settings': '設定', 'Logout': 'ログアウト', 'Role': '役割', 'Date': '日付', 'Amount': '金額', 'Description': '説明',
            'Active': '有効', 'Inactive': '無効', 'No data available.': 'データがありません', 'Select': '選択'
        },
        ko: {
            'Dashboard': '대시보드', 'Dasbor': '대시보드', 'Customers': '고객', 'Pelanggan': '고객', 'Service Plans': '서비스 요금제',
            'Subscriptions': '구독', 'Invoices': '청구서', 'Revenue': '수익', 'Payments': '결제', 'Payment Methods': '결제 방법',
            'Users & Roles': '사용자 및 역할', 'Organizations': '조직', 'Add': '추가', 'Tambah': '추가', 'Create': '생성', 'Edit': '편집',
            'Save': '저장', 'Simpan': '저장', 'Update': '업데이트', 'Delete': '삭제', 'Hapus': '삭제', 'Cancel': '취소', 'Close': '닫기',
            'Submit': '제출', 'Search': '검색', 'View': '보기', 'Actions': '작업', 'Name': '이름', 'Email': '이메일', 'Username': '사용자 이름',
            'Password': '비밀번호', 'Confirm': '확인', 'Back': '뒤로', 'Profile': '프로필', 'Settings': '설정', 'Logout': '로그아웃',
            'Role': '역할', 'Date': '날짜', 'Amount': '금액', 'Description': '설명', 'Active': '활성', 'Inactive': '비활성',
            'No data available.': '데이터가 없습니다', 'Select': '선택'
        },
        es: {'Dashboard':'Panel','Dasbor':'Panel','Customers':'Clientes','Pelanggan':'Clientes','Service Plans':'Planes de servicio','Subscriptions':'Suscripciones','Invoices':'Facturas','Revenue':'Ingresos','Payments':'Pagos','Payment Methods':'Métodos de pago','Users & Roles':'Usuarios y roles','Organizations':'Organizaciones','Add':'Añadir','Tambah':'Añadir','Create':'Crear','Edit':'Editar','Save':'Guardar','Simpan':'Guardar','Update':'Actualizar','Delete':'Eliminar','Hapus':'Eliminar','Cancel':'Cancelar','Close':'Cerrar','Submit':'Enviar','Search':'Buscar','View':'Ver','Actions':'Acciones','Name':'Nombre','Email':'Correo electrónico','Username':'Usuario','Password':'Contraseña','Confirm':'Confirmar','Back':'Volver','Profile':'Perfil','Settings':'Configuración','Logout':'Cerrar sesión','Role':'Rol','Date':'Fecha','Amount':'Importe','Description':'Descripción','Active':'Activo','Inactive':'Inactivo','No data available.':'No hay datos.','Select':'Seleccionar'},
        fr: {'Dashboard':'Tableau de bord','Dasbor':'Tableau de bord','Customers':'Clients','Pelanggan':'Clients','Service Plans':'Forfaits','Subscriptions':'Abonnements','Invoices':'Factures','Revenue':'Revenus','Payments':'Paiements','Payment Methods':'Modes de paiement','Users & Roles':'Utilisateurs et rôles','Organizations':'Organisations','Add':'Ajouter','Tambah':'Ajouter','Create':'Créer','Edit':'Modifier','Save':'Enregistrer','Simpan':'Enregistrer','Update':'Mettre à jour','Delete':'Supprimer','Hapus':'Supprimer','Cancel':'Annuler','Close':'Fermer','Submit':'Envoyer','Search':'Rechercher','View':'Voir','Actions':'Actions','Name':'Nom','Email':'E-mail','Username':'Nom d’utilisateur','Password':'Mot de passe','Confirm':'Confirmer','Back':'Retour','Profile':'Profil','Settings':'Paramètres','Logout':'Déconnexion','Role':'Rôle','Date':'Date','Amount':'Montant','Description':'Description','Active':'Actif','Inactive':'Inactif','No data available.':'Aucune donnée.','Select':'Sélectionner'},
        de: {'Dashboard':'Dashboard','Dasbor':'Dashboard','Customers':'Kunden','Pelanggan':'Kunden','Service Plans':'Servicepläne','Subscriptions':'Abonnements','Invoices':'Rechnungen','Revenue':'Umsatz','Payments':'Zahlungen','Payment Methods':'Zahlungsmethoden','Users & Roles':'Benutzer und Rollen','Organizations':'Organisationen','Add':'Hinzufügen','Tambah':'Hinzufügen','Create':'Erstellen','Edit':'Bearbeiten','Save':'Speichern','Simpan':'Speichern','Update':'Aktualisieren','Delete':'Löschen','Hapus':'Löschen','Cancel':'Abbrechen','Close':'Schließen','Submit':'Senden','Search':'Suchen','View':'Ansehen','Actions':'Aktionen','Name':'Name','Email':'E-Mail','Username':'Benutzername','Password':'Passwort','Confirm':'Bestätigen','Back':'Zurück','Profile':'Profil','Settings':'Einstellungen','Logout':'Abmelden','Role':'Rolle','Date':'Datum','Amount':'Betrag','Description':'Beschreibung','Active':'Aktiv','Inactive':'Inaktiv','No data available.':'Keine Daten verfügbar.','Select':'Auswählen'},
        pt: {'Dashboard':'Painel','Dasbor':'Painel','Customers':'Clientes','Pelanggan':'Clientes','Service Plans':'Planos de serviço','Subscriptions':'Assinaturas','Invoices':'Faturas','Revenue':'Receita','Payments':'Pagamentos','Payment Methods':'Métodos de pagamento','Users & Roles':'Utilizadores e funções','Organizations':'Organizações','Add':'Adicionar','Tambah':'Adicionar','Create':'Criar','Edit':'Editar','Save':'Guardar','Simpan':'Guardar','Update':'Atualizar','Delete':'Eliminar','Hapus':'Eliminar','Cancel':'Cancelar','Close':'Fechar','Submit':'Enviar','Search':'Pesquisar','View':'Ver','Actions':'Ações','Name':'Nome','Email':'E-mail','Username':'Nome de utilizador','Password':'Palavra-passe','Confirm':'Confirmar','Back':'Voltar','Profile':'Perfil','Settings':'Definições','Logout':'Terminar sessão','Role':'Função','Date':'Data','Amount':'Valor','Description':'Descrição','Active':'Ativo','Inactive':'Inativo','No data available.':'Sem dados.','Select':'Selecionar'},
        hi: {'Dashboard':'डैशबोर्ड','Dasbor':'डैशबोर्ड','Customers':'ग्राहक','Pelanggan':'ग्राहक','Service Plans':'सेवा योजनाएँ','Subscriptions':'सदस्यताएँ','Invoices':'चालान','Revenue':'राजस्व','Payments':'भुगतान','Payment Methods':'भुगतान विधियाँ','Users & Roles':'उपयोगकर्ता और भूमिकाएँ','Organizations':'संगठन','Add':'जोड़ें','Tambah':'जोड़ें','Create':'बनाएँ','Edit':'संपादित करें','Save':'सहेजें','Simpan':'सहेजें','Update':'अपडेट करें','Delete':'हटाएँ','Hapus':'हटाएँ','Cancel':'रद्द करें','Close':'बंद करें','Submit':'भेजें','Search':'खोजें','View':'देखें','Actions':'क्रियाएँ','Name':'नाम','Email':'ईमेल','Username':'उपयोगकर्ता नाम','Password':'पासवर्ड','Confirm':'पुष्टि करें','Back':'वापस','Profile':'प्रोफ़ाइल','Settings':'सेटिंग्स','Logout':'लॉग आउट','Role':'भूमिका','Date':'तारीख','Amount':'राशि','Description':'विवरण','Active':'सक्रिय','Inactive':'निष्क्रिय','No data available.':'कोई डेटा उपलब्ध नहीं है','Select':'चुनें'},
        ar: {'Dashboard':'لوحة التحكم','Dasbor':'لوحة التحكم','Customers':'العملاء','Pelanggan':'العملاء','Service Plans':'خطط الخدمة','Subscriptions':'الاشتراكات','Invoices':'الفواتير','Revenue':'الإيرادات','Payments':'المدفوعات','Payment Methods':'طرق الدفع','Users & Roles':'المستخدمون والأدوار','Organizations':'المؤسسات','Add':'إضافة','Tambah':'إضافة','Create':'إنشاء','Edit':'تعديل','Save':'حفظ','Simpan':'حفظ','Update':'تحديث','Delete':'حذف','Hapus':'حذف','Cancel':'إلغاء','Close':'إغلاق','Submit':'إرسال','Search':'بحث','View':'عرض','Actions':'الإجراءات','Name':'الاسم','Email':'البريد الإلكتروني','Username':'اسم المستخدم','Password':'كلمة المرور','Confirm':'تأكيد','Back':'رجوع','Profile':'الملف الشخصي','Settings':'الإعدادات','Logout':'تسجيل الخروج','Role':'الدور','Date':'التاريخ','Amount':'المبلغ','Description':'الوصف','Active':'نشط','Inactive':'غير نشط','No data available.':'لا توجد بيانات','Select':'اختيار'},
        tr: {'Dashboard':'Gösterge Paneli','Dasbor':'Gösterge Paneli','Customers':'Müşteriler','Pelanggan':'Müşteriler','Service Plans':'Hizmet Planları','Subscriptions':'Abonelikler','Invoices':'Faturalar','Revenue':'Gelir','Payments':'Ödemeler','Payment Methods':'Ödeme Yöntemleri','Users & Roles':'Kullanıcılar ve Roller','Organizations':'Kuruluşlar','Add':'Ekle','Tambah':'Ekle','Create':'Oluştur','Edit':'Düzenle','Save':'Kaydet','Simpan':'Kaydet','Update':'Güncelle','Delete':'Sil','Hapus':'Sil','Cancel':'İptal','Close':'Kapat','Submit':'Gönder','Search':'Ara','View':'Görüntüle','Actions':'İşlemler','Name':'Ad','Email':'E-posta','Username':'Kullanıcı adı','Password':'Parola','Confirm':'Onayla','Back':'Geri','Profile':'Profil','Settings':'Ayarlar','Logout':'Çıkış','Role':'Rol','Date':'Tarih','Amount':'Tutar','Description':'Açıklama','Active':'Aktif','Inactive':'Pasif','No data available.':'Veri yok.','Select':'Seç'},
        it: {'Dashboard':'Dashboard','Dasbor':'Dashboard','Customers':'Clienti','Pelanggan':'Clienti','Service Plans':'Piani di servizio','Subscriptions':'Abbonamenti','Invoices':'Fatture','Revenue':'Entrate','Payments':'Pagamenti','Payment Methods':'Metodi di pagamento','Users & Roles':'Utenti e ruoli','Organizations':'Organizzazioni','Add':'Aggiungi','Tambah':'Aggiungi','Create':'Crea','Edit':'Modifica','Save':'Salva','Simpan':'Salva','Update':'Aggiorna','Delete':'Elimina','Hapus':'Elimina','Cancel':'Annulla','Close':'Chiudi','Submit':'Invia','Search':'Cerca','View':'Visualizza','Actions':'Azioni','Name':'Nome','Email':'E-mail','Username':'Nome utente','Password':'Password','Confirm':'Conferma','Back':'Indietro','Profile':'Profilo','Settings':'Impostazioni','Logout':'Esci','Role':'Ruolo','Date':'Data','Amount':'Importo','Description':'Descrizione','Active':'Attivo','Inactive':'Inattivo','No data available.':'Nessun dato disponibile.','Select':'Seleziona'},
        nl: {'Dashboard':'Dashboard','Dasbor':'Dashboard','Customers':'Klanten','Pelanggan':'Klanten','Service Plans':'Serviceplannen','Subscriptions':'Abonnementen','Invoices':'Facturen','Revenue':'Omzet','Payments':'Betalingen','Payment Methods':'Betaalmethoden','Users & Roles':'Gebruikers en rollen','Organizations':'Organisaties','Add':'Toevoegen','Tambah':'Toevoegen','Create':'Maken','Edit':'Bewerken','Save':'Opslaan','Simpan':'Opslaan','Update':'Bijwerken','Delete':'Verwijderen','Hapus':'Verwijderen','Cancel':'Annuleren','Close':'Sluiten','Submit':'Verzenden','Search':'Zoeken','View':'Bekijken','Actions':'Acties','Name':'Naam','Email':'E-mail','Username':'Gebruikersnaam','Password':'Wachtwoord','Confirm':'Bevestigen','Back':'Terug','Profile':'Profiel','Settings':'Instellingen','Logout':'Uitloggen','Role':'Rol','Date':'Datum','Amount':'Bedrag','Description':'Beschrijving','Active':'Actief','Inactive':'Inactief','No data available.':'Geen gegevens beschikbaar.','Select':'Selecteren'},
        ru: {'Dashboard':'Панель управления','Dasbor':'Панель управления','Customers':'Клиенты','Pelanggan':'Клиенты','Service Plans':'Тарифы','Subscriptions':'Подписки','Invoices':'Счета','Revenue':'Доход','Payments':'Платежи','Payment Methods':'Способы оплаты','Users & Roles':'Пользователи и роли','Organizations':'Организации','Add':'Добавить','Tambah':'Добавить','Create':'Создать','Edit':'Изменить','Save':'Сохранить','Simpan':'Сохранить','Update':'Обновить','Delete':'Удалить','Hapus':'Удалить','Cancel':'Отмена','Close':'Закрыть','Submit':'Отправить','Search':'Поиск','View':'Просмотр','Actions':'Действия','Name':'Имя','Email':'Электронная почта','Username':'Имя пользователя','Password':'Пароль','Confirm':'Подтвердить','Back':'Назад','Profile':'Профиль','Settings':'Настройки','Logout':'Выйти','Role':'Роль','Date':'Дата','Amount':'Сумма','Description':'Описание','Active':'Активен','Inactive':'Неактивен','No data available.':'Нет данных','Select':'Выбрать'}
    };
    const dictionary = { ...(dictionaries[locale] || {}), ...(commonTranslations[locale] || commonTranslations.en) };
    const entries = Object.entries(dictionary)
        .filter(([source, target]) => source && source !== target)
        .sort((a, b) => b[0].length - a[0].length);
    const protectedTags = new Set(['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'PRE', 'CODE', 'INPUT', 'TEXTAREA']);
    const translateText = (text) => {
        const leading = text.match(/^\s*/)?.[0] || '';
        const trailing = text.match(/\s*$/)?.[0] || '';
        const value = text.trim();
        if (!value) return text;
        const exact = dictionary[value];
        if (exact) return leading + exact + trailing;
        // Only translate a phrase when it is visibly a UI phrase. This avoids
        // changing customer names, IDs, invoice numbers, and database values.
        if (value.length < 4 || !/[\s&/:-]/.test(value)) return text;
        const translated = entries.reduce((result, [source, target]) => {
            const pattern = new RegExp(`(^|\\s)${source.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}(?=\\s|$)`, 'g');
            return result.replace(pattern, `$1${target}`);
        }, value);
        return leading + translated + trailing;
    };
    const shouldSkip = (element) => !element
        || protectedTags.has(element.tagName)
        || element.closest?.('[data-no-i18n], [contenteditable="true"]');
    const translate = (root) => {
        if (!root) return;
        const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
            acceptNode: (node) => shouldSkip(node.parentElement)
                ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT
        });
        const nodes = [];
        while (walker.nextNode()) nodes.push(walker.currentNode);
        nodes.forEach((node) => {
            const original = node.nodeValue;
            const translated = translateText(original);
            if (translated !== original) node.nodeValue = translated;
        });
        root.querySelectorAll?.('[title], [aria-label], option').forEach((element) => {
            ['placeholder', 'title', 'aria-label'].forEach((attribute) => {
                const value = element.getAttribute(attribute);
                if (value && attribute !== 'placeholder') element.setAttribute(attribute, translateText(value));
            });
            if (element.tagName === 'OPTION') element.textContent = translateText(element.textContent);
        });
        root.querySelectorAll?.('input[placeholder], textarea[placeholder]').forEach((element) => {
            element.setAttribute('placeholder', translateText(element.getAttribute('placeholder') || ''));
        });
    };
    translate(document.body);
    if (document.title) document.title = translateText(document.title);

    // Forms and modal contents are often inserted after page load.
    let translating = false;
    new MutationObserver((mutations) => {
        if (translating) return;
        translating = true;
        mutations.forEach(({ addedNodes }) => addedNodes.forEach((node) => {
            if (node.nodeType === Node.ELEMENT_NODE) translate(node);
        }));
        translating = false;
    }).observe(document.body, { childList: true, subtree: true });

    const languageNames = {
        id: 'Bahasa Indonesia', en: 'English', ms: 'Bahasa Melayu', zh: '简体中文', ja: '日本語', ko: '한국어',
        ar: 'العربية', es: 'Español', fr: 'Français', de: 'Deutsch', pt: 'Português', hi: 'हिन्दी', tr: 'Türkçe',
        it: 'Italiano', nl: 'Nederlands', ru: 'Русский'
    };
    if (!document.querySelector('[aria-label="Language"], .language-switcher, a[href*="lang="]')) {
        const wrapper = document.createElement('div');
        wrapper.className = 'bajama-i18n-fallback';
        const select = document.createElement('select');
        select.className = 'form-select form-select-sm shadow-sm';
        select.setAttribute('aria-label', 'Language');
        Object.entries(languageNames).forEach(([code, name]) => {
            const option = new Option(name, code, code === locale, code === locale);
            select.appendChild(option);
        });
        select.addEventListener('change', () => {
            const url = new URL(window.location.href);
            url.searchParams.set('lang', select.value);
            window.location.href = url.toString();
        });
        wrapper.appendChild(select);
        document.body.appendChild(wrapper);
    }

})();
