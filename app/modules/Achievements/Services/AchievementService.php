<?php

declare(strict_types=1);

namespace App\Modules\Achievements\Services;

use App\Core\Database;

/**
 * Achievement and training management service.
 *
 * Handles CRUD for achievement/training definitions and the assignment
 * (award/revoke) of achievements to individual members. Definitions
 * are categorised as either "achievement" or "training" and can be
 * reordered via drag-and-drop in the admin UI.
 */
class AchievementService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    // ──── Definition CRUD ────

    /**
     * Create an achievement or training definition.
     *
     * @param array $data Must include 'name' and 'category'; may include
     *                     'description', 'is_active', 'sort_order'.
     * @return int The new definition ID
     * @throws \InvalidArgumentException if required fields are missing or invalid
     */
    public function createDefinition(array $data): int
    {
        $this->validateDefinition($data);

        return $this->db->insert('achievement_definitions', [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'category' => $data['category'],
            'is_active' => (int) ($data['is_active'] ?? 1),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
        ]);
    }

    /**
     * Update an existing definition.
     *
     * @param int   $id   Definition ID
     * @param array $data Fields to update (name, description, category, is_active, sort_order)
     * @throws \InvalidArgumentException if category is provided but invalid
     */
    public function updateDefinition(int $id, array $data): void
    {
        $allowed = ['name', 'description', 'category', 'is_active', 'sort_order'];
        $update = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];

                if ($field === 'name') {
                    $value = trim((string) $value);
                    if ($value === '') {
                        throw new \InvalidArgumentException('Name cannot be empty');
                    }
                }

                if ($field === 'category' && !in_array($value, ['achievement', 'training'], true)) {
                    throw new \InvalidArgumentException('Category must be "achievement" or "training"');
                }

                if ($field === 'is_active' || $field === 'sort_order') {
                    $value = (int) $value;
                }

                $update[$field] = $value;
            }
        }

        if (!empty($update)) {
            $this->db->update('achievement_definitions', $update, ['id' => $id]);
        }
    }

    /**
     * Deactivate a definition (soft-delete).
     *
     * Existing member awards are preserved but the definition will no
     * longer appear when listing active definitions for new awards.
     *
     * @param int $id Definition ID
     */
    public function deactivateDefinition(int $id): void
    {
        $this->db->update('achievement_definitions', ['is_active' => 0], ['id' => $id]);
    }

    /**
     * Re-activate a previously deactivated definition.
     *
     * @param int $id Definition ID
     */
    public function activateDefinition(int $id): void
    {
        $this->db->update('achievement_definitions', ['is_active' => 1], ['id' => $id]);
    }

    /**
     * List achievement/training definitions.
     *
     * @param string|null $category Filter by 'achievement' or 'training', or null for all
     * @param bool        $activeOnly Only return active definitions (default true)
     * @return array List of definitions ordered by sort_order, then name
     */
    public function getDefinitions(?string $category = null, bool $activeOnly = true): array
    {
        $conditions = [];
        $params = [];

        if ($category !== null) {
            $conditions[] = "category = :category";
            $params['category'] = $category;
        }

        if ($activeOnly) {
            $conditions[] = "is_active = 1";
        }

        $where = !empty($conditions) ? 'WHERE ' . implode(' AND ', $conditions) : '';

        return $this->db->fetchAll(
            "SELECT * FROM achievement_definitions $where ORDER BY sort_order ASC, name ASC",
            $params
        );
    }

    /**
     * Get a single definition by ID.
     *
     * @param int $id Definition ID
     * @return array|null Definition data or null if not found
     */
    public function getDefinitionById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT * FROM achievement_definitions WHERE id = :id",
            ['id' => $id]
        );
    }

    // ──── Member awards ────

    /**
     * Award an achievement or training record to a member.
     *
     * @param int         $memberId      Member ID
     * @param int         $achievementId Achievement definition ID
     * @param string      $date          Award date (Y-m-d)
     * @param int         $awardedBy     User ID of the person making the award
     * @param string|null $notes         Optional notes
     * @return int The new member_achievements record ID
     */
    public function awardToMember(int $memberId, int $achievementId, string $date, int $awardedBy, ?string $notes = null): int
    {
        return $this->db->insert('member_achievements', [
            'member_id' => $memberId,
            'achievement_id' => $achievementId,
            'awarded_date' => $date,
            'awarded_by' => $awardedBy,
            'notes' => $notes,
        ]);
    }

    /**
     * Revoke (delete) a member achievement record.
     *
     * @param int $id The member_achievements record ID
     */
    public function revokeFromMember(int $id): void
    {
        $this->db->delete('member_achievements', ['id' => $id]);
    }

    /**
     * Get all achievements for a member, joined with definition details.
     *
     * @param int         $memberId Member ID
     * @param string|null $category Filter by category, or null for all
     * @return array List of awards with definition name, category, date, notes
     */
    public function getMemberAchievements(int $memberId, ?string $category = null): array
    {
        $sql = "SELECT ma.id, ma.member_id, ma.achievement_id, ma.awarded_date,
                       ma.awarded_by, ma.notes, ma.created_at,
                       ad.name AS achievement_name, ad.category, ad.description AS achievement_description
                FROM member_achievements ma
                JOIN achievement_definitions ad ON ad.id = ma.achievement_id
                WHERE ma.member_id = :member_id";
        $params = ['member_id' => $memberId];

        if ($category !== null) {
            $sql .= " AND ad.category = :category";
            $params['category'] = $category;
        }

        $sql .= " ORDER BY ma.awarded_date DESC, ad.name ASC";

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * Get all members who hold a specific achievement.
     *
     * @param int $achievementId Achievement definition ID
     * @return array List of member awards with member name and award details
     */
    public function getMembersWithAchievement(int $achievementId): array
    {
        return $this->db->fetchAll(
            "SELECT ma.id, ma.member_id, ma.awarded_date, ma.awarded_by, ma.notes,
                    m.first_name, m.surname, m.membership_number
             FROM member_achievements ma
             JOIN members m ON m.id = ma.member_id
             WHERE ma.achievement_id = :achievement_id
             ORDER BY m.surname ASC, m.first_name ASC",
            ['achievement_id' => $achievementId]
        );
    }

    /**
     * Reorder definitions by updating sort_order based on the given array
     * of IDs. The position in the array determines the sort_order value.
     *
     * @param array<int> $orderedIds Definition IDs in the desired order
     */
    public function reorderDefinitions(array $orderedIds): void
    {
        foreach ($orderedIds as $position => $id) {
            $this->db->update('achievement_definitions', [
                'sort_order' => $position,
            ], ['id' => (int) $id]);
        }
    }

    // ──── Private helpers ────

    /**
     * Validate required fields for definition creation.
     *
     * @throws \InvalidArgumentException if validation fails
     */
    private function validateDefinition(array $data): void
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new \InvalidArgumentException('Name is required');
        }

        if (!isset($data['category']) || !in_array($data['category'], ['achievement', 'training'], true)) {
            throw new \InvalidArgumentException('Category must be "achievement" or "training"');
        }
    }
}
