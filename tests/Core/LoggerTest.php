<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Logger;
use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    private string $logDir;

    protected function setUp(): void
    {
        $this->logDir = ROOT_PATH . '/var/logs';
        if (!is_dir($this->logDir)) {
            mkdir($this->logDir, 0755, true);
        }
        // Clean up any existing log files
        $this->cleanLogs();
    }

    protected function tearDown(): void
    {
        $this->cleanLogs();
    }

    public function testErrorWritesToErrorsJson(): void
    {
        Logger::error('Test error', ['detail' => 'test']);

        $file = $this->logDir . '/errors.json';
        $this->assertFileExists($file);

        $entries = json_decode(file_get_contents($file), true);
        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        $last = end($entries);
        $this->assertSame('error', $last['level']);
        $this->assertSame('Test error', $last['message']);
        $this->assertSame('test', $last['context']['detail']);
    }

    public function testWarningWritesToErrorsJson(): void
    {
        Logger::warning('Test warning');

        $file = $this->logDir . '/errors.json';
        $this->assertFileExists($file);

        $entries = json_decode(file_get_contents($file), true);
        $last = end($entries);
        $this->assertSame('warning', $last['level']);
    }

    public function testLogEntryHasTimestamp(): void
    {
        Logger::error('Timestamped');

        $entries = json_decode(file_get_contents($this->logDir . '/errors.json'), true);
        $last = end($entries);
        $this->assertArrayHasKey('timestamp', $last);
        $this->assertNotEmpty($last['timestamp']);
    }

    public function testMultipleEntriesAppend(): void
    {
        Logger::error('First');
        Logger::error('Second');
        Logger::error('Third');

        $entries = json_decode(file_get_contents($this->logDir . '/errors.json'), true);
        $this->assertCount(3, $entries);
    }

    private function cleanLogs(): void
    {
        foreach (glob($this->logDir . '/*.json') as $file) {
            unlink($file);
        }
    }
}
