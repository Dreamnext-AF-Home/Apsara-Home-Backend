<?php

namespace App\Support;

use App\Models\Admin;

class AdminAccess
{
    public const PERMISSIONS = [
        'dashboard',
        'members',
        'orders',
        'interior_requests',
        'products',
        'shipping',
        'suppliers',
        'web_content',
        'settings_users',
    ];

    public static function roleFromLevel(int $level): string
    {
        return match ($level) {
            1 => 'super_admin',
            2 => 'admin',
            3 => 'csr',
            4 => 'web_content',
            5 => 'accounting',
            6 => 'finance_officer',
            7 => 'merchant_admin',
            8 => 'supplier_admin',
            default => 'staff',
        };
    }

    public static function availablePermissions(): array
    {
        return self::PERMISSIONS;
    }

    public static function normalizePermissions(mixed $permissions): array
    {
        if (! is_array($permissions)) {
            return [];
        }

        $valid = array_flip(self::PERMISSIONS);

        return array_values(array_unique(array_filter(
            array_map(static fn ($item) => is_string($item) ? trim($item) : '', $permissions),
            static fn ($item) => $item !== '' && isset($valid[$item]),
        )));
    }

    public static function defaultPermissionsForLevel(int $level): array
    {
        return match ($level) {
            1 => self::PERMISSIONS,
            2 => ['dashboard', 'orders', 'interior_requests', 'products', 'shipping', 'web_content', 'settings_users'],
            3 => ['dashboard', 'members', 'orders', 'interior_requests'],
            4 => ['dashboard', 'products', 'web_content'],
            7 => ['dashboard', 'orders', 'interior_requests', 'products', 'shipping'],
            8 => ['dashboard', 'products', 'suppliers'],
            default => ['dashboard'],
        };
    }

    public static function sanitizePermissionsForLevel(int $level, mixed $permissions): array
    {
        if ($level !== 2) {
            return [];
        }

        $normalized = self::normalizePermissions($permissions);

        return array_values(array_unique(array_merge(['dashboard'], $normalized)));
    }

    public static function permissionsForAdmin(Admin $admin): array
    {
        $level = (int) $admin->user_level_id;
        if ($level === 1) {
            return self::PERMISSIONS;
        }

        $custom = self::sanitizePermissionsForLevel($level, $admin->admin_permissions ?? []);
        if ($level === 2 && ! empty($custom)) {
            return $custom;
        }

        return self::defaultPermissionsForLevel($level);
    }

    public static function hasPermission(Admin $admin, string $permission): bool
    {
        return in_array($permission, self::permissionsForAdmin($admin), true);
    }

    public static function permissionForPath(string $path): ?string
    {
        $normalized = '/' . ltrim($path, '/');
        if (str_starts_with($normalized, '/api/')) {
            $normalized = substr($normalized, 4);
            $normalized = $normalized === '' ? '/' : $normalized;
        }

        return match (true) {
            $normalized === '/admin',
            str_starts_with($normalized, '/admin/dashboard') => 'dashboard',
            str_starts_with($normalized, '/admin/members') => 'members',
            str_starts_with($normalized, '/admin/orders') => 'orders',
            str_starts_with($normalized, '/admin/interior-requests') => 'interior_requests',
            str_starts_with($normalized, '/admin/products'),
            str_starts_with($normalized, '/admin/product-brands'),
            str_starts_with($normalized, '/admin/categories') => 'products',
            str_starts_with($normalized, '/admin/shipping') => 'shipping',
            str_starts_with($normalized, '/admin/suppliers') => 'suppliers',
            str_starts_with($normalized, '/admin/web-pages'),
            str_starts_with($normalized, '/admin/webpages') => 'web_content',
            str_starts_with($normalized, '/admin/users'),
            str_starts_with($normalized, '/admin/settings/users') => 'settings_users',
            default => null,
        };
    }
}
