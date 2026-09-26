<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use BAJAMA\Core\Auth;
use BAJAMA\Core\License;
use BAJAMA\Core\RBAC;
use BAJAMA\Core\Tenant;
use BAJAMA\Network\MikroTik;
use BAJAMA\Network\MikroTikPermissionGuard;

Auth::requireLogin();

$db = db();

License::requireFeature(
    $db,
    'mikrotik'
);

RBAC::require(
    $db,
    'mikrotik.view'
);

header(
    'Content-Type: application/json; charset=utf-8'
);

header(
    'Cache-Control: no-store, no-cache, must-revalidate'
);

function json_ok($data): void
{
    echo json_encode(
        [
            'ok' => true,
            'data' => $data,
        ],
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

function json_error(
    string $message,
    int $status = 400
): void {
    http_response_code($status);

    echo json_encode(
        [
            'ok' => false,
            'message' => $message,
        ],
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function mikrotik_editable_attributes(string $resource): array
{
    return [
        'interfaces' => ['name', 'disabled', 'comment', 'mtu'],
        'vlans' => ['name', 'vlan-id', 'interface', 'disabled', 'comment'],
        'ip_addresses' => ['address', 'interface', 'disabled', 'comment'],
        'routes' => ['dst-address', 'gateway', 'distance', 'scope', 'target-scope', 'check-gateway', 'routing-table', 'routing-mark', 'disabled', 'comment'],
        'dhcp_servers' => ['name', 'interface', 'address-pool', 'lease-time', 'disabled', 'comment'],
        'dhcp_leases' => ['address', 'mac-address', 'server', 'rate-limit', 'comment', 'disabled'],
        'pppoe' => ['name', 'password', 'profile', 'service', 'caller-id', 'disabled', 'comment', 'remote-address', 'local-address'],
        'ppp_profiles' => ['name', 'local-address', 'remote-address', 'rate-limit', 'dns-server', 'session-timeout', 'comment'],
        'hotspot' => ['name', 'password', 'profile', 'limit-uptime', 'limit-bytes-total', 'disabled', 'comment'],
        'hotspot_servers' => ['name', 'interface', 'address-pool', 'profile', 'disabled', 'comment'],
        'queues' => ['name', 'target', 'max-limit', 'limit-at', 'burst-limit', 'priority', 'disabled', 'comment'],
        'firewall_filter' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'src-port', 'dst-port', 'in-interface', 'out-interface', 'connection-state', 'disabled', 'comment'],
        'firewall_nat' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'src-port', 'dst-port', 'in-interface', 'out-interface', 'to-addresses', 'to-ports', 'disabled', 'comment'],
        'firewall_mangle' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'src-port', 'dst-port', 'new-connection-mark', 'new-routing-mark', 'passthrough', 'disabled', 'comment'],
        'firewall_address_list' => ['list', 'address', 'timeout', 'comment'],
        'pppoe_servers' => ['service-name', 'interface', 'default-profile', 'disabled', 'comment'],
        'firewall_raw' => ['chain', 'action', 'protocol', 'src-address', 'dst-address', 'disabled', 'comment'],
        'ip_pools' => ['name', 'ranges', 'next-pool'],
        'dhcp_networks' => ['address', 'gateway', 'dns-server', 'domain', 'comment'],
        'interface_lists' => ['name', 'comment'],
        'users' => ['name', 'group', 'password', 'address', 'disabled', 'comment'],
        'dns' => ['servers', 'allow-remote-requests', 'cache-size', 'max-udp-packet-size', 'query-server-timeout', 'query-total-timeout'],
        'identity' => ['name'],
    ][$resource] ?? [];
}

try {
    $organizationId =
        (int)Tenant::id();

    $routerId =
        (int)(
            $_GET['router_id']
            ?? $_POST['router_id']
            ?? 0
        );

    $action =
        trim(
            (string)(
                $_GET['action']
                ?? $_POST['action']
                ?? ''
            )
        );

    /*
     * UI lama mengirim action=add/edit/enable/disable/delete,
     * sedangkan endpoint sebelumnya hanya membaca item_action.
     * Normalisasi dilakukan sebelum validasi action baca agar tombol
     * WinBox tidak dianggap sebagai action API yang tidak dikenal.
     */
    $itemAction = trim((string)($_POST['item_action'] ?? ''));
    $resourceName = trim((string)($_POST['resource'] ?? ''));
    $itemId = trim((string)($_POST['item_id'] ?? ''));

    if (
        $itemAction === ''
        && in_array($action, ['add', 'create', 'edit', 'enable', 'disable', 'delete'], true)
        && $resourceName !== ''
    ) {
        $itemAction = $action;
    }

    if ($routerId < 1) {
        json_error(
            'router_id wajib diisi.'
        );
    }

    $router = MikroTik::find(
        $db,
        $organizationId,
        $routerId
    );

    /*
     * Sangat penting:
     * router selalu dicari menggunakan
     * organization_id milik session.
     */
    if (!$router) {
        json_error(
            'MikroTik tidak ditemukan.',
            404
        );
    }

    /*
     * Semua action yang hanya membaca
     * diperbolehkan dengan mikrotik.view.
     */
    $readActions = [
        'resource',
        'identity',
        'health',
        'interfaces',
        'ip_addresses',
        'routes',
        'arp',
        'dhcp_servers',
        'dhcp_leases',
        'pppoe',
        'ppp_active',
        'ppp_profiles',
        'hotspot',
        'hotspot_active',
        'hotspot_servers',
        'queues',
        'firewall_filter',
        'firewall_nat',
        'firewall_mangle',
        'firewall_address_list',
        'dns',
        'clock',
        'users',
        'logs',
        'vlans',
        'firewall_raw',
        'ip_pools',
        'dhcp_networks',
        'interface_lists',
    ];

    /*
     * Terminal/raw API sengaja dianggap
     * sebagai operasi management.
     */
    if (
        $action === 'raw' ||
        $action === 'command' ||
        $action === 'terminal'
    ) {
        RBAC::require(
            $db,
            'mikrotik.manage'
        );
    }

    if (
        $itemAction === ''
        && (
            $action === ''
            || (
                !in_array($action, $readActions, true)
                    && !in_array($action, ['raw', 'command', 'terminal'], true)
            )
        )
    ) {
        json_error(
            'Action API tidak dikenal.'
        );
    }


    /*
     * ============================================================
     * WINBOX-LIKE OBJECT ACTIONS
     * ADD / ENABLE / DISABLE / DELETE
     * ============================================================
     */
    /*
     * RouterOS resource allowlist.
     * Tidak menerima path RouterOS mentah dari browser.
     */
    $resourceMap = [
        'interfaces' => [
            'path' => '/interface',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => false,
        ],

        'vlans' => [
            'path' => '/interface/vlan',
            'menu_key' => 'interfaces.vlans',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'ip_addresses' => [
            'path' => '/ip/address',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'routes' => [
            'path' => '/ip/route',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'dhcp_servers' => [
            'path' => '/ip/dhcp-server',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'dhcp_leases' => [
            'path' => '/ip/dhcp-server/lease',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'pppoe' => [
            'path' => '/ppp/secret',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'ppp_profiles' => [
            'path' => '/ppp/profile',
            'add' => true,
            'enable' => false,
            'disable' => false,
            'delete' => true,
        ],

        'hotspot' => [
            'path' => '/ip/hotspot/user',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'hotspot_servers' => [
            'path' => '/ip/hotspot',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'queues' => [
            'path' => '/queue/simple',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'firewall_filter' => [
            'path' => '/ip/firewall/filter',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'firewall_nat' => [
            'path' => '/ip/firewall/nat',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'firewall_mangle' => [
            'path' => '/ip/firewall/mangle',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'firewall_address_list' => [
            'path' => '/ip/firewall/address-list',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'pppoe_servers' => [
            'path' => '/interface/pppoe-server/server',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'firewall_raw' => [
            'path' => '/ip/firewall/raw',
            'menu_key' => 'firewall.raw',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'ip_pools' => [
            'path' => '/ip/pool',
            'menu_key' => 'ip.pools',
            'add' => true,
            'enable' => false,
            'disable' => false,
            'delete' => true,
        ],

        'dhcp_networks' => [
            'path' => '/ip/dhcp-server/network',
            'menu_key' => 'dhcp.networks',
            'add' => true,
            'enable' => false,
            'disable' => false,
            'delete' => true,
        ],

        'interface_lists' => [
            'path' => '/interface/list',
            'menu_key' => 'interfaces.lists',
            'add' => true,
            'enable' => false,
            'disable' => false,
            'delete' => true,
        ],

        'users' => [
            'path' => '/user',
            'menu_key' => 'system.users',
            'add' => true,
            'enable' => true,
            'disable' => true,
            'delete' => true,
        ],

        'dns' => [
            'path' => '/ip/dns',
            'menu_key' => 'system.dns',
            'add' => false,
            'edit' => true,
            'enable' => false,
            'disable' => false,
            'delete' => false,
        ],

        'identity' => [
            'path' => '/system/identity',
            'menu_key' => 'system.identity',
            'add' => false,
            'edit' => true,
            'enable' => false,
            'disable' => false,
            'delete' => false,
        ],
    ];

    /*
     * Semua perubahan konfigurasi MikroTik wajib POST
     * dan membutuhkan permission mikrotik.manage.
     */
    if ($itemAction !== '') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_error('Operasi perubahan MikroTik harus menggunakan POST.', 405);
        }

        RBAC::require($db, 'mikrotik.manage');

        $csrf = $_POST['csrf_token'] ?? $_POST['csrf'] ?? '';

        if (function_exists('verifyCsrf')) {
            verifyCsrf($csrf);
        } elseif (function_exists('verify_csrf')) {
            verify_csrf($csrf);
        }

        if (!isset($resourceMap[$resourceName])) {
            json_error('Resource MikroTik tidak diizinkan.');
        }

        $definition = $resourceMap[$resourceName];

        if ($itemAction === 'create') {
            $itemAction = 'add';
        }

        if (!in_array(
            $itemAction,
            ['add', 'edit', 'enable', 'disable', 'delete'],
            true
        )) {
            json_error('Operasi MikroTik tidak dikenal.');
        }

        if (empty($definition[$itemAction])) {
            json_error(
                'Operasi ' . strtoupper($itemAction) .
                ' tidak tersedia untuk resource ini.'
            );
        }

        $resourceMenuKeys = [
            'interfaces' => 'interfaces.list',
            'vlans' => 'interfaces.vlans',
            'ip_addresses' => 'ip.addresses',
            'routes' => 'ip.routes',
            'dhcp_servers' => 'dhcp.servers',
            'dhcp_leases' => 'dhcp.leases',
            'pppoe' => 'ppp.secrets',
            'ppp_profiles' => 'ppp.profiles',
            'hotspot' => 'hotspot.users',
            'hotspot_servers' => 'hotspot.servers',
            'queues' => 'queues.simple',
            'firewall_filter' => 'firewall.filter',
            'firewall_nat' => 'firewall.nat',
            'firewall_mangle' => 'firewall.mangle',
            'firewall_address_list' => 'firewall.address_list',
            'pppoe_servers' => 'ppp.servers',
            'firewall_raw' => 'firewall.raw',
            'ip_pools' => 'ip.pools',
            'dhcp_networks' => 'dhcp.networks',
            'interface_lists' => 'interfaces.lists',
            'users' => 'system.users',
            'dns' => 'system.dns',
            'identity' => 'system.identity',
        ];

        $menuKey = $resourceMenuKeys[$resourceName] ?? null;

        if ($menuKey !== null) {
            $menuGuard = new MikroTikPermissionGuard(
                $db,
                $organizationId,
                (int)Auth::userId(),
                $routerId
            );

            $menuAction = $itemAction === 'add'
                ? 'create'
                : $itemAction;

            $menuGuard->require($menuKey, $menuAction);
        }

        $api = MikroTik::connect($router);

        try {
            /*
         * ADD / EDIT menggunakan attributes dari browser.
         * Field internal .id selalu dikontrol server.
         */
        if (
            $itemAction === 'add' ||
            $itemAction === 'edit'
        ) {
            $attributesRaw = $_POST['attributes'] ?? '';

            if (is_string($attributesRaw)) {
                $decoded = json_decode(
                    $attributesRaw,
                    true
                );

                $attributes = is_array($decoded)
                    ? $decoded
                    : [];
            } elseif (is_array($attributesRaw)) {
                $attributes = $attributesRaw;
            } else {
                $attributes = [];
            }

            if (!$attributes) {
                json_error(
                    $itemAction === 'add'
                        ? 'Parameter Add tidak valid.'
                        : 'Parameter Edit tidak valid.'
                );
            }

            $cleanAttributes = [];

            foreach ($attributes as $key => $value) {
                $key = trim((string)$key);

                if (
                    $key === '' ||
                    $key[0] === '?'
                ) {
                    continue;
                }

                if (isset($key[0]) && $key[0] === '=') {
                    $key = ltrim($key, '=');
                }

                /*
                 * .id tidak boleh dikirim dari attributes.
                 * Untuk EDIT, target selalu memakai item_id.
                 */
                if ($key === '.id') {
                    continue;
                }

                if (
                    is_array($value) ||
                    is_object($value)
                ) {
                    continue;
                }

                /*
                 * Jangan mengirim field kosong saat EDIT
                 * kecuali memang sengaja dikosongkan.
                 */
                $cleanAttributes[$key] =
                    (string)$value;
            }

            $editableKeys = mikrotik_editable_attributes($resourceName);
            $cleanAttributes = array_intersect_key(
                $cleanAttributes,
                array_flip($editableKeys)
            );

            if (!$cleanAttributes) {
                json_error(
                    $itemAction === 'add'
                        ? 'Data Add tidak boleh kosong.'
                        : 'Tidak ada perubahan yang dikirim.'
                );
            }

            if ($itemAction === 'add') {
                $data = $api->raw(
                    $definition['path'] . '/add',
                    $cleanAttributes
                );

                json_ok($data);
            }

            /*
             * EDIT = RouterOS /set
             */
            if ($itemId === '') {
                json_error(
                    'ID item MikroTik tidak ditemukan.'
                );
            }

            if (!isset($itemId[0]) || $itemId[0] !== '*') {
                json_error(
                    'ID item MikroTik tidak valid.'
                );
            }

            $cleanAttributes['.id'] = $itemId;

            $data = $api->raw(
                $definition['path'] . '/set',
                $cleanAttributes
            );

            json_ok($data);
        }

        if ($itemId === '') {
            json_error(
                'ID item MikroTik tidak ditemukan.'
            );
        }

        if (!isset($itemId[0]) || $itemId[0] !== '*') {
            json_error(
                'ID item MikroTik tidak valid.'
            );
        }

        switch ($itemAction) {
            case 'enable':
                $command =
                    $definition['path'] . '/enable';
                break;

            case 'disable':
                $command =
                    $definition['path'] . '/disable';
                break;

            case 'delete':
                $command =
                    $definition['path'] . '/remove';
                break;

            default:
                json_error(
                    'Operasi tidak valid.'
                );
        }

        $data = $api->raw(
            $command,
            [
                '.id' => $itemId
            ]
        );

        json_ok($data);

        } finally {
            $api->disconnect();
        }
    }

    $api = MikroTik::connect(
        $router
    );

    try {
        switch ($action) {
            case 'resource':
                $data = $api->resource();
                break;

            case 'identity':
                $data = $api->identity();
                break;

            case 'health':
                $data = $api->health();
                break;

            case 'interfaces':
                $data = $api->interfaces();
                break;

            case 'ip_addresses':
                $data = $api->ipAddresses();
                break;

            case 'routes':
                $data = $api->routes();
                break;

            case 'arp':
                $data = $api->arp();
                break;

            case 'dhcp_servers':
                $data = $api->dhcpServers();
                break;

            case 'dhcp_leases':
                $data = $api->dhcpLeases();
                break;

            case 'pppoe':
                $data = $api->pppoeSecrets();
                break;

            case 'ppp_active':
                $data = $api->pppActive();
                break;

            case 'ppp_profiles':
                $data = $api->pppProfiles();
                break;

            case 'hotspot':
                $data = $api->hotspotUsers();
                break;

            case 'hotspot_active':
                $data = $api->hotspotActive();
                break;

            case 'hotspot_servers':
                $data = $api->hotspotServers();
                break;

            case 'queues':
                $data = $api->simpleQueues();
                break;

            case 'firewall_filter':
                $data = $api->firewallFilter();
                break;

            case 'firewall_nat':
                $data = $api->firewallNat();
                break;

            case 'firewall_mangle':
                $data = $api->firewallMangle();
                break;

            case 'firewall_address_list':
                $data = $api->firewallAddressList();
                break;

            case 'dns':
                $data = $api->dns();
                break;

            case 'clock':
                $data = $api->clock();
                break;

            case 'users':
                $data = $api->users();
                break;

            case 'logs':
                $data = $api->logs();
                break;

            case 'vlans':
                $data = $api->vlans();
                break;

            case 'firewall_raw':
                $data = $api->raw('/ip/firewall/raw/print');
                break;

            case 'ip_pools':
                $data = $api->raw('/ip/pool/print');
                break;

            case 'dhcp_networks':
                $data = $api->raw('/ip/dhcp-server/network/print');
                break;

            case 'interface_lists':
                $data = $api->raw('/interface/list/print');
                break;

            case 'raw':
            case 'command':
            case 'terminal':
                $command =
                    trim(
                        (string)(
                            $_POST['command']
                            ?? $_GET['command']
                            ?? ''
                        )
                    );

                if (
                    $command === '' ||
                    $command[0] !== '/'
                ) {
                    json_error(
                        'Command RouterOS harus diawali /.'
                    );
                }

                /*
                 * Batasi command raw ke operasi
                 * print/read terlebih dahulu.
                 *
                 * Perintah konfigurasi seperti
                 * /ip/firewall/filter/add tidak
                 * dijalankan melalui endpoint ini.
                 */
                $blocked = [
                    '/add',
                    '/set',
                    '/remove',
                    '/delete',
                    '/enable',
                    '/disable',
                    '/move',
                    '/reset',
                    '/reboot',
                    '/shutdown',
                    '/export',
                    '/import',
                ];

                $lower =
                    strtolower($command);

                foreach ($blocked as $word) {
                    if (
                        strpos(
                            $lower,
                            $word
                        ) !== false
                    ) {
                        json_error(
                            'Command perubahan konfigurasi diblokir dari API raw.'
                        );
                    }
                }

                $data =
                    $api->raw(
                        $command
                    );

                break;

            default:
                json_error(
                    'Action API tidak dikenal.'
                );
        }
    } finally {
        $api->disconnect();
    }

    json_ok($data);

} catch (\Throwable $e) {
    json_error(
        MikroTik::safeErrorMessage($e),
        400
    );
}
