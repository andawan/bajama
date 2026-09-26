<?php

namespace BAJAMA\Network;

use PDO;
use RuntimeException;
use BAJAMA\Core\RBAC;

final class MikroTikPermissionGuard
{
    private $db;
    private $manager;

    public function __construct(
        PDO $db,
        int $organizationId,
        int $userId,
        int $routerId
    ) {
        $this->db = $db;

        $this->manager = new MikroTikMenuManager(
            $db,
            $organizationId,
            $userId,
            $routerId
        );

        if (!$this->manager->validateContext()) {
            throw new RuntimeException(
                'Konteks organization, user, atau router tidak valid.'
            );
        }
    }

    public function manager(): MikroTikMenuManager
    {
        return $this->manager;
    }

    public function can(
        int $menuId,
        string $action = 'view'
    ): bool {
        return $this->manager->can($menuId, $action);
    }

    public function canView(int $menuId): bool
    {
        return $this->manager->canView($menuId);
    }

    /**
     * Cari menu berdasarkan menu_key.
     */
    public function menuId(string $menuKey): ?int
    {
        $menuKey = trim($menuKey);

        if ($menuKey === '') {
            return null;
        }

        foreach ($this->manager->menuTree() as $root) {
            $found = $this->findMenuRecursive(
                $root,
                $menuKey
            );

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * Cek permission berdasarkan menu_key.
     */
    public function allows(
        string $menuKey,
        string $action = 'view'
    ): bool {
        /*
         * SUPER_ADMIN adalah system-level administrator.
         *
         * Sama seperti RBAC.php, SUPER_ADMIN tidak dibatasi
         * oleh matrix permission MikroTik per-user/per-router.
         */
        if (RBAC::hasPermission($this->db, 'mikrotik.view')) {
            $roles = RBAC::roles($this->db);

            if (in_array('SUPER_ADMIN', $roles, true) || in_array('OWNER', $roles, true)) {
                return true;
            }
        }

        $menuId = $this->menuId($menuKey);

        if ($menuId === null) {
            return false;
        }

        return $this->manager->can($menuId, $action);
    }

    /**
     * Guard server-side.
     *
     * Jika tidak memiliki permission:
     * HTTP 403 + hentikan request.
     */
    public function require(
        string $menuKey,
        string $action = 'view'
    ): void {
        if ($this->allows($menuKey, $action)) {
            return;
        }

        http_response_code(403);

        exit(
            '403 Forbidden: Anda tidak memiliki permission '
            . 'untuk menu "' . htmlspecialchars(
                $menuKey,
                ENT_QUOTES,
                'UTF-8'
            )
            . '" dengan action "' . htmlspecialchars(
                $action,
                ENT_QUOTES,
                'UTF-8'
            )
            . '".'
        );
    }

    /**
     * Ambil permission seluruh menu yang boleh dilihat.
     */
    public function visibleMenuTree(): array
    {
        return $this->filterTree(
            $this->manager->menuTree()
        );
    }

    /**
     * Filter tree berdasarkan permission VIEW.
     *
     * Tidak berdasarkan ada/tidaknya data MikroTik.
     * Murni berdasarkan konfigurasi permission.
     */
    private function filterTree(array $nodes): array
    {
        $roles = RBAC::roles($this->db);
        if (in_array('SUPER_ADMIN', $roles, true) || in_array('OWNER', $roles, true)) {
            return $nodes;
        }

        $result = [];

        foreach ($nodes as $node) {
            $children = isset($node['children'])
                && is_array($node['children'])
                ? $node['children']
                : [];

            $filteredChildren = $this->filterTree($children);

            $menuId = (int) ($node['id'] ?? 0);

            $selfVisible = $menuId > 0
                && $this->manager->canView($menuId);

            /*
             * Parent tetap boleh tampil jika:
             * - parent sendiri punya VIEW, atau
             * - minimal ada child yang punya VIEW.
             *
             * Ini penting untuk menu seperti PPP,
             * Hotspot, IP, Firewall, dan sebagainya.
             */
            if ($selfVisible || !empty($filteredChildren)) {
                $node['children'] = $filteredChildren;
                $result[] = $node;
            }
        }

        return $result;
    }

    private function findMenuRecursive(
        array $node,
        string $menuKey
    ): ?int {
        if (
            isset($node['menu_key'])
            && (string) $node['menu_key'] === $menuKey
        ) {
            return (int) $node['id'];
        }

        if (
            isset($node['children'])
            && is_array($node['children'])
        ) {
            foreach ($node['children'] as $child) {
                $found = $this->findMenuRecursive(
                    $child,
                    $menuKey
                );

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
