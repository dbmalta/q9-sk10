<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\I18n;
use PHPUnit\Framework\TestCase;

class I18nTest extends TestCase
{
    private string $langPath;

    protected function setUp(): void
    {
        $this->langPath = ROOT_PATH . '/lang';
    }

    public function testTranslateReturnsValue(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $this->assertSame('Log In', $i18n->t('auth.login'));
    }

    public function testTranslateReturnsKeyForMissingTranslation(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $this->assertSame('nonexistent.key', $i18n->t('nonexistent.key'));
    }

    public function testPlaceholderReplacement(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $result = $i18n->t('common.showing', ['from' => '1', 'to' => '10', 'total' => '50']);
        $this->assertSame('Showing 1 to 10 of 50', $result);
    }

    public function testGetMasterStringsReturnsAllKeys(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $master = $i18n->getMasterStrings();

        $this->assertIsArray($master);
        $this->assertNotEmpty($master);
        $this->assertArrayHasKey('auth.login', $master);
        $this->assertArrayHasKey('common.save', $master);
    }

    public function testExportMasterFileReturnsJson(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $json = $i18n->exportMasterFile();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('auth.login', $decoded);
    }

    public function testGetLanguage(): void
    {
        $i18n = new I18n($this->langPath, null, 'fr');
        $this->assertSame('fr', $i18n->getLanguage());
    }

    public function testSetLanguageReloads(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $i18n->t('auth.login'); // Load translations

        $i18n->setLanguage('fr');
        $this->assertSame('fr', $i18n->getLanguage());
    }

    public function testCompletionPercentageForEnglish(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $this->assertSame(100, $i18n->getCompletionPercentage('en'));
    }

    public function testCompletionPercentageForMissingLanguage(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $this->assertSame(0, $i18n->getCompletionPercentage('zz'));
    }

    public function testGetMissingForMissingLanguage(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $missing = $i18n->getMissing('zz');

        $master = $i18n->getMasterStrings();
        $this->assertSame(count($master), count($missing));
    }

    public function testGetAvailableLanguagesIncludesEnglish(): void
    {
        $i18n = new I18n($this->langPath, null, 'en');
        $languages = $i18n->getAvailableLanguages();

        $codes = array_column($languages, 'code');
        $this->assertContains('en', $codes);
    }

    public function testFallbackToEnglishForUnknownLanguage(): void
    {
        $i18n = new I18n($this->langPath, null, 'xx');
        // Should fall back to English master strings
        $this->assertSame('Log In', $i18n->t('auth.login'));
    }
}
