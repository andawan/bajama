<?php

namespace BAJAMA\Network;

use PDO;
use RuntimeException;

final class MikroTikMenuManager
{
    private $db;
    private $organizationId;
    private $userId;
    private $routerId;

    public function __construct(
        PDO $db,
        int $organizationId,
        int $userId,
        int $routerId
    ) {
        if ($organizationId <= 0) {
            throw new RuntimeException('Organization ID tidak valid.');
        }

        if ($userId <= 0) {
            throw new RuntimeException('User ID tidak valid.');
        }

        if ($routerId <= 0) {
            throw new RuntimeException('Router ID tidak valid.');
        }

        $this->db = $db;
        $this->organizationId = $organizationId;
        $this->userId = $userId;
        $this->routerId = $routerId;
    }

    public function userId(): int
    {
        return $this->userId;
    }

    public function routerId(): int
    {
        return $this->routerId;
    }

    public function organizationId(): int
    {
        return $this->organizationId;
    }

    /**
     * Memastikan router benar-benar milik organization yang sedang aktif.
     */
    public function routerBelongsToOrganization(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM mikrotik_routers
             WHERE id = ?
               AND organization_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $this->routerId,
            $this->organizationId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Memastikan user benar-benar berada pada organization yang sama.
     */
    public function userBelongsToOrganization(): bool
    {
        $stmt = $this->db->prepare(
            'SELECT id
             FROM users
             WHERE id = ?
               AND organization_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $this->userId,
            $this->organizationId
        ]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Validasi tenant boundary.
     */
    public function validateContext(): bool
    {
        return $this->routerBelongsToOrganization()
            && $this->userBelongsToOrganization();
    }

    /**
     * Ambil seluruh master menu aktif dalam bentuk tree.
     */
    public function menuTree(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                id,
                parent_id,
                menu_key,
                label,
                icon,
                route,
                item_type,
                sort_order,
                active
             FROM mikrotik_menu_definitions
             WHERE active = 1
             ORDER BY
                COALESCE(parent_id, 0),
                sort_order,
                id'
        );

        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $indexed = [];

        foreach ($rows as $row) {
            $id = (int) $row['id'];

            $indexed[$id] = [
                'id' => $id,
                'parent_id' => $row['parent_id'] !== null
                    ? (int) $row['parent_id']
                    : null,
                'menu_key' => $row['menu_key'],
                'label' => $row['label'],
                'icon' => $row['icon'],
                'route' => $row['route'],
                'item_type' => $row['item_type'],
                'sort_order' => (int) $row['sort_order'],
                'active' => (int) $row['active'],
                'children' => []
            ];
        }

        foreach ($indexed as $id => $row) {
            if (
                $row['parent_id'] !== null
                && isset($indexed[$row['parent_id']])
            ) {
                $indexed[$row['parent_id']]['children'][] = &$indexed[$id];
            }
        }

        $tree = [];

        foreach ($indexed as $id => $row) {
            if ($row['parent_id'] === null) {
                $tree[] = $row;
            }
        }

        return $tree;
    }

    /**
     * Ambil permission satu menu untuk user + router.
     *
     * Jika belum ada record, gunakan default aman:
     * enabled      = 0
     * can_view     = 0
     * semua action = 0
     *
     * Artinya menu baru TIDAK otomatis terbuka ke semua user.
     */
    public function permission(int $menuId): array
    {
        if ($menuId <= 0) {
            return $this->defaultPermission();
        }

        $stmt = $this->db->prepare(
            'SELECT
                enabled,
                can_view,
                can_create,
                can_edit,
                can_enable,
                can_disable,
                can_delete
             FROM mikrotik_user_router_menu_permissions
             WHERE organization_id = ?
               AND router_id = ?
               AND user_id = ?
               AND menu_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $this->organizationId,
            $this->routerId,
            $this->userId,
            $menuId
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return $this->defaultPermission();
        }

        return [
            'enabled' => (int) $row['enabled'],
            'can_view' => (int) $row['can_view'],
            'can_create' => (int) $row['can_create'],
            'can_edit' => (int) $row['can_edit'],
            'can_enable' => (int) $row['can_enable'],
            'can_disable' => (int) $row['can_disable'],
            'can_delete' => (int) $row['can_delete']
        ];
    }

    /**
     * Cek satu permission action.
     */
    public function can(int $menuId, string $action): bool
    {
        $allowedActions = [
            'view',
            'create',
            'edit',
            'enable',
            'disable',
            'delete'
        ];

        if (!in_array($action, $allowedActions, true)) {
            return false;
        }

        $permission = $this->permission($menuId);

        if (!$permission['enabled']) {
            return false;
        }

        $field = 'can_' . $action;

        return !empty($permission[$field]);
    }

    /**
     * Menu boleh ditampilkan apabila:
     * 1. aktif secara master
     * 2. enabled untuk user + router
     * 3. user memiliki view
     */
    public function canView(int $menuId): bool
    {
        return $this->can($menuId, 'view');
    }

    /**
     * Ambil semua permission user pada router.
     */
    public function permissions(): array
    {
        $stmt = $this->db->prepare(
            'SELECT
                p.menu_id,
                p.enabled,
                p.can_view,
                p.can_create,
                p.can_edit,
                p.can_enable,
                p.can_disable,
                p.can_delete
             FROM mikrotik_user_router_menu_permissions p
             INNER JOIN mikrotik_menu_definitions m
                 ON m.id = p.menu_id
             WHERE p.organization_id = ?
               AND p.router_id = ?
               AND p.user_id = ?
               AND m.active = 1
             ORDER BY m.sort_order, m.id'
        );

        $stmt->execute([
            $this->organizationId,
            $this->routerId,
            $this->userId
        ]);

        $result = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(int) $row['menu_id']] = [
                'enabled' => (int) $row['enabled'],
                'can_view' => (int) $row['can_view'],
                'can_create' => (int) $row['can_create'],
                'can_edit' => (int) $row['can_edit'],
                'can_enable' => (int) $row['can_enable'],
                'can_disable' => (int) $row['can_disable'],
                'can_delete' => (int) $row['can_delete']
            ];
        }

        return $result;
    }

    /**
     * Default permission untuk menu yang belum dikonfigurasi.
     */
    private function defaultPermission(): array
    {
        return [
            'enabled' => 0,
            'can_view' => 0,
            'can_create' => 0,
            'can_edit' => 0,
            'can_enable' => 0,
            'can_disable' => 0,
            'can_delete' => 0
        ];
    }
}
