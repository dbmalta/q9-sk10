<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\ModuleRegistry;
use App\Core\Router;
use PHPUnit\Framework\TestCase;

class ModuleRegistryTest extends TestCase
{
    private string $modulesPath;

    protected function setUp(): void
    {
        $this->modulesPath = ROOT_PATH . '/tests/fixtures/modules';

        // Create test module directories
        $this->createTestModule('test_module', [
            'id' => 'test_module',
            'name' => 'Test Module',
            'version' => '1.0.0',
            'nav' => [
                'group' => 'members',
                'label' => 'nav.test',
                'icon' => 'bi-test',
                'route' => '/test',
                'order' => 10,
            ],
            'routes' => function (Router $router) {
                $router->get('/test', ['TestController', 'index']);
            },
            'permissions' => [
                'test.read' => 'Read test data',
                'test.write' => 'Write test data',
            ],
            'cron' => [],
        ]);

        $this->createTestModule('admin_module', [
            'id' => 'admin_module',
            'name' => 'Admin Module',
            'version' => '1.0.0',
            'nav' => [
                'group' => 'admin',
                'label' => 'nav.admin_test',
                'icon' => 'bi-gear',
                'route' => '/admin/test',
                'order' => 50,
            ],
            'routes' => function (Router $router) {},
            'permissions' => [
                'admin.manage' => 'Manage admin settings',
            ],
            'cron' => [],
        ]);

        $this->createTestModule('no_nav_module', [
            'id' => 'no_nav_module',
            'name' => 'No Nav Module',
            'version' => '1.0.0',
            'routes' => function (Router $router) {},
            'permissions' => [],
            'cron' => [],
        ]);
    }

    protected function tearDown(): void
    {
        // Clean up test modules
        $this->removeDir($this->modulesPath);
    }

    public function testLoadModulesFindsAllModules(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $this->assertNotNull($registry->getModule('test_module'));
        $this->assertNotNull($registry->getModule('admin_module'));
        $this->assertNotNull($registry->getModule('no_nav_module'));
    }

    public function testGetModuleReturnsNullForUnknown(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $this->assertNull($registry->getModule('nonexistent'));
    }

    public function testGetNavItemsGroupedAndSorted(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $navItems = $registry->getNavItems(['id' => 1]);

        $this->assertArrayHasKey('members', $navItems);
        $this->assertArrayHasKey('admin', $navItems);

        $this->assertArrayNotHasKey('communications', $navItems);
        $this->assertArrayNotHasKey('config', $navItems);
    }

    public function testEmptyGroupsAreHidden(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $navItems = $registry->getNavItems(['id' => 1]);
        $this->assertArrayNotHasKey('communications', $navItems);
        $this->assertArrayNotHasKey('training', $navItems);
        $this->assertArrayNotHasKey('config', $navItems);
    }

    public function testNavItemsHiddenForUnauthenticatedUsers(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $navItems = $registry->getNavItems(null);
        $this->assertEmpty($navItems);
    }

    public function testModuleWithoutNavIsExcludedFromNav(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $navItems = $registry->getNavItems(['id' => 1]);
        $allLabels = [];
        foreach ($navItems as $items) {
            foreach ($items as $item) {
                $allLabels[] = $item['id'];
            }
        }

        $this->assertNotContains('no_nav_module', $allLabels);
    }

    public function testGetPermissionDefinitionsAggregatesAll(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $permissions = $registry->getPermissionDefinitions();

        $this->assertArrayHasKey('test.read', $permissions);
        $this->assertArrayHasKey('test.write', $permissions);
        $this->assertArrayHasKey('admin.manage', $permissions);
    }

    public function testNavWithNoModesDeclaredDefaultsToAdminOnly(): void
    {
        // The two pre-existing modules in setUp() do NOT declare `modes`.
        // They must therefore appear in admin mode and be hidden in member.
        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        $adminNav = $registry->getNavItems(['id' => 1], 'admin');
        $adminFlat = [];
        foreach ($adminNav as $items) { foreach ($items as $i) { $adminFlat[] = $i['id']; } }
        $this->assertContains('test_module', $adminFlat);

        $memberNav = $registry->getNavItems(['id' => 1], 'member');
        $this->assertEmpty($memberNav);
    }

    public function testNavFilteredByMode(): void
    {
        // Extra module declaring member-mode nav only.
        $this->createTestModule('member_only_module', [
            'id' => 'member_only_module',
            'name' => 'Member Only',
            'version' => '1.0.0',
            'nav' => [
                'group' => 'members',
                'label' => 'nav.my_stuff',
                'icon' => 'bi-person',
                'route' => '/me/stuff',
                'order' => 5,
                'modes' => ['member'],
            ],
            'routes' => function (Router $router) {},
            'permissions' => [],
            'cron' => [],
        ]);

        $registry = new ModuleRegistry();
        $registry->loadModules($this->modulesPath);

        // Admin mode: pre-existing items (which default to admin) appear,
        // the member-only item is hidden.
        $adminNav = $registry->getNavItems(['id' => 1], 'admin');
        $adminIds = [];
        foreach ($adminNav as $items) {
            foreach ($items as $item) {
                $adminIds[] = $item['id'];
            }
        }
        $this->assertContains('test_module', $adminIds);
        $this->assertContains('admin_module', $adminIds);
        $this->assertNotContains('member_only_module', $adminIds);

        // Member mode: only the member-only item shows.
        $memberNav = $registry->getNavItems(['id' => 1], 'member');
        $memberIds = [];
        foreach ($memberNav as $items) {
            foreach ($items as $item) {
                $memberIds[] = $item['id'];
            }
        }
        $this->assertSame(['member_only_module'], $memberIds);
    }

    public function testLoadModulesHandlesEmptyDirectory(): void
    {
        $registry = new ModuleRegistry();
        $registry->loadModules('/nonexistent/path');

        $this->assertEmpty($registry->getAllModules());
    }

    // --- Helpers ---

    private function createTestModule(string $name, array $definition): void
    {
        $dir = $this->modulesPath . '/' . $name;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $export = var_export($definition, true);

        // Replace the closure representation — var_export can't handle closures
        // Write actual PHP instead
        $navExport = isset($definition['nav']) ? var_export($definition['nav'], true) : 'null';
        $permExport = var_export($definition['permissions'] ?? [], true);
        $cronExport = var_export($definition['cron'] ?? [], true);

        $php = "<?php\nreturn [\n";
        $php .= "    'id' => " . var_export($definition['id'], true) . ",\n";
        $php .= "    'name' => " . var_export($definition['name'], true) . ",\n";
        $php .= "    'version' => " . var_export($definition['version'], true) . ",\n";

        if (isset($definition['nav'])) {
            $php .= "    'nav' => $navExport,\n";
        }

        $php .= "    'routes' => function(\\App\\Core\\Router \$router) {},\n";
        $php .= "    'permissions' => $permExport,\n";
        $php .= "    'cron' => $cronExport,\n";
        $php .= "];\n";

        file_put_contents($dir . '/module.php', $php);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
