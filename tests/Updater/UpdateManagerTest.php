<?php

declare(strict_types=1);

namespace Tests\Updater;

use PHPUnit\Framework\TestCase;
use Updater\UpdateManager;

/**
 * Tests for the Auto-Update Manager (Phase 6.2).
 *
 * Uses a temporary directory to simulate the project root for
 * filesystem-based operations (maintenance mode, state, tokens).
 */
class UpdateManagerTest extends TestCase
{
    private string $tempDir;
    private UpdateManager $updater;

    protected function setUp(): void
    {
        // Load the UpdateManager class (it's not namespaced, lives in /updater/)
        require_once ROOT_PATH . '/updater/UpdateManager.php';

        $this->tempDir = sys_get_temp_dir() . '/sk10_updater_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        mkdir($this->tempDir . '/var/updates', 0755, true);
        mkdir($this->tempDir . '/var/logs', 0755, true);
        mkdir($this->tempDir . '/config', 0755, true);

        $this->updater = new UpdateManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    // ── Maintenance Mode ────────────────────────────────────────────

    public function testSetMaintenanceModeCreatesFlag(): void
    {
        $flagFile = $this->tempDir . '/var/maintenance.flag';
        $this->assertFileDoesNotExist($flagFile);

        $this->updater->setMaintenanceMode(true);

        $this->assertFileExists($flagFile);
        $this->assertNotEmpty(file_get_contents($flagFile));
    }

    public function testSetMaintenanceModeRemovesFlag(): void
    {
        $flagFile = $this->tempDir . '/var/maintenance.flag';
        file_put_contents($flagFile, date('c'));

        $this->updater->setMaintenanceMode(false);

        $this->assertFileDoesNotExist($flagFile);
    }

    public function testSetMaintenanceModeOffWhenNoFlagExists(): void
    {
        // Should not throw when flag doesn't exist
        $this->updater->setMaintenanceMode(false);
        $this->assertFileDoesNotExist($this->tempDir . '/var/maintenance.flag');
    }

    // ── Update State ────────────────────────────────────────────────

    public function testGetUpdateStateReturnsNullWhenNoState(): void
    {
        $this->assertNull($this->updater->getUpdateState());
    }

    public function testStateIsPersisted(): void
    {
        // Use token generation to write state
        $this->updater->generateUpdateToken();

        $state = $this->updater->getUpdateState();
        $this->assertIsArray($state);
        $this->assertArrayHasKey('update_token', $state);
        $this->assertArrayHasKey('updated_at', $state);
    }

    // ── Update Tokens ───────────────────────────────────────────────

    public function testGenerateUpdateTokenReturnsHexString(): void
    {
        $token = $this->updater->generateUpdateToken();

        $this->assertSame(64, strlen($token)); // 32 bytes = 64 hex chars
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    public function testVerifyUpdateTokenAcceptsValidToken(): void
    {
        $token = $this->updater->generateUpdateToken();

        $this->assertTrue($this->updater->verifyUpdateToken($token));
    }

    public function testVerifyUpdateTokenRejectsInvalidToken(): void
    {
        $this->updater->generateUpdateToken();

        $this->assertFalse($this->updater->verifyUpdateToken('wrong-token'));
    }

    public function testVerifyUpdateTokenRejectsWhenNoState(): void
    {
        $this->assertFalse($this->updater->verifyUpdateToken('any-token'));
    }

    public function testVerifyUpdateTokenRejectsExpiredToken(): void
    {
        $token = $this->updater->generateUpdateToken();

        // Manually expire the token by setting created_at to >1 hour ago
        $stateFile = $this->tempDir . '/var/updates/update_state.json';
        $state = json_decode(file_get_contents($stateFile), true);
        $state['token_created_at'] = time() - 3700; // 1 hour + margin
        file_put_contents($stateFile, json_encode($state));

        $this->assertFalse($this->updater->verifyUpdateToken($token));
    }

    public function testEachTokenIsUnique(): void
    {
        $token1 = $this->updater->generateUpdateToken();
        $token2 = $this->updater->generateUpdateToken();

        $this->assertNotSame($token1, $token2);
    }

    // ── Signature Verification ──────────────────────────────────────

    public function testVerifySignatureReturnsTrueWhenNoPublicKey(): void
    {
        // When no public key file exists, verification is skipped (self-hosted)
        $zipPath = $this->tempDir . '/test.zip';
        $sigPath = $this->tempDir . '/test.zip.sig';
        file_put_contents($zipPath, 'fake zip content');
        file_put_contents($sigPath, 'fake signature');

        $this->assertTrue($this->updater->verifySignature($zipPath, $sigPath));
    }

    public function testVerifySignatureReturnsFalseForMissingFiles(): void
    {
        // Create a dummy public key file so verification is attempted
        file_put_contents($this->tempDir . '/update_public_key.pem', 'dummy');

        $this->assertFalse($this->updater->verifySignature(
            $this->tempDir . '/nonexistent.zip',
            $this->tempDir . '/nonexistent.sig'
        ));
    }

    public function testVerifySignatureReturnsFalseForInvalidKey(): void
    {
        file_put_contents($this->tempDir . '/update_public_key.pem', 'not a real PEM key');
        $zipPath = $this->tempDir . '/test.zip';
        $sigPath = $this->tempDir . '/test.zip.sig';
        file_put_contents($zipPath, 'fake zip content');
        file_put_contents($sigPath, 'fake signature');

        $this->assertFalse($this->updater->verifySignature($zipPath, $sigPath));
    }

    // ── checkForUpdate ──────────────────────────────────────────────

    public function testCheckForUpdateReturnsNullOnNetworkFailure(): void
    {
        // Using a version that is absurdly high ensures no update is found
        // even if the GitHub API happens to respond
        $result = $this->updater->checkForUpdate('999.999.999');

        // Either null (can't reach API) or null (no newer version)
        $this->assertNull($result);
    }

    // ── getCurrentVersion ───────────────────────────────────────────

    public function testGetCurrentVersionReturnsDefaultWhenNoConfig(): void
    {
        $version = $this->updater->getCurrentVersion();

        // No config exists, should return 'unknown'
        $this->assertSame('unknown', $version);
    }

    public function testGetCurrentVersionReadsFromConfig(): void
    {
        $configContent = "<?php\nreturn ['db' => ['host' => 'x', 'name' => 'x', 'user' => 'x', 'password' => 'x'], 'app' => ['version' => '2.5.0']];";
        file_put_contents($this->tempDir . '/config/config.php', $configContent);

        // Pass config directly to avoid DB lookup
        $config = require $this->tempDir . '/config/config.php';
        $version = $this->updater->getCurrentVersion($config);

        // Can't connect to DB, falls back to config
        $this->assertSame('2.5.0', $version);
    }

    // ── downloadRelease ─────────────────────────────────────────────

    public function testDownloadReleaseCreatesDirectoryAndFile(): void
    {
        $targetPath = $this->tempDir . '/downloads/subdir/test.zip';

        // Use a URL that won't resolve — we just test the directory creation
        $result = $this->updater->downloadRelease('http://localhost:1/nonexistent.zip', $targetPath);

        // Should fail (no server) but the directory should have been created
        $this->assertFalse($result);
        $this->assertDirectoryExists(dirname($targetPath));
    }

    // ── applyUpdate ─────────────────────────────────────────────────

    public function testApplyUpdateFailsWithNoConfig(): void
    {
        $result = $this->updater->applyUpdate($this->tempDir . '/fake.zip');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('config', $result['error']);
    }

    // ── rollback ────────────────────────────────────────────────────

    public function testRollbackFailsWithNoState(): void
    {
        $this->assertFalse($this->updater->rollback());
    }

    public function testRollbackFailsWithMissingBackupDir(): void
    {
        // Write state with a non-existent backup path
        $stateFile = $this->tempDir . '/var/updates/update_state.json';
        file_put_contents($stateFile, json_encode([
            'backup_path' => $this->tempDir . '/nonexistent_backup',
        ]));

        $this->assertFalse($this->updater->rollback());
    }

    // ── Helpers ─────────────────────────────────────────────────────

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
