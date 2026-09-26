<?php

namespace BAJAMA\Network;

use PDO;
use RuntimeException;

final class MikroTikMenuPermissionService
{
    private $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Daftar action yang diperbolehkan sistem.
     */
    public static function allowedActions(): array
    {
        return [
            'view',
            'create',
            'edit',
            'enable',
            'disable',
            'delete'
        ];
    }

    /**
     * Validasi user + router berada pada organization yang sama.
     */
    public function validateContext(
        int $organizationId,
        int $userId,
        int $routerId
    ): bool {
        if ($organizationId <= 0 || $userId <= 0 || $routerId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT
                u.id AS user_id,
                r.id AS router_id
             FROM users u
             INNER JOIN mikrotik_routers r
                 ON r.organization_id = u.organization_id
             WHERE u.id = ?
               AND r.id = ?
               AND u.organization_id = ?
             LIMIT 1'
        );

        $stmt->execute([
            $userId,
            $routerId,
            $organizationId
        ]);

        return (bool) $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Pastikan menu memang ada dan aktif.
     */
    public function menuExists(int $menuId): bool
    {
        if ($menuId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            'SELECT id
             FROM mikrotik_menu_definitions
             WHERE id = ?
               AND active = 1
             LIMIT 1'
        );

        $stmt->execute([$menuId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Normalisasi nilai boolean permission.
     */
    private function boolValue($value): int
    {
        return !empty($value) ? 1 : 0;
    }

    /**
     * Simpan satu permission menggunakan UPSERT.
     *
     * Tidak membuat duplicate karena tabel memiliki
     * unique key:
     *
     * organization_id + router_id + user_id + menu_id
     */
    public function save(
        int $organizationId,
        int $userId,
        int $routerId,
        int $menuId,
        array $permissions
    ): bool {
        if (!$this->validateContext(
            $organizationId,
            $userId,
            $routerId
        )) {
            throw new RuntimeException(
                'User dan router tidak berada pada organization yang sama.'
            );
        }

        if (!$this->menuExists($menuId)) {
            throw new RuntimeException(
                'Menu tidak ditemukan atau tidak aktif.'
            );
        }

        $enabled = $this->boolValue(
            $permissions['enabled'] ?? 0
        );

        $canView = $this->boolValue(
            $permissions['can_view'] ?? 0
        );

        $canCreate = $this->boolValue(
            $permissions['can_create'] ?? 0
        );

        $canEdit = $this->boolValue(
            $permissions['can_edit'] ?? 0
        );

        $canEnable = $this->boolValue(
            $permissions['can_enable'] ?? 0
        );

        $canDisable = $this->boolValue(
            $permissions['can_disable'] ?? 0
        );

        $canDelete = $this->boolValue(
            $permissions['can_delete'] ?? 0
        );

        /*
         * Jika menu dimatikan, seluruh action otomatis dimatikan.
         *
         * Ini mencegah kondisi:
         * enabled = OFF
         * can_delete = ON
         */
        if (!$enabled) {
            $canView = 0;
            $canCreate = 0;
            $canEdit = 0;
            $canEnable = 0;
            $canDisable = 0;
            $canDelete = 0;
        }

        $sql = '
            INSERT INTO mikrotik_user_router_menu_permissions
            (
                organization_id,
                router_id,
                user_id,
                menu_id,
                enabled,
                can_view,
                can_create,
                can_edit,
                can_enable,
                can_disable,
                can_delete
            )
            VALUES
            (
                :organization_id,
                :router_id,
                :user_id,
                :menu_id,
                :enabled,
                :can_view,
                :can_create,
                :can_edit,
                :can_enable,
                :can_disable,
                :can_delete
            )
            ON DUPLICATE KEY UPDATE
                enabled = VALUES(enabled),
                can_view = VALUES(can_view),
                can_create = VALUES(can_create),
                can_edit = VALUES(can_edit),
                can_enable = VALUES(can_enable),
                can_disable = VALUES(can_disable),
                can_delete = VALUES(can_delete),
                updated_at = CURRENT_TIMESTAMP
        ';

        $stmt = $this->db->prepare($sql);

        return $stmt->execute([
            ':organization_id' => $organizationId,
            ':router_id' => $routerId,
            ':user_id' => $userId,
            ':menu_id' => $menuId,
            ':enabled' => $enabled,
            ':can_view' => $canView,
            ':can_create' => $canCreate,
            ':can_edit' => $canEdit,
            ':can_enable' => $canEnable,
            ':can_disable' => $canDisable,
            ':can_delete' => $canDelete
        ]);
    }

    /**
     * Hapus konfigurasi permission.
     *
     * Penghapusan hanya dilakukan pada kombinasi
     * organization + router + user + menu yang tepat.
     */
    public function delete(
        int $organizationId,
        int $userId,
        int $routerId,
        int $menuId
    ): bool {
        if (!$this->validateContext(
            $organizationId,
            $userId,
            $routerId
        )) {
            throw new RuntimeException(
                'Context organization tidak valid.'
            );
        }

        $stmt = $this->db->prepare(
            'DELETE FROM mikrotik_user_router_menu_permissions
             WHERE organization_id = ?
               AND router_id = ?
               AND user_id = ?
               AND menu_id = ?'
        );

        return $stmt->execute([
            $organizationId,
            $routerId,
            $userId,
            $menuId
        ]);
    }

    /**
     * Simpan seluruh permission menu sekaligus.
     *
     * Format:
     *
     * [
     *   menu_id => [
     *      enabled => 1,
     *      can_view => 1,
     *      ...
     *   ]
     * ]
     */
    public function saveAll(
        int $organizationId,
        int $userId,
        int $routerId,
        array $permissions
    ): int {
        if (!$this->validateContext(
            $organizationId,
            $userId,
            $routerId
        )) {
            throw new RuntimeException(
                'Context organization tidak valid.'
            );
        }

        $this->db->beginTransaction();

        try {
            $count = 0;

            foreach ($permissions as $menuId => $permission) {
                $menuId = (int) $menuId;

                if ($menuId <= 0) {
                    continue;
                }

                $this->save(
                    $organizationId,
                    $userId,
                    $routerId,
                    $menuId,
                    is_array($permission)
                        ? $permission
                        : []
                );

                $count++;
            }

            $this->db->commit();

            return $count;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw $e;
        }
    }
}
