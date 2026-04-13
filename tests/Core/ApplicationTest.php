<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\Application;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Application singleton.
 */
class ApplicationTest extends TestCase
{
    protected function setUp(): void
    {
        Application::reset();
    }

    protected function tearDown(): void
    {
        Application::reset();
    }

    public function testInitCreatesInstance(): void
    {
        Application::init(TEST_CONFIG);
        $app = Application::getInstance();

        $this->assertInstanceOf(Application::class, $app);
    }

    public function testGetInstanceThrowsWhenNotInitialised(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Application not initialised');

        Application::getInstance();
    }

    public function testInitOnlyCreatesOneInstance(): void
    {
        Application::init(TEST_CONFIG);
        $first = Application::getInstance();

        Application::init(['app' => ['name' => 'Different']]);
        $second = Application::getInstance();

        $this->assertSame($first, $second);
        $this->assertSame('ScoutKeeper Test', $second->getConfig()['app']['name']);
    }

    public function testGetConfigReturnsFullConfig(): void
    {
        Application::init(TEST_CONFIG);
        $app = Application::getInstance();

        $config = $app->getConfig();
        $this->assertArrayHasKey('db', $config);
        $this->assertArrayHasKey('app', $config);
        $this->assertArrayHasKey('smtp', $config);
    }

    public function testGetConfigValueWithDotNotation(): void
    {
        Application::init(TEST_CONFIG);
        $app = Application::getInstance();

        $this->assertSame('ScoutKeeper Test', $app->getConfigValue('app.name'));
        $this->assertSame('en', $app->getConfigValue('app.language'));
        $this->assertTrue($app->getConfigValue('app.debug'));
    }

    public function testGetConfigValueReturnsDefaultForMissingKey(): void
    {
        Application::init(TEST_CONFIG);
        $app = Application::getInstance();

        $this->assertNull($app->getConfigValue('nonexistent'));
        $this->assertSame('fallback', $app->getConfigValue('nonexistent.key', 'fallback'));
    }

    public function testResetClearsInstance(): void
    {
        Application::init(TEST_CONFIG);
        Application::reset();

        $this->expectException(\RuntimeException::class);
        Application::getInstance();
    }
}
