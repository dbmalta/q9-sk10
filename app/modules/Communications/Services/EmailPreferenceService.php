<?php

declare(strict_types=1);

namespace App\Modules\Communications\Services;

use App\Core\Database;

/**
 * Email preference and bounce management service.
 *
 * Manages per-member opt-in/out preferences by email type, tracks
 * bounce counts, and provides queries for opted-in member lists
 * scoped by org node.
 */
class EmailPreferenceService
{
    private Database $db;

    /** Bounce count threshold at which a member is marked as bounced */
    private const BOUNCE_THRESHOLD = 3;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Get all email preferences for a member.
     *
     * @param int $memberId Member ID
     * @return array List of preference rows
     */
    public function getPreferences(int $memberId): array
    {
        return $this->db->fetchAll(
            "SELECT * FROM member_email_preferences WHERE member_id = :member_id",
            ['member_id' => $memberId]
        );
    }

    /**
     * Set (upsert) a member's email preference for a given type.
     *
     * If a preference row already exists for this member + type, it is
     * updated. Otherwise a new row is inserted.
     *
     * @param int $memberId Member ID
     * @param string $emailType Email category (e.g. 'general', 'newsletter')
     * @param bool $optedIn Whether the member is opted in
     */
    public function setPreference(int $memberId, string $emailType, bool $optedIn): void
    {
        $existing = $this->db->fetchOne(
            "SELECT id FROM member_email_preferences
             WHERE member_id = :member_id AND email_type = :email_type",
            ['member_id' => $memberId, 'email_type' => $emailType]
        );

        if ($existing !== null) {
            $this->db->update('member_email_preferences', [
                'is_opted_in' => $optedIn ? 1 : 0,
            ], ['id' => (int) $existing['id']]);
        } else {
            $this->db->insert('member_email_preferences', [
                'member_id' => $memberId,
                'email_type' => $emailType,
                'is_opted_in' => $optedIn ? 1 : 0,
            ]);
        }
    }

    /**
     * Check if a member is opted in for a given email type.
     *
     * If no preference row exists, the member is considered opted in
     * (default behaviour).
     *
     * @param int $memberId Member ID
     * @param string $emailType Email category
     * @return bool True if opted in (or no preference recorded)
     */
    public function isOptedIn(int $memberId, string $emailType = 'general'): bool
    {
        $pref = $this->db->fetchOne(
            "SELECT is_opted_in, bounced FROM member_email_preferences
             WHERE member_id = :member_id AND email_type = :email_type",
            ['member_id' => $memberId, 'email_type' => $emailType]
        );

        if ($pref === null) {
            return true; // No preference = opted in by default
        }

        // Bounced members are effectively not opted in
        if ((bool) $pref['bounced']) {
            return false;
        }

        return (bool) $pref['is_opted_in'];
    }

    /**
     * Record a bounce for a member.
     *
     * Increments the bounce_count on the 'general' preference row.
     * If bounce_count reaches the threshold (3), sets bounced = 1.
     * Creates the preference row if it does not exist.
     *
     * @param int $memberId Member ID
     */
    public function recordBounce(int $memberId): void
    {
        $pref = $this->db->fetchOne(
            "SELECT id, bounce_count FROM member_email_preferences
             WHERE member_id = :member_id AND email_type = 'general'",
            ['member_id' => $memberId]
        );

        if ($pref === null) {
            // Create preference row with bounce
            $this->db->insert('member_email_preferences', [
                'member_id' => $memberId,
                'email_type' => 'general',
                'is_opted_in' => 1,
                'bounced' => 0,
                'bounce_count' => 1,
            ]);
            return;
        }

        $newCount = (int) $pref['bounce_count'] + 1;
        $isBounced = $newCount >= self::BOUNCE_THRESHOLD ? 1 : 0;

        $this->db->update('member_email_preferences', [
            'bounce_count' => $newCount,
            'bounced' => $isBounced,
        ], ['id' => (int) $pref['id']]);
    }

    /**
     * Get members who are opted in for a given email type and not bounced.
     *
     * Returns members who either have an explicit opted-in preference or
     * have no preference at all (default = opted in). Optionally scoped
     * by org node IDs.
     *
     * @param string $emailType Email category
     * @param array|null $nodeIds Limit to members in these org nodes (null = all)
     * @return array List of ['member_id' => ..., 'email' => ..., 'first_name' => ..., 'surname' => ...]
     */
    public function getOptedInMembers(string $emailType = 'general', ?array $nodeIds = null): array
    {
        $params = ['email_type' => $emailType];
        $nodeFilter = '';

        if ($nodeIds !== null && !empty($nodeIds)) {
            $placeholders = [];
            foreach ($nodeIds as $i => $nodeId) {
                $key = "node_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $nodeFilter = "AND EXISTS (
                SELECT 1 FROM member_nodes mn
                WHERE mn.member_id = m.id
                AND mn.node_id IN (" . implode(',', $placeholders) . ")
            )";
        }

        // Members who are opted in: either no preference row (default = in)
        // or an explicit is_opted_in = 1 with bounced = 0.
        return $this->db->fetchAll(
            "SELECT m.id AS member_id, m.email, m.first_name, m.surname
             FROM members m
             WHERE m.status = 'active'
             AND m.email IS NOT NULL
             AND m.email != ''
             $nodeFilter
             AND NOT EXISTS (
                 SELECT 1 FROM member_email_preferences mep
                 WHERE mep.member_id = m.id
                 AND mep.email_type = :email_type
                 AND (mep.is_opted_in = 0 OR mep.bounced = 1)
             )
             ORDER BY m.surname ASC, m.first_name ASC",
            $params
        );
    }
}
