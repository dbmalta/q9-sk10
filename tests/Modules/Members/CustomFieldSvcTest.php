<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use PHPUnit\Framework\TestCase;
use App\Core\Database;
use App\Modules\Members\Services\CustomFieldService;

/**
 * Tests for CustomFieldService.
 *
 * Covers CRUD, per-type validation, required fields, dropdown options,
 * number min/max, reordering, activation/deactivation, and rendering.
 */
class CustomFieldSvcTest extends TestCase
{
    private Database $db;
    private CustomFieldService $service;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        // Drop in dependency order
        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        $this->db->query("DROP TABLE IF EXISTS `medical_access_log`");
        $this->db->query("DROP TABLE IF EXISTS `member_pending_changes`");
        $this->db->query("DROP TABLE IF EXISTS `member_nodes`");
        $this->db->query("DROP TABLE IF EXISTS `members`");
        $this->db->query("DROP TABLE IF EXISTS `custom_field_definitions`");
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        // Create the table
        $this->db->query("
            CREATE TABLE `custom_field_definitions` (
                `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `field_key` VARCHAR(100) NOT NULL,
                `field_type` ENUM('short_text', 'long_text', 'number', 'dropdown', 'date') NOT NULL,
                `label` VARCHAR(255) NOT NULL,
                `description` TEXT NULL,
                `is_required` TINYINT(1) NOT NULL DEFAULT 0,
                `validation_rules` JSON NULL,
                `display_group` VARCHAR(50) NOT NULL DEFAULT 'additional',
                `sort_order` INT NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_field_key` (`field_key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $this->service = new CustomFieldService($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query("DROP TABLE IF EXISTS `custom_field_definitions`");
        }
    }

    // ── CRUD Tests ────────────────────────────────────────────────────

    public function testCreateReturnsId(): void
    {
        $id = $this->service->create([
            'field_key' => 'uniform_size',
            'field_type' => 'dropdown',
            'label' => 'Uniform Size',
            'description' => 'Scout uniform size',
            'validation_rules' => ['dropdown_options' => ['S', 'M', 'L', 'XL']],
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
    }

    public function testCreateStoresAllFields(): void
    {
        $id = $this->service->create([
            'field_key' => 'test_field',
            'field_type' => 'short_text',
            'label' => 'Test Field',
            'description' => 'A test field',
            'is_required' => true,
        ]);

        $def = $this->service->getById($id);
        $this->assertSame('test_field', $def['field_key']);
        $this->assertSame('short_text', $def['field_type']);
        $this->assertSame('Test Field', $def['label']);
        $this->assertSame('A test field', $def['description']);
        $this->assertEquals(1, $def['is_required']);
        $this->assertEquals(1, $def['is_active']);
        $this->assertSame('additional', $def['display_group']);
    }

    public function testCreateRequiresFieldKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'field_type' => 'short_text',
            'label' => 'No Key',
        ]);
    }

    public function testCreateRequiresLabel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'field_key' => 'no_label',
            'field_type' => 'short_text',
        ]);
    }

    public function testCreateRejectsInvalidFieldType(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'field_key' => 'bad_type',
            'field_type' => 'invalid',
            'label' => 'Bad Type',
        ]);
    }

    public function testCreateRejectsInvalidFieldKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'field_key' => 'UPPERCASE',
            'field_type' => 'short_text',
            'label' => 'Bad Key',
        ]);
    }

    public function testCreateRejectsDuplicateKey(): void
    {
        $this->service->create([
            'field_key' => 'dup_key',
            'field_type' => 'short_text',
            'label' => 'First',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->create([
            'field_key' => 'dup_key',
            'field_type' => 'number',
            'label' => 'Duplicate',
        ]);
    }

    public function testGetByKey(): void
    {
        $this->service->create([
            'field_key' => 'lookup_test',
            'field_type' => 'date',
            'label' => 'Lookup Test',
        ]);

        $def = $this->service->getByKey('lookup_test');
        $this->assertNotNull($def);
        $this->assertSame('date', $def['field_type']);

        $this->assertNull($this->service->getByKey('nonexistent'));
    }

    public function testUpdate(): void
    {
        $id = $this->service->create([
            'field_key' => 'update_me',
            'field_type' => 'short_text',
            'label' => 'Original',
        ]);

        $this->service->update($id, [
            'label' => 'Updated Label',
            'description' => 'Now with description',
            'is_required' => true,
        ]);

        $def = $this->service->getById($id);
        $this->assertSame('Updated Label', $def['label']);
        $this->assertSame('Now with description', $def['description']);
        $this->assertEquals(1, $def['is_required']);
    }

    public function testUpdateRejectsNonexistent(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->update(99999, ['label' => 'Nope']);
    }

    public function testUpdateRejectsDuplicateKey(): void
    {
        $this->service->create([
            'field_key' => 'key_a',
            'field_type' => 'short_text',
            'label' => 'A',
        ]);
        $idB = $this->service->create([
            'field_key' => 'key_b',
            'field_type' => 'short_text',
            'label' => 'B',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->update($idB, ['field_key' => 'key_a']);
    }

    // ── Activation Tests ──────────────────────────────────────────────

    public function testDeactivateAndActivate(): void
    {
        $id = $this->service->create([
            'field_key' => 'toggle_me',
            'field_type' => 'short_text',
            'label' => 'Toggle',
        ]);

        $this->service->deactivate($id);
        $def = $this->service->getById($id);
        $this->assertEquals(0, $def['is_active']);

        $this->service->activate($id);
        $def = $this->service->getById($id);
        $this->assertEquals(1, $def['is_active']);
    }

    public function testGetDefinitionsFiltersActive(): void
    {
        $id1 = $this->service->create([
            'field_key' => 'active_one',
            'field_type' => 'short_text',
            'label' => 'Active',
        ]);
        $id2 = $this->service->create([
            'field_key' => 'inactive_one',
            'field_type' => 'short_text',
            'label' => 'Inactive',
        ]);
        $this->service->deactivate($id2);

        // Active only (default)
        $active = $this->service->getDefinitions(true);
        $this->assertCount(1, $active);
        $this->assertSame('active_one', $active[0]['field_key']);

        // All
        $all = $this->service->getDefinitions(null);
        $this->assertCount(2, $all);
    }

    // ── Reorder Tests ─────────────────────────────────────────────────

    public function testReorder(): void
    {
        $id1 = $this->service->create([
            'field_key' => 'first',
            'field_type' => 'short_text',
            'label' => 'First',
        ]);
        $id2 = $this->service->create([
            'field_key' => 'second',
            'field_type' => 'short_text',
            'label' => 'Second',
        ]);
        $id3 = $this->service->create([
            'field_key' => 'third',
            'field_type' => 'short_text',
            'label' => 'Third',
        ]);

        // Reverse order
        $this->service->reorder([$id3, $id1, $id2]);

        $defs = $this->service->getDefinitions(true);
        $this->assertSame('third', $defs[0]['field_key']);
        $this->assertSame('first', $defs[1]['field_key']);
        $this->assertSame('second', $defs[2]['field_key']);
    }

    // ── Sort Order Auto-Increment ─────────────────────────────────────

    public function testAutoSortOrder(): void
    {
        $id1 = $this->service->create([
            'field_key' => 'auto_a',
            'field_type' => 'short_text',
            'label' => 'A',
        ]);
        $id2 = $this->service->create([
            'field_key' => 'auto_b',
            'field_type' => 'short_text',
            'label' => 'B',
        ]);

        $def1 = $this->service->getById($id1);
        $def2 = $this->service->getById($id2);

        $this->assertGreaterThan(0, (int) $def1['sort_order']);
        $this->assertGreaterThan((int) $def1['sort_order'], (int) $def2['sort_order']);
    }

    // ── Validation Tests ──────────────────────────────────────────────

    public function testValidateRequiredField(): void
    {
        $def = [
            'field_key' => 'req',
            'field_type' => 'short_text',
            'label' => 'Required Field',
            'is_required' => 1,
            'validation_rules' => null,
        ];

        $err = $this->service->validateFieldValue($def, '');
        $this->assertNotNull($err);

        $err = $this->service->validateFieldValue($def, null);
        $this->assertNotNull($err);

        $err = $this->service->validateFieldValue($def, 'valid');
        $this->assertNull($err);
    }

    public function testValidateOptionalFieldAllowsEmpty(): void
    {
        $def = [
            'field_key' => 'opt',
            'field_type' => 'short_text',
            'label' => 'Optional',
            'is_required' => 0,
            'validation_rules' => null,
        ];

        $this->assertNull($this->service->validateFieldValue($def, ''));
        $this->assertNull($this->service->validateFieldValue($def, null));
    }

    public function testValidateShortTextMaxLength(): void
    {
        $def = [
            'field_key' => 'short',
            'field_type' => 'short_text',
            'label' => 'Short',
            'is_required' => 0,
            'validation_rules' => json_encode(['max_length' => 5]),
        ];

        $this->assertNull($this->service->validateFieldValue($def, 'abcde'));
        $this->assertNotNull($this->service->validateFieldValue($def, 'abcdef'));
    }

    public function testValidateNumber(): void
    {
        $def = [
            'field_key' => 'num',
            'field_type' => 'number',
            'label' => 'Number',
            'is_required' => 0,
            'validation_rules' => json_encode(['min' => 1, 'max' => 100]),
        ];

        $this->assertNull($this->service->validateFieldValue($def, '50'));
        $this->assertNull($this->service->validateFieldValue($def, '1'));
        $this->assertNull($this->service->validateFieldValue($def, '100'));
        $this->assertNotNull($this->service->validateFieldValue($def, '0'));
        $this->assertNotNull($this->service->validateFieldValue($def, '101'));
        $this->assertNotNull($this->service->validateFieldValue($def, 'abc'));
    }

    public function testValidateDropdown(): void
    {
        $def = [
            'field_key' => 'dd',
            'field_type' => 'dropdown',
            'label' => 'Dropdown',
            'is_required' => 0,
            'validation_rules' => json_encode(['dropdown_options' => ['S', 'M', 'L', 'XL']]),
        ];

        $this->assertNull($this->service->validateFieldValue($def, 'M'));
        $this->assertNull($this->service->validateFieldValue($def, 'XL'));
        $this->assertNotNull($this->service->validateFieldValue($def, 'XXL'));
        $this->assertNull($this->service->validateFieldValue($def, '')); // optional
    }

    public function testValidateDate(): void
    {
        $def = [
            'field_key' => 'dt',
            'field_type' => 'date',
            'label' => 'Date',
            'is_required' => 0,
            'validation_rules' => null,
        ];

        $this->assertNull($this->service->validateFieldValue($def, '2026-04-12'));
        $this->assertNotNull($this->service->validateFieldValue($def, '12/04/2026'));
        $this->assertNotNull($this->service->validateFieldValue($def, '2026-02-30')); // invalid date
    }

    public function testValidateAllCustomData(): void
    {
        $this->service->create([
            'field_key' => 'vf_required',
            'field_type' => 'short_text',
            'label' => 'Required',
            'is_required' => true,
        ]);
        $this->service->create([
            'field_key' => 'vf_optional',
            'field_type' => 'number',
            'label' => 'Optional',
            'validation_rules' => ['min' => 0, 'max' => 10],
        ]);

        // Missing required field
        $errors = $this->service->validateAllCustomData([]);
        $this->assertArrayHasKey('vf_required', $errors);
        $this->assertArrayNotHasKey('vf_optional', $errors);

        // Valid data
        $errors = $this->service->validateAllCustomData([
            'vf_required' => 'hello',
            'vf_optional' => '5',
        ]);
        $this->assertEmpty($errors);

        // Invalid optional value
        $errors = $this->service->validateAllCustomData([
            'vf_required' => 'hello',
            'vf_optional' => '20',
        ]);
        $this->assertArrayHasKey('vf_optional', $errors);
    }

    // ── Sanitise Tests ────────────────────────────────────────────────

    public function testSanitiseCustomData(): void
    {
        $this->service->create([
            'field_key' => 'known_field',
            'field_type' => 'short_text',
            'label' => 'Known',
        ]);

        $cleaned = $this->service->sanitiseCustomData([
            'known_field' => 'value',
            'unknown_field' => 'should be stripped',
            'another_unknown' => 'also stripped',
        ]);

        $this->assertSame(['known_field' => 'value'], $cleaned);
    }

    public function testSanitiseConvertsEmptyToNull(): void
    {
        $this->service->create([
            'field_key' => 'empty_test',
            'field_type' => 'short_text',
            'label' => 'Empty',
        ]);

        $cleaned = $this->service->sanitiseCustomData([
            'empty_test' => '',
        ]);

        $this->assertArrayHasKey('empty_test', $cleaned);
        $this->assertNull($cleaned['empty_test']);
    }

    // ── Rendering Tests ───────────────────────────────────────────────

    public function testRenderField(): void
    {
        $def = [
            'id' => 1,
            'field_key' => 'size',
            'field_type' => 'dropdown',
            'label' => 'Size',
            'description' => 'Select size',
            'is_required' => 1,
            'validation_rules' => json_encode(['dropdown_options' => ['S', 'M', 'L']]),
        ];

        $result = $this->service->renderField($def, 'M');

        $this->assertSame('size', $result['key']);
        $this->assertSame('dropdown', $result['type']);
        $this->assertSame('Size', $result['label']);
        $this->assertSame('Select size', $result['description']);
        $this->assertTrue($result['required']);
        $this->assertSame('M', $result['value']);
        $this->assertSame(['S', 'M', 'L'], $result['options']);
    }

    public function testGetRenderableFields(): void
    {
        $this->service->create([
            'field_key' => 'render_a',
            'field_type' => 'short_text',
            'label' => 'Field A',
        ]);
        $this->service->create([
            'field_key' => 'render_b',
            'field_type' => 'dropdown',
            'label' => 'Field B',
            'validation_rules' => ['dropdown_options' => ['X', 'Y']],
        ]);

        $fields = $this->service->getRenderableFields(['render_a' => 'hello', 'render_b' => 'X']);

        $this->assertCount(2, $fields);
        $this->assertSame('render_a', $fields[0]['key']);
        $this->assertSame('hello', $fields[0]['value']);
        $this->assertSame('render_b', $fields[1]['key']);
        $this->assertSame('X', $fields[1]['value']);
        $this->assertSame(['X', 'Y'], $fields[1]['options']);
    }

    // ── Group Filter Tests ────────────────────────────────────────────

    public function testGetFieldsForGroup(): void
    {
        $this->service->create([
            'field_key' => 'grp_additional',
            'field_type' => 'short_text',
            'label' => 'Additional',
            'display_group' => 'additional',
        ]);
        $this->service->create([
            'field_key' => 'grp_custom',
            'field_type' => 'short_text',
            'label' => 'Custom Group',
            'display_group' => 'custom_section',
        ]);

        $additional = $this->service->getFieldsForGroup('additional');
        $this->assertCount(1, $additional);
        $this->assertSame('grp_additional', $additional[0]['field_key']);

        $custom = $this->service->getFieldsForGroup('custom_section');
        $this->assertCount(1, $custom);
    }

    // ── Validation Rules JSON Storage ─────────────────────────────────

    public function testValidationRulesStoredAsJson(): void
    {
        $id = $this->service->create([
            'field_key' => 'json_rules',
            'field_type' => 'dropdown',
            'label' => 'With Rules',
            'validation_rules' => ['dropdown_options' => ['A', 'B', 'C']],
        ]);

        $def = $this->service->getById($id);
        $rules = json_decode($def['validation_rules'], true);
        $this->assertIsArray($rules);
        $this->assertSame(['A', 'B', 'C'], $rules['dropdown_options']);
    }

    public function testUpdateValidationRules(): void
    {
        $id = $this->service->create([
            'field_key' => 'update_rules',
            'field_type' => 'number',
            'label' => 'Updatable',
            'validation_rules' => ['min' => 0, 'max' => 10],
        ]);

        $this->service->update($id, [
            'validation_rules' => ['min' => 5, 'max' => 50],
        ]);

        $def = $this->service->getById($id);
        $rules = json_decode($def['validation_rules'], true);
        $this->assertEquals(5, $rules['min']);
        $this->assertEquals(50, $rules['max']);
    }
}
