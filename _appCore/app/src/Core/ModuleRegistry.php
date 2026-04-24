<?php

declare(strict_types=1);

namespace AppCore\Core;

/**
 * Module registry — discovers and manages application modules.
 *
 * Globs /app/modules/*\/module.php, collects each module's registrations
 * (routes, navigation items, permissions, cron handlers) and exposes them
 * to the rest of the application. Adding a module is as simple as creating
 * a new directory with a module.php file.
 */
class ModuleRegistry
{
    /** @var array<string, array> */
    private array $modules = [];

    private const NAV_GROUPS = [
        '_top'   => 1,
        'admin'  => 2,
        'config' => 3,
    ];

    public function loadModules(string $modulesPath): void
    {
        if (!is_dir($modulesPath)) {
            return;
        }

        $dirs = glob($modulesPath . '/*/module.php') ?: [];
        foreach ($dirs as $moduleFile) {
            try {
                $definition = require $moduleFile;
                if (is_array($definition) && isset($definition['id'])) {
                    $this->modules[$definition['id']] = $definition;
                }
            } catch (\Throwable $e) {
                Logger::error('Failed to load module', [
                    'file'  => $moduleFile,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    public function registerRoutes(Router $router): void
    {
        foreach ($this->modules as $module) {
            if (isset($module['routes']) && is_callable($module['routes'])) {
                ($module['routes'])($router);
            }
        }
    }

    /**
     * Get navigation items grouped by section, sorted, and filtered by auth.
     *
     * @return array<string, array>
     */
    public function getNavItems(?array $user): array
    {
        $groups = [];

        foreach ($this->modules as $module) {
            if (!isset($module['nav'])) {
                continue;
            }

            $navItems = $module['nav'];
            if (isset($navItems['label'])) {
                $navItems = [$navItems];
            }

            foreach ($navItems as $nav) {
                $group = $nav['group'] ?? 'admin';

                if ($user === null && ($nav['requires_auth'] ?? true)) {
                    continue;
                }

                $groups[$group][] = [
                    'id'    => $module['id'],
                    'label' => $nav['label'] ?? $module['name'],
                    'icon'  => $nav['icon'] ?? 'bi-circle',
                    'route' => $nav['route'] ?? '/' . $module['id'],
                    'order' => $nav['order'] ?? 50,
                    'badge' => isset($nav['badge']) && is_callable($nav['badge']) ? ($nav['badge'])() : null,
                ];
            }
        }

        foreach ($groups as &$items) {
            usort($items, static fn($a, $b) => $a['order'] <=> $b['order']);
        }
        unset($items);

        $sorted = [];
        foreach (self::NAV_GROUPS as $groupId => $groupOrder) {
            if (!empty($groups[$groupId])) {
                $sorted[$groupId] = $groups[$groupId];
            }
        }
        // Pass through any groups the module declared outside the known set
        foreach ($groups as $groupId => $items) {
            if (!isset($sorted[$groupId])) {
                $sorted[$groupId] = $items;
            }
        }

        return $sorted;
    }

    /**
     * @return array<string, string>
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
     * @return array<CronHandlerInterface>
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

    public function getModule(string $id): ?array
    {
        return $this->modules[$id] ?? null;
    }

    public function getAllModules(): array
    {
        return $this->modules;
    }
}
