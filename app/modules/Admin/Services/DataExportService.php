<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use App\Core\Database;

/**
 * Data export service.
 *
 * Generates CSV, XML, and JSON exports for members, individual GDPR
 * subject access requests, and system settings.
 */
class DataExportService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    /**
     * Export members as CSV, optionally scoped by organisation nodes.
     *
     * Columns: membership_number, first_name, surname, email, phone,
     * dob, gender, status, joined_date, node_names.
     *
     * @param array|null $nodeIds Limit to members belonging to these nodes (null = all)
     * @return string CSV content
     */
    public function exportMembersCsv(?array $nodeIds = null): string
    {
        $members = $this->fetchMembers($nodeIds);

        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary stream for CSV export');
        }

        // Header row
        fputcsv($handle, [
            'membership_number', 'first_name', 'surname', 'email', 'phone',
            'dob', 'gender', 'status', 'joined_date', 'node_names',
        ]);

        foreach ($members as $row) {
            fputcsv($handle, [
                $row['membership_number'],
                $row['first_name'],
                $row['surname'],
                $row['email'] ?? '',
                $row['phone'] ?? '',
                $row['dob'] ?? '',
                $row['gender'] ?? '',
                $row['status'],
                $row['joined_date'] ?? '',
                $row['node_names'] ?? '',
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Export members as XML, optionally scoped by organisation nodes.
     *
     * @param array|null $nodeIds Limit to members belonging to these nodes (null = all)
     * @return string XML content
     */
    public function exportMembersXml(?array $nodeIds = null): string
    {
        $members = $this->fetchMembers($nodeIds);

        $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><members/>');

        foreach ($members as $row) {
            $member = $xml->addChild('member');
            $member->addChild('membership_number', $this->xmlEscape($row['membership_number']));
            $member->addChild('first_name', $this->xmlEscape($row['first_name']));
            $member->addChild('surname', $this->xmlEscape($row['surname']));
            $member->addChild('email', $this->xmlEscape($row['email'] ?? ''));
            $member->addChild('phone', $this->xmlEscape($row['phone'] ?? ''));
            $member->addChild('dob', $this->xmlEscape($row['dob'] ?? ''));
            $member->addChild('gender', $this->xmlEscape($row['gender'] ?? ''));
            $member->addChild('status', $this->xmlEscape($row['status']));
            $member->addChild('joined_date', $this->xmlEscape($row['joined_date'] ?? ''));
            $member->addChild('node_names', $this->xmlEscape($row['node_names'] ?? ''));
        }

        $output = $xml->asXML();

        return $output !== false ? $output : '';
    }

    /**
     * Export all data for a single member (GDPR subject access request).
     *
     * Includes: all member fields, custom data, timeline entries,
     * and role assignments.
     *
     * @param int $memberId Member ID
     * @return string CSV content
     * @throws \RuntimeException If the member does not exist
     */
    public function exportMyDataCsv(int $memberId): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open temporary stream for CSV export');
        }

        // ── Member profile ──
        $member = $this->db->fetchOne(
            "SELECT m.*, GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR ', ') AS node_names
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             LEFT JOIN org_nodes n ON n.id = mn.node_id
             WHERE m.id = :id
             GROUP BY m.id",
            ['id' => $memberId]
        );

        if ($member === null) {
            fclose($handle);
            throw new \RuntimeException("Member $memberId not found");
        }

        // Remove encrypted medical notes from export (sensitive)
        unset($member['medical_notes']);

        fputcsv($handle, ['--- MEMBER PROFILE ---']);
        fputcsv($handle, array_keys($member));
        fputcsv($handle, array_values($member));
        fputcsv($handle, []);

        // ── Custom field data ──
        $customData = $this->db->fetchAll(
            "SELECT cfd.field_id, cf.label, cfd.value
             FROM custom_field_data cfd
             JOIN custom_fields cf ON cf.id = cfd.field_id
             WHERE cfd.member_id = :id
             ORDER BY cf.sort_order ASC",
            ['id' => $memberId]
        );

        if (!empty($customData)) {
            fputcsv($handle, ['--- CUSTOM FIELDS ---']);
            fputcsv($handle, ['field_id', 'label', 'value']);
            foreach ($customData as $row) {
                fputcsv($handle, [$row['field_id'], $row['label'], $row['value']]);
            }
            fputcsv($handle, []);
        }

        // ── Timeline entries ──
        $timeline = $this->db->fetchAll(
            "SELECT t.id, t.entry_type, t.title, t.body, t.created_at, u.email AS created_by_email
             FROM timeline_entries t
             LEFT JOIN users u ON u.id = t.created_by
             WHERE t.member_id = :id
             ORDER BY t.created_at DESC",
            ['id' => $memberId]
        );

        if (!empty($timeline)) {
            fputcsv($handle, ['--- TIMELINE ---']);
            fputcsv($handle, ['id', 'entry_type', 'title', 'body', 'created_at', 'created_by_email']);
            foreach ($timeline as $row) {
                fputcsv($handle, [
                    $row['id'], $row['entry_type'], $row['title'],
                    $row['body'], $row['created_at'], $row['created_by_email'] ?? '',
                ]);
            }
            fputcsv($handle, []);
        }

        // ── Role assignments ──
        $roles = $this->db->fetchAll(
            "SELECT ra.id, r.name AS role_name, n.name AS node_name,
                    ra.assigned_at, ra.expires_at
             FROM role_assignments ra
             JOIN roles r ON r.id = ra.role_id
             LEFT JOIN org_nodes n ON n.id = ra.node_id
             WHERE ra.member_id = :id
             ORDER BY ra.assigned_at DESC",
            ['id' => $memberId]
        );

        if (!empty($roles)) {
            fputcsv($handle, ['--- ROLE ASSIGNMENTS ---']);
            fputcsv($handle, ['id', 'role_name', 'node_name', 'assigned_at', 'expires_at']);
            foreach ($roles as $row) {
                fputcsv($handle, [
                    $row['id'], $row['role_name'], $row['node_name'] ?? '',
                    $row['assigned_at'], $row['expires_at'] ?? '',
                ]);
            }
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv !== false ? $csv : '';
    }

    /**
     * Export all system settings as formatted JSON.
     *
     * @return string JSON string
     */
    public function exportSettingsJson(): string
    {
        $rows = $this->db->fetchAll(
            "SELECT `key`, `value`, `group` FROM settings ORDER BY `group` ASC, `key` ASC"
        );

        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['group']][$row['key']] = $row['value'];
        }

        return json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    // ──── Private helpers ────

    /**
     * Fetch members with node names, optionally scoped by node IDs.
     *
     * @param array|null $nodeIds Node IDs to filter by
     * @return array Member rows
     */
    private function fetchMembers(?array $nodeIds): array
    {
        $where = '';
        $params = [];

        if ($nodeIds !== null && !empty($nodeIds)) {
            $placeholders = [];
            foreach ($nodeIds as $i => $nodeId) {
                $key = "node_$i";
                $placeholders[] = ":$key";
                $params[$key] = $nodeId;
            }
            $where = "HAVING MAX(CASE WHEN mn.node_id IN (" . implode(',', $placeholders) . ") THEN 1 ELSE 0 END) = 1";
        }

        return $this->db->fetchAll(
            "SELECT m.membership_number, m.first_name, m.surname, m.email, m.phone,
                    m.dob, m.gender, m.status, m.joined_date,
                    GROUP_CONCAT(DISTINCT n.name ORDER BY mn.is_primary DESC SEPARATOR ', ') AS node_names
             FROM members m
             LEFT JOIN member_nodes mn ON mn.member_id = m.id
             LEFT JOIN org_nodes n ON n.id = mn.node_id
             GROUP BY m.id
             $where
             ORDER BY m.surname ASC, m.first_name ASC",
            $params
        );
    }

    /**
     * Escape a value for safe inclusion in XML.
     *
     * @param string $value Raw value
     * @return string Escaped value
     */
    private function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
