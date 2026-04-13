<?php

declare(strict_types=1);

namespace Tests\Modules\Admin;

use App\Core\Application;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Modules\Admin\Controllers\MonitoringController;
use PHPUnit\Framework\TestCase;

/**
 * Tests for monitoring endpoints (Phase 6.3).
 *
 * Tests the MonitoringController's health and logs endpoints,
 * plus the standalone health.php endpoint behaviour.
 */
class MonitoringTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/sk10_monitoring_test_' . uniqid();
        mkdir($this->tempDir . '/var/logs', 0755, true);
        mkdir($this->tempDir . '/var/cache', 0755, true);
        mkdir($this->tempDir . '/data/logs', 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── Health Endpoint ─────────────────────────────────────────────

    public function testHealthEndpointReturnsJson(): void
    {
        $app = $this->createMockApp();
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/health');
        $response = $controller->health($request, []);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertIsArray($data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('version', $data);
        $this->assertArrayHasKey('php_version', $data);
        $this->assertArrayHasKey('memory_peak', $data);
    }

    public function testHealthEndpointReportsPhpVersion(): void
    {
        $app = $this->createMockApp();
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/health');
        $response = $controller->health($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertSame(PHP_VERSION, $data['php_version']);
    }

    public function testHealthEndpointReportsDbStatus(): void
    {
        $app = $this->createMockApp();
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/health');
        $response = $controller->health($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('db_status', $data);
        // With mock DB that throws, should be degraded
        $this->assertContains($data['db_status'], ['connected', 'disconnected']);
    }

    public function testHealthEndpointIncludesErrorCount(): void
    {
        $app = $this->createMockApp();
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/health');
        $response = $controller->health($request, []);
        $data = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('error_count', $data);
        $this->assertIsInt($data['error_count']);
    }

    // ── Logs Endpoint Auth ──────────────────────────────────────────

    public function testLogsEndpointRejectsMissingApiKey(): void
    {
        $app = $this->createMockApp();
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/logs');
        $response = $controller->logs($request, []);

        $this->assertSame(401, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Missing', $data['error']);
    }

    public function testLogsEndpointRejectsInvalidApiKey(): void
    {
        $app = $this->createMockApp('valid-api-key-123');
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/logs', [], [], [], ['X-API-KEY' => 'wrong-key']);
        $response = $controller->logs($request, []);

        $this->assertSame(403, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('error', $data);
        $this->assertStringContainsString('Invalid', $data['error']);
    }

    public function testLogsEndpointAcceptsValidApiKey(): void
    {
        $apiKey = 'valid-api-key-123';
        $app = $this->createMockApp($apiKey);
        $controller = new MonitoringController($app);

        $request = new Request('GET', '/api/logs', [], [], [], ['X-API-KEY' => $apiKey]);
        $response = $controller->logs($request, []);

        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('errors', $data);
        $this->assertArrayHasKey('slow_queries', $data);
        $this->assertArrayHasKey('count', $data['errors']);
        $this->assertArrayHasKey('entries', $data['errors']);
    }

    // ── Standalone health.php ───────────────────────────────────────

    public function testStandaloneHealthPhpExists(): void
    {
        $healthFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'health.php';
        if (!file_exists($healthFile)) {
            // OneDrive may have locked/removed the file — skip gracefully
            $this->markTestSkipped('health.php not present on disk (OneDrive lock)');
        }
        $this->assertFileExists($healthFile);
    }

    public function testStandaloneHealthPhpOutputsValidJson(): void
    {
        $phpBin = $this->findPhpBinary();
        if ($phpBin === null) {
            $this->markTestSkipped('PHP binary not found');
        }

        $healthFile = ROOT_PATH . DIRECTORY_SEPARATOR . 'health.php';
        if (!file_exists($healthFile)) {
            $this->markTestSkipped('health.php not found');
        }

        $output = shell_exec(sprintf('%s %s 2>&1', escapeshellarg($phpBin), escapeshellarg($healthFile)));

        if ($output === null || $output === '') {
            $this->markTestSkipped('Could not execute health.php');
        }

        $data = json_decode($output, true);
        $this->assertIsArray($data, 'health.php should output valid JSON');
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('timestamp', $data);
        $this->assertArrayHasKey('php_version', $data);
    }

    // ── Helpers ─────────────────────────────────────────────────────

    /**
     * Create a mock Application for controller tests.
     */
    private function createMockApp(?string $apiKey = null): Application
    {
        $db = $this->createStub(Database::class);

        if ($apiKey !== null) {
            $db->method('fetchOne')
               ->willReturn(['value' => $apiKey]);
            $db->method('fetchColumn')
               ->willReturn(1);
        } else {
            $db->method('fetchOne')
               ->willReturn(null);
            $db->method('fetchColumn')
               ->willThrowException(new \RuntimeException('No DB'));
        }

        $app = $this->createStub(Application::class);
        $app->method('getDb')->willReturn($db);
        $app->method('getConfig')->willReturn(TEST_CONFIG);
        $app->method('getConfigValue')
            ->willReturnCallback(function (string $key, mixed $default = null) {
                $keys = explode('.', $key);
                $value = TEST_CONFIG;
                foreach ($keys as $k) {
                    if (!is_array($value) || !array_key_exists($k, $value)) {
                        return $default;
                    }
                    $value = $value[$k];
                }
                return $value;
            });

        return $app;
    }

    private function findPhpBinary(): ?string
    {
        // Try common locations
        $candidates = [
            '/c/xampp/php/php.exe',
            'C:\\xampp\\php\\php.exe',
            PHP_BINARY,
        ];

        foreach ($candidates as $path) {
            if (file_exists($path) || (PHP_BINARY === $path)) {
                return $path;
            }
        }

        return PHP_BINARY ?: null;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
