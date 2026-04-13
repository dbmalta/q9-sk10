<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Module registry — discovers and manages application modules.
 *
 * Scans /app/modules/ for module.php files, collects their registrations
 * (routes, navigation, permissions, cron handlers), and exposes them
 * to the rest of the application. Adding a module is as simple as creating
 * a new directory with a module.php file.
 */
class ModuleRegistry
{
    /** @var array<string, array> Registered module definitions keyed by module ID */
    private array $modules = [];

    /** Nav group definitions and their sort order */
    private const NAV_GROUPS = [
        'main' => 1,
        'engagement' => 2,
        'operations' => 3,
        'administration' => 4,
    ];

    /**
     * Scan a directory for module.php files and load their definitions.
     *
     * Each module.php should return an array with:
     *   id, name, version, nav (optional), routes (callable), permissions, cron (optional)
     *
     * @param string $modulesPath Path to the modules directory
     */
    public function loadModules(string $modulesPath): void
    {
        if (!is_dir($modulesPath)) {
            return;
        }

        $dirs = glob($modulesPath . '/*/module.php');
        foreach ($dirs as $moduleFile) {
            try {
                $definition = require $moduleFile;
                if (is_array($definition) && isset($definition['id'])) {
                    $this->modules[$definition['id']] = $definition;
                }
            } catch (\Throwable $e) {
                Logger::error('Failed to load module', [
                    'file' => $moduleFile,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Register all module routes with the router.
     */
    public function registerRoutes(Router $router): void
    {
        foreach ($this->modules as $module) {
            if (isset($module['routes']) && is_callable($module['routes'])) {
                ($module['routes'])($router);
            }
        }
    }

    /**
     * Get navigation items grouped by section, sorted, and filtered by permissions.
     *
     * @param array|null $user The current user data (null if not authenticated)
     * @return array<string, array> Navigation groups with their items
     */
    public function getNavItems(?array $user): array
    {
        $groups = [];

        foreach ($this->modules as $module) {
            if (!isset($module['nav'])) {
                continue;
            }

            // Support single nav item (assoc array) or multiple (indexed array)
            $navItems = $module['nav'];
            if (isset($navItems['label'])) {
                // Single nav item — wrap in array
                $navItems = [$navItems];
            }

            foreach ($navItems as $nav) {
                $group = $nav['group'] ?? 'main';

                // TODO: Check permissions when PermissionResolver is implemented
                // For now, show all nav items to authenticated users
                if ($user === null && ($nav['requires_auth'] ?? true)) {
                    continue;
                }

                $groups[$group][] = [
                    'id' => $module['id'],
                    'label' => $nav['label'] ?? $module['name'],
                    'icon' => $nav['icon'] ?? 'bi-circle',
                    'route' => $nav['route'] ?? '/' . $module['id'],
                    'order' => $nav['order'] ?? 50,
                    'badge' => isset($nav['badge']) && is_callable($nav['badge']) ? ($nav['badge'])() : null,
                ];
            }
        }

        // Sort items within each group
        foreach ($groups as &$items) {
            usort($items, fn($a, $b) => $a['order'] <=> $b['order']);
        }

        // Sort groups by their defined order, filter out empty groups
        $sorted = [];
        foreach (self::NAV_GROUPS as $groupId => $groupOrder) {
            if (!empty($groups[$groupId])) {
                $sorted[$groupId] = $groups[$groupId];
            }
        }

        return $sorted;
    }

    /**
     * Get all permission definitions from all modules.
     *
     * @return array<string, string> Permission key => description
     */
    public function getPermissionDefinitions(): array
    {
        $permissions = [];
        foreach ($this->modules as $module) {
            if (isset($module['permissions']) && is_array($module['permissions'])) {
                $permissions = array_merge($permissions, $module['permissions']);
            }
        }
        return $permissions;
    }

    /**
     * Get all registered cron handlers.
     *
     * @return array<CronHandlerInterface> List of cron handler instances
     */
    public function getCronHandlers(): array
    {
        $handlers = [];
        foreach ($this->modules as $module) {
            if (isset($module['cron']) && is_array($module['cron'])) {
                foreach ($module['cron'] as $handlerClass) {
                    if (class_exists($handlerClass)) {
                        $handlers[] = new $handlerClass();
                    }
                }
            }
        }
        return $handlers;
    }

    /**
     * Get a specific module definition by ID.
     */
    public function getModule(string $id): ?array
    {
        return $this->modules[$id] ?? null;
    }

    /**
     * Get all registered modules.
     */
    public function getAllModules(): array
    {
        return $this->modules;
    }
}
