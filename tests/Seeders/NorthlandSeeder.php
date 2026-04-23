<?php

declare(strict_types=1);

namespace Tests\Seeders;

use App\Core\Database;

/**
 * Comprehensive synthetic org seeder — "Scouts of Northland".
 *
 * Creates a complete, realistic Scout organisation with 150+ members,
 * events, articles, achievements, registrations, and admin data.
 * Idempotent: truncates all data tables before seeding.
 */
class NorthlandSeeder
{
    private Database $db;
    private string $passwordHash;
    private array $levelTypes = [];
    private array $nodes = [];
    private array $teams = [];
    private array $users = [];
    private array $roles = [];
    private array $members = [];
    private array $achievements = [];

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->passwordHash = password_hash('TestPass123!', PASSWORD_BCRYPT);
    }

    /**
     * Run the full seed. Idempotent — wipes and re-creates everything.
     */
    public function run(): void
    {
        // Truncate outside transaction — TRUNCATE is DDL and causes implicit commit
        $this->truncateAll();

        $this->db->beginTransaction();
        try {
            $this->seedLevelTypes();
            $this->seedOrgTree();
            $this->seedTeams();
            $this->seedRoles();
            $this->seedUsers();
            $this->seedMembers();
            $this->seedMemberNodes();
            $this->seedRoleAssignments();
            $this->seedCustomFields();
            $this->seedCustomFieldData();
            $this->seedTimeline();
            $this->seedEvents();
            $this->seedArticles();
            $this->seedAchievements();
            $this->seedAchievementAssignments();
            $this->seedRegistrations();
            $this->seedWaitingList();
            $this->seedEmailQueue();
            $this->seedEmailPreferences();
            $this->seedTerms();
            $this->seedNotices();
            $this->seedSettings();
            $this->seedAuditLog();
            $this->db->commit();
        } catch (\Throwable $e) {
            try {
                $this->db->rollback();
            } catch (\Throwable) {
                // Rollback may fail if transaction was implicitly committed
            }
            throw $e;
        }
    }

    /**
     * Truncate all data tables in dependency-safe order.
     */
    private function truncateAll(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $tables = [
            'notice_acknowledgements', 'notices',
            'terms_acceptances', 'terms_versions',
            'audit_log',
            'email_log', 'email_queue', 'member_email_preferences',
            'member_achievements', 'achievement_definitions',
            'event_ical_tokens', 'events',
            'articles',
            'member_attachments', 'member_timeline',
            'custom_field_definitions',
            'waiting_list', 'registration_invitations',
            'member_pending_changes', 'medical_access_log',
            'member_nodes', 'members',
            'role_assignment_scopes', 'role_assignments', 'roles',
            'user_sessions', 'password_resets', 'users',
            'org_teams', 'org_closure', 'org_nodes', 'org_level_types',
            'settings',
        ];
        foreach ($tables as $t) {
            $this->db->query("TRUNCATE TABLE `$t`");
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Level Types ──────────────────────────────────────────────

    private function seedLevelTypes(): void
    {
        $types = [
            ['name' => 'National Organisation', 'depth' => 0, 'is_leaf' => 0, 'sort_order' => 1],
            ['name' => 'Region',                'depth' => 1, 'is_leaf' => 0, 'sort_order' => 2],
            ['name' => 'District',              'depth' => 2, 'is_leaf' => 0, 'sort_order' => 3],
            ['name' => 'Group',                 'depth' => 3, 'is_leaf' => 0, 'sort_order' => 4],
            ['name' => 'Section',               'depth' => 4, 'is_leaf' => 1, 'sort_order' => 5],
        ];
        foreach ($types as $t) {
            $this->levelTypes[$t['name']] = $this->db->insert('org_level_types', $t);
        }
    }

    // ── Org Tree ─────────────────────────────────────────────────

    private function seedOrgTree(): void
    {
        // National
        $natId = $this->createNode(null, 'National Organisation', 'Scouts of Northland', 'SoN');

        // Regions
        $northId = $this->createNode($natId, 'Region', 'Northern Region', 'NR');
        $southId = $this->createNode($natId, 'Region', 'Southern Region', 'SR');

        // Districts
        $frostId   = $this->createNode($northId, 'District', 'Frostdale District', 'FD');
        $pineId    = $this->createNode($northId, 'District', 'Pinewood District', 'PW');
        $coastId   = $this->createNode($southId, 'District', 'Coastview District', 'CV');

        // Groups
        $g1 = $this->createNode($frostId, 'Group', '1st Frostdale', '1F');
        $g2 = $this->createNode($frostId, 'Group', '2nd Frostdale', '2F');
        $g3 = $this->createNode($pineId,  'Group', '1st Pinewood', '1P');
        $g4 = $this->createNode($coastId, 'Group', '1st Coastview', '1C');

        // Sections (leaf nodes with age ranges)
        $this->createNode($g1, 'Section', 'Beaver Colony',   'BC', 6, 8);
        $this->createNode($g1, 'Section', 'Cub Pack',        'CP', 8, 11);
        $this->createNode($g1, 'Section', 'Scout Troop',     'ST', 11, 14);
        $this->createNode($g1, 'Section', 'Venture Unit',    'VU', 14, 18);

        $this->createNode($g2, 'Section', 'Cub Pack',        'CP', 8, 11);
        $this->createNode($g2, 'Section', 'Scout Troop',     'ST', 11, 14);

        $this->createNode($g3, 'Section', 'Beaver Colony',   'BC', 6, 8);
        $this->createNode($g3, 'Section', 'Cub Pack',        'CP', 8, 11);
        $this->createNode($g3, 'Section', 'Scout Troop',     'ST', 11, 14);

        $this->createNode($g4, 'Section', 'Cub Pack',        'CP', 8, 11);
        $this->createNode($g4, 'Section', 'Scout Troop',     'ST', 11, 14);
        $this->createNode($g4, 'Section', 'Venture Unit',    'VU', 14, 18);
    }

    private function createNode(?int $parentId, string $levelType, string $name, string $shortName, ?int $ageMin = null, ?int $ageMax = null): int
    {
        $data = [
            'parent_id'     => $parentId,
            'level_type_id' => $this->levelTypes[$levelType],
            'name'          => $name,
            'short_name'    => $shortName,
            'sort_order'    => count($this->nodes) + 1,
            'is_active'     => 1,
        ];
        if ($ageMin !== null) {
            $data['age_group_min'] = $ageMin;
            $data['age_group_max'] = $ageMax;
        }

        $nodeId = $this->db->insert('org_nodes', $data);
        $this->nodes[$name . ($parentId ? '-' . $parentId : '')] = $nodeId;

        // Maintain closure table
        if ($parentId === null) {
            $this->db->insert('org_closure', [
                'ancestor_id'   => $nodeId,
                'descendant_id' => $nodeId,
                'depth'         => 0,
            ]);
        } else {
            // Copy parent's ancestor paths and add self-reference
            $this->db->query(
                'INSERT INTO org_closure (ancestor_id, descendant_id, depth)
                 SELECT ancestor_id, :newNode, depth + 1
                 FROM org_closure
                 WHERE descendant_id = :parent',
                ['newNode' => $nodeId, 'parent' => $parentId]
            );
            $this->db->insert('org_closure', [
                'ancestor_id'   => $nodeId,
                'descendant_id' => $nodeId,
                'depth'         => 0,
            ]);
        }

        return $nodeId;
    }

    // ── Teams ────────────────────────────────────────────────────

    private function seedTeams(): void
    {
        $natId = $this->getNodeId('Scouts of Northland');

        $teams = [
            ['node_id' => $natId, 'name' => 'National Board',       'description' => 'Governing body of Scouts of Northland',                 'is_permanent' => 1],
            ['node_id' => $natId, 'name' => 'Finance Committee',    'description' => 'Oversees national finances and budgeting',               'is_permanent' => 1],
            ['node_id' => $natId, 'name' => 'Training Team',        'description' => 'National training and development team',                 'is_permanent' => 1],
            ['node_id' => $natId, 'name' => 'International Team',   'description' => 'Manages international relations and exchanges',          'is_permanent' => 1],
            ['node_id' => $this->getNodeId('1st Frostdale'), 'name' => 'Camp Committee 2026', 'description' => 'Organising committee for summer camp 2026', 'is_permanent' => 0],
            ['node_id' => $this->getNodeId('Northern Region'), 'name' => 'Regional Events Team', 'description' => 'Coordinates regional events and activities', 'is_permanent' => 1],
        ];

        foreach ($teams as $t) {
            $t['is_active'] = 1;
            $this->teams[$t['name']] = $this->db->insert('org_teams', $t);
        }
    }

    // ── Roles ────────────────────────────────────────────────────

    private function seedRoles(): void
    {
        // Permissions are stored in flat "module.action" => true format
        // to match RolesController output and the edit form template.
        $flat = static function (array $nested): string {
            $out = [];
            foreach ($nested as $module => $actions) {
                foreach ($actions as $action) {
                    $out["$module.$action"] = true;
                }
            }
            return json_encode($out);
        };

        $roles = [
            [
                'name'                => 'Super Admin',
                'description'         => 'Full system access',
                'permissions'         => $flat(['members' => ['read', 'write'], 'org_structure' => ['read', 'write'], 'admin' => ['dashboard', 'reports', 'terms', 'notices', 'settings', 'audit', 'logs', 'export', 'backup', 'languages', 'updates', 'monitoring'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read'], 'custom_fields' => ['write'], 'registrations' => ['manage']]),
                'can_publish_events'  => 1,
                'can_access_medical'  => 1,
                'can_access_financial'=> 1,
                'is_directory_visible'=> 1,
                'is_system'           => 1,
            ],
            [
                'name'                => 'Group Leader',
                'description'         => 'Manages a Scout Group',
                'permissions'         => $flat(['members' => ['read', 'write'], 'org_structure' => ['read'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read'], 'registrations' => ['manage']]),
                'can_publish_events'  => 1,
                'can_access_medical'  => 1,
                'can_access_financial'=> 0,
                'is_directory_visible'=> 1,
                'is_system'           => 1,
            ],
            [
                'name'                => 'Section Leader',
                'description'         => 'Leads a Section within a Group',
                'permissions'         => $flat(['members' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read']]),
                'can_publish_events'  => 1,
                'can_access_medical'  => 1,
                'can_access_financial'=> 0,
                'is_directory_visible'=> 1,
                'is_system'           => 1,
            ],
            [
                'name'                => 'District Commissioner',
                'description'         => 'Oversees a District',
                'permissions'         => $flat(['members' => ['read', 'write'], 'org_structure' => ['read'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read'], 'directory' => ['read']]),
                'can_publish_events'  => 1,
                'can_access_medical'  => 0,
                'can_access_financial'=> 0,
                'is_directory_visible'=> 1,
                'is_system'           => 0,
            ],
            [
                'name'                => 'Member',
                'description'         => 'Basic member access — view only',
                'permissions'         => $flat(['directory' => ['read'], 'events' => ['read']]),
                'can_publish_events'  => 0,
                'can_access_medical'  => 0,
                'can_access_financial'=> 0,
                'is_directory_visible'=> 0,
                'is_system'           => 0,
            ],
        ];

        foreach ($roles as $r) {
            $this->roles[$r['name']] = $this->db->insert('roles', $r);
        }
    }

    // ── Users ────────────────────────────────────────────────────

    private function seedUsers(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // Known-state users for Playwright
        $knownUsers = [
            ['email' => 'admin@northland.test',    'is_super_admin' => 1],
            ['email' => 'leader@northland.test',   'is_super_admin' => 0],
            ['email' => 'member@northland.test',   'is_super_admin' => 0],
            ['email' => 'pending@northland.test',  'is_super_admin' => 0], // pending T&Cs
            ['email' => 'mfa@northland.test',      'is_super_admin' => 0], // MFA enabled
        ];

        foreach ($knownUsers as $u) {
            $this->users[$u['email']] = $this->db->insert('users', [
                'email'               => $u['email'],
                'password_hash'       => $this->passwordHash,
                'is_super_admin'      => $u['is_super_admin'],
                'is_active'           => 1,
                'password_changed_at' => $now,
            ]);
        }

        // Enable MFA for the mfa user (dummy TOTP secret for testing).
        // AuthService decrypts mfa_secret at verify time — store it encrypted.
        $plainSecret = 'JBSWY3DPEHPK3PXP'; // well-known test secret, base32 encoded
        $encryptedSecret = $plainSecret;
        try {
            $keyPath = defined('ROOT_PATH') ? ROOT_PATH . '/config/encryption.key' : __DIR__ . '/../../config/encryption.key';
            if (file_exists($keyPath)) {
                $enc = new \App\Core\Encryption($keyPath);
                $encryptedSecret = $enc->encrypt($plainSecret);
            }
        } catch (\Throwable $e) {
            // Fall back to plaintext — tests that don't use MFA still pass.
        }
        $this->db->update('users', [
            'mfa_enabled' => 1,
            'mfa_secret'  => $encryptedSecret,
        ], ['id' => $this->users['mfa@northland.test']]);

        // Additional leader/member accounts (25 more)
        for ($i = 1; $i <= 25; $i++) {
            $email = sprintf('user%02d@northland.test', $i);
            $this->users[$email] = $this->db->insert('users', [
                'email'               => $email,
                'password_hash'       => $this->passwordHash,
                'is_super_admin'      => 0,
                'is_active'           => 1,
                'password_changed_at' => $now,
            ]);
        }

        // Seed last-used view mode so the switcher has a realistic starting
        // point for each Playwright archetype. The admin user stays null to
        // exercise the "first login, no preference" path.
        $this->db->update('users', ['view_mode_last' => 'admin'],  ['email' => 'leader@northland.test']);
        $this->db->update('users', ['view_mode_last' => 'member'], ['email' => 'member@northland.test']);
    }

    // ── Members ──────────────────────────────────────────────────

    private function seedMembers(): void
    {
        $names = $this->getMemberNames();
        $statuses = $this->getMemberStatuses();
        $now = gmdate('Y-m-d H:i:s');

        foreach ($names as $i => $name) {
            $idx = $i + 1;
            $status = $statuses[$i] ?? 'active';
            $gender = $this->pickGender($i);
            $dob = $this->generateDob($i);
            $joinedDate = $this->generateJoinDate($i);

            $data = [
                'membership_number' => sprintf('SK-%06d', $idx),
                'first_name'        => $name[0],
                'surname'           => $name[1],
                'dob'               => $dob,
                'gender'            => $gender,
                'email'             => strtolower($name[0]) . '.' . strtolower($name[1]) . '@example.test',
                'phone'             => sprintf('+356 %04d %04d', rand(2000, 9999), rand(1000, 9999)),
                'address_line1'     => ($idx) . ' ' . $this->pickStreet($i),
                'city'              => $this->pickCity($i),
                'postcode'          => sprintf('NL%03d', $idx),
                'country'           => 'Northland',
                'status'            => $status,
                'joined_date'       => $status !== 'pending' ? $joinedDate : null,
                'left_date'         => $status === 'left' ? '2025-12-31' : null,
                'gdpr_consent'      => 1,
                'member_custom_data'=> null,
                'created_at'        => $now,
            ];

            // Link first 30 members to user accounts
            if ($idx <= 5) {
                // Known Playwright users
                $emailMap = [1 => 'admin@northland.test', 2 => 'leader@northland.test', 3 => 'member@northland.test', 4 => 'pending@northland.test', 5 => 'mfa@northland.test'];
                $data['user_id'] = $this->users[$emailMap[$idx]];
            } elseif ($idx <= 30) {
                $data['user_id'] = $this->users[sprintf('user%02d@northland.test', $idx - 5)];
            }

            $this->members[$idx] = $this->db->insert('members', $data);
        }
    }

    private function seedMemberNodes(): void
    {
        // Get all section node IDs for distributing members
        $sections = $this->db->fetchAll(
            'SELECT n.id, n.name, n.parent_id FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.is_leaf = 1 ORDER BY n.id'
        );
        $sectionIds = array_column($sections, 'id');

        // Get group node IDs for leaders
        $groups = $this->db->fetchAll(
            "SELECT n.id FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.name = 'Group' ORDER BY n.id"
        );
        $groupIds = array_column($groups, 'id');

        foreach ($this->members as $idx => $memberId) {
            if ($idx <= 4) {
                // Leaders: assign to groups + a section within
                $gIdx = ($idx - 1) % count($groupIds);
                $this->db->query(
                    'INSERT IGNORE INTO member_nodes (member_id, node_id, is_primary) VALUES (?, ?, 1)',
                    [$memberId, $groupIds[$gIdx]]
                );
            } else {
                // Regular members: assign to sections
                $sIdx = ($idx - 5) % count($sectionIds);
                $this->db->query(
                    'INSERT IGNORE INTO member_nodes (member_id, node_id, is_primary) VALUES (?, ?, 1)',
                    [$memberId, $sectionIds[$sIdx]]
                );
            }

            // 10 members with multiple node assignments (cross-level)
            if ($idx >= 141 && $idx <= 150 && count($sectionIds) > 1) {
                $altSection = $sectionIds[($idx + 3) % count($sectionIds)];
                $this->db->query(
                    'INSERT IGNORE INTO member_nodes (member_id, node_id, is_primary) VALUES (?, ?, 0)',
                    [$memberId, $altSection]
                );
            }
        }
    }

    // ── Role Assignments ─────────────────────────────────────────

    private function seedRoleAssignments(): void
    {
        $natId = $this->getNodeId('Scouts of Northland');

        $assignments = [];

        // Member 1 (admin): Super Admin at national level
        $assignments[] = ['user' => 'admin@northland.test', 'role' => 'Super Admin', 'context_type' => 'node', 'context_id' => $natId, 'scope_nodes' => [$natId], 'start' => '2020-01-01'];

        // Member 2 (leader): Group Leader at 1st Frostdale
        $g1 = $this->getNodeId('1st Frostdale');
        $assignments[] = ['user' => 'leader@northland.test', 'role' => 'Group Leader', 'context_type' => 'node', 'context_id' => $g1, 'scope_nodes' => [$g1], 'start' => '2021-06-15'];

        // Member 3 (member): Member role at 1st Frostdale Scout Troop
        $sections = $this->db->fetchAll(
            'SELECT n.id, n.parent_id, n.name FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.is_leaf = 1 ORDER BY n.id'
        );
        $firstSection = $sections[0]['id'] ?? 1;
        $assignments[] = ['user' => 'member@northland.test', 'role' => 'Member', 'context_type' => 'node', 'context_id' => $firstSection, 'scope_nodes' => [$firstSection], 'start' => '2023-09-01'];

        // Section leaders for several sections
        $sectionLeaderIdx = 0;
        foreach (array_slice($sections, 0, 8) as $sec) {
            $userEmail = sprintf('user%02d@northland.test', $sectionLeaderIdx + 1);
            if (isset($this->users[$userEmail])) {
                $assignments[] = ['user' => $userEmail, 'role' => 'Section Leader', 'context_type' => 'node', 'context_id' => (int)$sec['id'], 'scope_nodes' => [(int)$sec['id']], 'start' => '2022-09-01'];
            }
            $sectionLeaderIdx++;
        }

        // District commissioners
        $districts = $this->db->fetchAll(
            "SELECT n.id FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.name = 'District' ORDER BY n.id"
        );
        $dcIdx = 9;
        foreach ($districts as $d) {
            $userEmail = sprintf('user%02d@northland.test', $dcIdx);
            if (isset($this->users[$userEmail])) {
                $assignments[] = ['user' => $userEmail, 'role' => 'District Commissioner', 'context_type' => 'node', 'context_id' => (int)$d['id'], 'scope_nodes' => [(int)$d['id']], 'start' => '2022-01-01'];
            }
            $dcIdx++;
        }

        // Team assignment: National Board
        $assignments[] = ['user' => 'admin@northland.test', 'role' => 'Super Admin', 'context_type' => 'team', 'context_id' => $this->teams['National Board'], 'scope_nodes' => [$natId], 'start' => '2020-01-01'];

        // 3 expired role assignments
        $expiredUsers = ['user13@northland.test', 'user14@northland.test', 'user15@northland.test'];
        foreach ($expiredUsers as $i => $email) {
            if (isset($this->users[$email])) {
                $sec = $sections[$i % count($sections)];
                $assignments[] = ['user' => $email, 'role' => 'Section Leader', 'context_type' => 'node', 'context_id' => (int)$sec['id'], 'scope_nodes' => [(int)$sec['id']], 'start' => '2020-01-01', 'end' => '2024-08-31'];
            }
        }

        $adminUserId = $this->users['admin@northland.test'];
        foreach ($assignments as $a) {
            if (!isset($this->users[$a['user']])) continue;

            $assignId = $this->db->insert('role_assignments', [
                'user_id'      => $this->users[$a['user']],
                'role_id'      => $this->roles[$a['role']],
                'context_type' => $a['context_type'],
                'context_id'   => $a['context_id'],
                'start_date'   => $a['start'],
                'end_date'     => $a['end'] ?? null,
                'assigned_by'  => $adminUserId,
            ]);

            foreach ($a['scope_nodes'] as $scopeNode) {
                $this->db->insert('role_assignment_scopes', [
                    'assignment_id' => $assignId,
                    'node_id'       => $scopeNode,
                ]);
            }
        }
    }

    // ── Custom Fields ────────────────────────────────────────────

    private function seedCustomFields(): void
    {
        $fields = [
            ['field_key' => 'uniform_size',      'field_type' => 'dropdown',   'label' => 'Uniform Size',      'is_required' => 0, 'validation_rules' => json_encode(['options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']]), 'display_group' => 'additional', 'sort_order' => 1],
            ['field_key' => 'allergies',         'field_type' => 'long_text',  'label' => 'Allergies',         'is_required' => 0, 'display_group' => 'medical',    'sort_order' => 2],
            ['field_key' => 'emergency_phone',   'field_type' => 'short_text', 'label' => 'Emergency Phone',   'is_required' => 1, 'display_group' => 'contact',    'sort_order' => 3],
            ['field_key' => 'swimming_ability',  'field_type' => 'dropdown',   'label' => 'Swimming Ability',  'is_required' => 0, 'validation_rules' => json_encode(['options' => ['None', 'Basic', 'Competent', 'Strong']]), 'display_group' => 'additional', 'sort_order' => 4],
            ['field_key' => 'school_name',       'field_type' => 'short_text', 'label' => 'School Name',       'is_required' => 0, 'display_group' => 'additional', 'sort_order' => 5],
            ['field_key' => 'dietary_needs',     'field_type' => 'short_text', 'label' => 'Dietary Needs',     'is_required' => 0, 'display_group' => 'medical',    'sort_order' => 6],
        ];

        foreach ($fields as $f) {
            $f['is_active'] = 1;
            $this->db->insert('custom_field_definitions', $f);
        }
    }

    private function seedCustomFieldData(): void
    {
        $sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        $swimming = ['None', 'Basic', 'Competent', 'Strong'];

        // Give ~80 members custom field data
        foreach (array_slice($this->members, 0, 80, true) as $idx => $memberId) {
            $data = [
                'uniform_size'    => $sizes[array_rand($sizes)],
                'swimming_ability'=> $swimming[array_rand($swimming)],
                'emergency_phone' => sprintf('+356 %04d %04d', rand(2000, 9999), rand(1000, 9999)),
            ];
            if ($idx % 3 === 0) {
                $data['school_name'] = $this->pickSchool($idx);
            }
            if ($idx % 5 === 0) {
                $data['dietary_needs'] = ['Vegetarian', 'Gluten-free', 'Halal', 'Vegan', 'No nuts'][$idx % 5];
            }

            $this->db->update('members', [
                'member_custom_data' => json_encode($data),
            ], ['id' => $memberId]);
        }
    }

    // ── Timeline ─────────────────────────────────────────────────

    private function seedTimeline(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $ranks = ['Tenderfoot', 'Second Class', 'First Class', 'Star Scout', 'Eagle Scout'];

        // 20 members with timeline entries
        $timelineMembers = array_slice($this->members, 0, 20, true);
        foreach ($timelineMembers as $idx => $memberId) {
            // Rank progression
            $numRanks = min($idx % 4 + 1, count($ranks));
            for ($r = 0; $r < $numRanks; $r++) {
                $year = 2021 + $r;
                $this->db->insert('member_timeline', [
                    'member_id'      => $memberId,
                    'field_key'      => 'rank',
                    'value'          => $ranks[$r],
                    'effective_date' => "$year-09-01",
                    'recorded_by'    => $adminUserId,
                    'notes'          => 'Awarded at annual ceremony',
                ]);
            }

            // Some qualification entries
            if ($idx <= 10) {
                $this->db->insert('member_timeline', [
                    'member_id'      => $memberId,
                    'field_key'      => 'first_aid_cert',
                    'value'          => 'Certified',
                    'effective_date' => '2024-03-15',
                    'recorded_by'    => $adminUserId,
                    'notes'          => 'Red Cross first aid course',
                ]);
            }
        }
    }

    // ── Events ───────────────────────────────────────────────────

    private function seedEvents(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $leaderUserId = $this->users['leader@northland.test'];
        $natId = $this->getNodeId('Scouts of Northland');
        $g1 = $this->getNodeId('1st Frostdale');

        $events = [
            // Past events
            ['title' => 'Winter Hike 2025',            'description' => 'Annual winter hiking expedition through Northland trails.',                      'location' => 'Frostdale Trailhead',     'start' => '2025-12-15 09:00:00', 'end' => '2025-12-15 16:00:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'Christmas Charity Drive',      'description' => 'Food and gift collection for local families in need.',                          'location' => '1st Frostdale Scout Hall', 'start' => '2025-12-20 10:00:00', 'end' => '2025-12-20 14:00:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'National AGM 2026',            'description' => 'Annual General Meeting of Scouts of Northland.',                                'location' => 'National HQ',              'start' => '2026-01-25 10:00:00', 'end' => '2026-01-25 17:00:00', 'all_day' => 0, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'First Aid Training Weekend',   'description' => 'Red Cross first aid certification course for leaders.',                         'location' => 'Pinewood Community Centre','start' => '2026-02-08 09:00:00', 'end' => '2026-02-09 16:00:00', 'all_day' => 0, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'District Swimming Gala',       'description' => 'Inter-group swimming competition.',                                              'location' => 'Coastview Aquatic Centre', 'start' => '2026-02-22 13:00:00', 'end' => '2026-02-22 17:00:00', 'all_day' => 0, 'node' => null,   'by' => $adminUserId],
            ['title' => 'Spring Camp',                  'description' => 'Three-day spring camping trip with outdoor skills workshops.',                   'location' => 'Northland Forest Reserve','start' => '2026-03-20 00:00:00', 'end' => '2026-03-22 00:00:00', 'all_day' => 1, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'St George\'s Day Parade',      'description' => 'Traditional Scout parade through Frostdale town centre.',                       'location' => 'Frostdale Main Street',   'start' => '2026-04-23 10:00:00', 'end' => '2026-04-23 12:00:00', 'all_day' => 0, 'node' => null,   'by' => $adminUserId],
            // Future events
            ['title' => 'Founders Day Celebration',     'description' => 'Commemorating the founding of Scouts of Northland.',                            'location' => 'National HQ',              'start' => '2026-05-10 10:00:00', 'end' => '2026-05-10 15:00:00', 'all_day' => 0, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'Summer Camp 2026',             'description' => 'Annual week-long summer camp. All sections welcome.',                           'location' => 'Coastview Campsite',       'start' => '2026-07-12 00:00:00', 'end' => '2026-07-19 00:00:00', 'all_day' => 1, 'node' => null,   'by' => $adminUserId],
            ['title' => 'Leadership Course',            'description' => 'National leadership and management course for adult leaders.',                  'location' => 'National Training Centre','start' => '2026-06-05 09:00:00', 'end' => '2026-06-07 16:00:00', 'all_day' => 0, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'Group Fun Day',                'description' => 'Open day with games, activities, and demonstrations.',                          'location' => '1st Frostdale Scout Hall', 'start' => '2026-05-24 11:00:00', 'end' => '2026-05-24 16:00:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'National Jamboree 2026',       'description' => 'Biennial national Scout jamboree. Registration required.',                      'location' => 'Northland Exhibition Park','start' => '2026-08-01 00:00:00', 'end' => '2026-08-07 00:00:00', 'all_day' => 1, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'Orienteering Challenge',       'description' => 'District-level orienteering competition in the Northern Forest.',               'location' => 'Northern Forest',          'start' => '2026-05-17 08:30:00', 'end' => '2026-05-17 14:00:00', 'all_day' => 0, 'node' => null,   'by' => $leaderUserId],
            ['title' => 'Cub Activity Day',             'description' => 'Fun activity day for all Cub sections.',                                        'location' => '2nd Frostdale Scout Hall', 'start' => '2026-06-14 10:00:00', 'end' => '2026-06-14 15:00:00', 'all_day' => 0, 'node' => null,   'by' => $leaderUserId],
            ['title' => 'Campfire Night',               'description' => 'Traditional campfire evening with songs, skits, and marshmallows.',             'location' => 'Frostdale Park',           'start' => '2026-06-21 19:00:00', 'end' => '2026-06-21 22:00:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'Regional Commissioner Meeting', 'description' => 'Quarterly meeting of regional and district commissioners.',                    'location' => 'National HQ',              'start' => '2026-09-14 14:00:00', 'end' => '2026-09-14 17:00:00', 'all_day' => 0, 'node' => $natId, 'by' => $adminUserId],
            ['title' => 'Back to Scouts Night',         'description' => 'Welcome back evening at the start of the new scouting year.',                   'location' => '1st Frostdale Scout Hall', 'start' => '2026-09-06 18:00:00', 'end' => '2026-09-06 20:30:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'Investiture Ceremony',         'description' => 'Formal investiture of new members into their sections.',                        'location' => '1st Frostdale Scout Hall', 'start' => '2026-10-04 17:00:00', 'end' => '2026-10-04 19:00:00', 'all_day' => 0, 'node' => $g1,    'by' => $leaderUserId],
            ['title' => 'Remembrance Day Service',      'description' => 'Annual remembrance parade and service.',                                        'location' => 'Frostdale War Memorial',  'start' => '2026-11-08 10:00:00', 'end' => '2026-11-08 12:00:00', 'all_day' => 0, 'node' => null,   'by' => $adminUserId],
            ['title' => 'Winter Camp 2026',             'description' => 'End of year winter camping trip.',                                              'location' => 'Northland Forest Reserve','start' => '2026-12-05 00:00:00', 'end' => '2026-12-07 00:00:00', 'all_day' => 1, 'node' => $g1,    'by' => $leaderUserId],
        ];

        foreach ($events as $e) {
            $id = $this->db->insert('events', [
                'title'         => $e['title'],
                'description'   => $e['description'],
                'location'      => $e['location'],
                'start_date'    => $e['start'],
                'end_date'      => $e['end'],
                'all_day'       => $e['all_day'],
                'node_scope_id' => $e['node'],
                'created_by'    => $e['by'],
                'is_published'  => 1,
            ]);
        }
    }

    // ── Articles ─────────────────────────────────────────────────

    private function seedArticles(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $leaderUserId = $this->users['leader@northland.test'];
        $now = gmdate('Y-m-d H:i:s');

        $articles = [
            ['title' => 'Welcome to ScoutKeeper',                    'body' => '<p>We are pleased to announce the launch of our new membership management system. All members can now log in, view their profile, and stay up to date with events and news.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Summer Camp 2026 Registration Now Open',    'body' => '<p>Registration for Summer Camp 2026 is now open! This year we will be heading to Coastview Campsite for a full week of outdoor activities, badge work, and adventure. Speak to your Section Leader for details.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $leaderUserId],
            ['title' => 'New Uniform Policy',                        'body' => '<p>Following feedback from groups across the organisation, we have updated our uniform policy. The new guidelines take effect from September 2026. Please review the attached document.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Safeguarding Training Reminder',            'body' => '<p>All adult leaders are reminded that safeguarding training must be renewed every three years. If your certificate is due to expire, please contact the Training Team to arrange a refresher.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Annual Census Submission',                  'body' => '<p>The annual WOSM census submission is due by 31 March. Group Leaders, please ensure your membership records are up to date.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Community Service Award Winners',           'body' => '<p>Congratulations to the members of 1st Frostdale and 1st Coastview who received Community Service Awards at the national ceremony.</p>', 'visibility' => 'public', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Founders Day — Save the Date',              'body' => '<p>Mark your calendars for Founders Day on 10 May 2026. More details to follow.</p>', 'visibility' => 'members', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'IT Systems Maintenance Notice',             'body' => '<p>The system will undergo routine maintenance this Sunday between 02:00 and 06:00. You may experience brief interruptions during this window.</p>', 'visibility' => 'portal', 'published' => 1, 'author' => $adminUserId],
            ['title' => 'Draft: Volunteer Recognition Programme',    'body' => '<p>Proposal for a new volunteer recognition programme. This is a draft for internal review.</p>', 'visibility' => 'members', 'published' => 0, 'author' => $adminUserId],
            ['title' => 'Draft: Updated Risk Assessment Template',   'body' => '<p>Updated risk assessment form for events and activities. Pending review by the Training Team.</p>', 'visibility' => 'members', 'published' => 0, 'author' => $leaderUserId],
        ];

        foreach ($articles as $i => $a) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $a['title']));
            $slug = trim($slug, '-');

            $this->db->insert('articles', [
                'title'        => $a['title'],
                'slug'         => $slug,
                'body'         => $a['body'],
                'excerpt'      => substr(strip_tags($a['body']), 0, 200),
                'visibility'   => $a['visibility'],
                'is_published' => $a['published'],
                'published_at' => $a['published'] ? $now : null,
                'author_id'    => $a['author'],
            ]);
        }
    }

    // ── Achievements & Training ──────────────────────────────────

    private function seedAchievements(): void
    {
        $defs = [
            ['name' => 'Woodcraft Badge',              'category' => 'achievement', 'description' => 'Proficiency in bushcraft, fire-lighting, and shelter building.'],
            ['name' => 'Navigation Badge',             'category' => 'achievement', 'description' => 'Map reading, compass use, and orienteering.'],
            ['name' => 'First Aid Badge',              'category' => 'achievement', 'description' => 'Emergency first aid knowledge and skills.'],
            ['name' => 'Community Service Award',      'category' => 'achievement', 'description' => 'Recognised for sustained community service.'],
            ['name' => 'Chief Scout Award',            'category' => 'achievement', 'description' => 'Highest youth achievement in the organisation.'],
            ['name' => 'Safeguarding Training',        'category' => 'training',    'description' => 'Mandatory safeguarding and child protection training.'],
            ['name' => 'Leadership Skills Course',     'category' => 'training',    'description' => 'National leadership development programme.'],
            ['name' => 'First Aid Instructor Course',  'category' => 'training',    'description' => 'Qualification to teach first aid to members.'],
        ];

        foreach ($defs as $i => $d) {
            $d['is_active'] = 1;
            $d['sort_order'] = $i + 1;
            $this->achievements[$d['name']] = $this->db->insert('achievement_definitions', $d);
        }
    }

    private function seedAchievementAssignments(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $achNames = array_keys($this->achievements);

        // Award achievements to first 40 adult/leader members
        $count = 0;
        foreach (array_slice($this->members, 0, 40, true) as $idx => $memberId) {
            $numAwards = ($idx % 4) + 1;
            for ($a = 0; $a < $numAwards && $a < count($achNames); $a++) {
                $year = 2022 + ($a % 3);
                $month = (($idx + $a) % 12) + 1;
                $this->db->insert('member_achievements', [
                    'member_id'      => $memberId,
                    'achievement_id' => $this->achievements[$achNames[$a]],
                    'awarded_date'   => sprintf('%d-%02d-15', $year, $month),
                    'awarded_by'     => $adminUserId,
                    'notes'          => null,
                ]);
                $count++;
            }
        }
    }

    // ── Registrations & Invitations ──────────────────────────────

    private function seedRegistrations(): void
    {
        $sections = $this->db->fetchAll(
            'SELECT n.id FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.is_leaf = 1 ORDER BY n.id LIMIT 3'
        );

        $adminUserId = $this->users['admin@northland.test'];

        // 2 active invitation tokens
        foreach (['invite-token-abc123def456', 'invite-token-xyz789ghi012'] as $i => $token) {
            $this->db->insert('registration_invitations', [
                'token'          => $token,
                'target_node_id' => (int)$sections[$i % count($sections)]['id'],
                'created_by'     => $adminUserId,
                'email'          => ($i === 0) ? 'newmember@example.test' : null,
                'expires_at'     => gmdate('Y-m-d H:i:s', strtotime('+7 days')),
            ]);
        }
    }

    private function seedWaitingList(): void
    {
        $sections = $this->db->fetchAll(
            'SELECT n.id FROM org_nodes n
             JOIN org_level_types lt ON n.level_type_id = lt.id
             WHERE lt.is_leaf = 1 ORDER BY n.id LIMIT 3'
        );

        $entries = [
            ['parent_name' => 'Maria Johnson',   'parent_email' => 'maria.j@example.test',   'child_name' => 'Oliver Johnson',   'child_dob' => '2019-03-15', 'notes' => 'Interested in Beaver Colony'],
            ['parent_name' => 'Ahmed Hassan',     'parent_email' => 'ahmed.h@example.test',   'child_name' => 'Yusuf Hassan',     'child_dob' => '2018-07-22', 'notes' => 'Referred by existing member'],
            ['parent_name' => 'Sarah Williams',   'parent_email' => 'sarah.w@example.test',   'child_name' => 'Emma Williams',    'child_dob' => '2017-11-08', 'notes' => 'Moving to area in September'],
        ];

        foreach ($entries as $i => $e) {
            $e['position'] = $i + 1;
            $e['preferred_node_id'] = (int)$sections[$i % count($sections)]['id'];
            $e['status'] = 'waiting';
            $this->db->insert('waiting_list', $e);
        }
    }

    // ── Email Queue ──────────────────────────────────────────────

    private function seedEmailQueue(): void
    {
        $now = gmdate('Y-m-d H:i:s');

        // 20 queued emails in various states
        for ($i = 1; $i <= 20; $i++) {
            $status = match (true) {
                $i <= 10 => 'pending',
                $i <= 15 => 'sent',
                $i <= 18 => 'failed',
                default  => 'sending',
            };

            $this->db->insert('email_queue', [
                'recipient_email' => "recipient{$i}@example.test",
                'recipient_name'  => "Recipient $i",
                'subject'         => "ScoutKeeper Notification #$i",
                'body_html'       => "<p>This is test email #$i from ScoutKeeper.</p>",
                'body_text'       => "This is test email #$i from ScoutKeeper.",
                'status'          => $status,
                'attempts'        => $status === 'failed' ? 3 : ($status === 'sent' ? 1 : 0),
                'scheduled_at'    => $now,
                'sent_at'         => $status === 'sent' ? $now : null,
            ]);
        }
    }

    private function seedEmailPreferences(): void
    {
        // Give all members with user accounts email preferences
        foreach (array_slice($this->members, 0, 30, true) as $memberId) {
            $this->db->insert('member_email_preferences', [
                'member_id'   => $memberId,
                'email_type'  => 'general',
                'is_opted_in' => 1,
                'bounced'     => 0,
            ]);
        }
    }

    // ── Terms & Conditions ───────────────────────────────────────

    private function seedTerms(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $now = gmdate('Y-m-d H:i:s');

        $policyId = $this->db->insert('policies', [
            'name'        => 'Membership Terms and Conditions',
            'description' => 'Core membership agreement that every active member must accept.',
            'is_active'   => 1,
            'created_by'  => $adminUserId,
        ]);

        $termsId = $this->db->insert('terms_versions', [
            'policy_id'        => $policyId,
            'title'            => 'Membership Terms and Conditions',
            'content'          => '<h2>Membership Terms and Conditions</h2><p>By becoming a member of Scouts of Northland, you agree to abide by the Scout Promise and Law, the policies of this organisation, and the regulations of the World Organization of the Scout Movement.</p><p>Members are expected to attend regular meetings and activities, wear the correct uniform, and behave in a manner consistent with Scout values.</p><p>Personal data will be processed in accordance with our privacy policy and applicable data protection legislation.</p>',
            'version_number'   => '1.0',
            'is_published'     => 1,
            'published_at'     => $now,
            'grace_period_days'=> 14,
            'created_by'       => $adminUserId,
        ]);

        // Most users have accepted — except 'pending' user
        foreach ($this->users as $email => $userId) {
            if ($email === 'pending@northland.test') continue;
            $this->db->query(
                'INSERT IGNORE INTO terms_acceptances (terms_version_id, user_id, ip_address) VALUES (?, ?, ?)',
                [$termsId, $userId, '127.0.0.1']
            );
        }
    }

    // ── Notices ──────────────────────────────────────────────────

    private function seedNotices(): void
    {
        $adminUserId = $this->users['admin@northland.test'];

        $mustAck = $this->db->insert('notices', [
            'title'      => 'Updated Safeguarding Policy',
            'content'    => 'Please read and acknowledge the updated safeguarding policy. All leaders must complete the online refresher by 30 June 2026.',
            'type'       => 'must_acknowledge',
            'is_active'  => 1,
            'created_by' => $adminUserId,
        ]);

        $info = $this->db->insert('notices', [
            'title'      => 'System Maintenance Scheduled',
            'content'    => 'Routine maintenance is scheduled for Sunday 3am–5am. The system may be briefly unavailable.',
            'type'       => 'informational',
            'is_active'  => 1,
            'created_by' => $adminUserId,
        ]);

        // Most users acknowledged the must-ack notice
        foreach ($this->users as $email => $userId) {
            if ($email === 'pending@northland.test') continue;
            $this->db->query(
                'INSERT IGNORE INTO notice_acknowledgements (notice_id, user_id) VALUES (?, ?)',
                [$mustAck, $userId]
            );
        }
    }

    // ── Settings ─────────────────────────────────────────────────

    private function seedSettings(): void
    {
        $settings = [
            ['key' => 'org_name',            'value' => 'Scouts of Northland',  'group' => 'general'],
            ['key' => 'timezone',            'value' => 'Europe/London',        'group' => 'general'],
            ['key' => 'date_format',         'value' => 'd/m/Y',               'group' => 'general'],
            ['key' => 'self_registration',   'value' => '1',                    'group' => 'registration'],
            ['key' => 'waiting_list',        'value' => '1',                    'group' => 'registration'],
            ['key' => 'admin_approval',      'value' => '1',                    'group' => 'registration'],
            ['key' => 'session_timeout',     'value' => '3600',                 'group' => 'security'],
            ['key' => 'mfa_enforcement',     'value' => 'optional',             'group' => 'security'],
            ['key' => 'gdpr_enabled',        'value' => '1',                    'group' => 'privacy'],
            ['key' => 'gdpr_retention_days', 'value' => '2555',                 'group' => 'privacy'],
            ['key' => 'cron_mode',           'value' => 'pseudo',               'group' => 'system'],
        ];

        foreach ($settings as $s) {
            $this->db->query(
                'INSERT INTO settings (`key`, `value`, `group`) VALUES (:key, :value, :group)
                 ON DUPLICATE KEY UPDATE `value` = :value2, `group` = :group2',
                ['key' => $s['key'], 'value' => $s['value'], 'group' => $s['group'], 'value2' => $s['value'], 'group2' => $s['group']]
            );
        }
    }

    // ── Audit Log ────────────────────────────────────────────────

    private function seedAuditLog(): void
    {
        $adminUserId = $this->users['admin@northland.test'];
        $leaderUserId = $this->users['leader@northland.test'];

        $actions = [
            ['action' => 'create', 'entity_type' => 'member',      'entity_id' => 1],
            ['action' => 'update', 'entity_type' => 'member',      'entity_id' => 1],
            ['action' => 'create', 'entity_type' => 'event',       'entity_id' => 1],
            ['action' => 'create', 'entity_type' => 'article',     'entity_id' => 1],
            ['action' => 'update', 'entity_type' => 'settings',    'entity_id' => null],
            ['action' => 'create', 'entity_type' => 'role',        'entity_id' => 1],
            ['action' => 'login',  'entity_type' => 'user',        'entity_id' => 1],
            ['action' => 'create', 'entity_type' => 'org_node',    'entity_id' => 1],
            ['action' => 'update', 'entity_type' => 'member',      'entity_id' => 2],
            ['action' => 'delete', 'entity_type' => 'event',       'entity_id' => null],
        ];

        // Generate 100+ audit log entries
        for ($i = 0; $i < 110; $i++) {
            $a = $actions[$i % count($actions)];
            $userId = ($i % 3 === 0) ? $adminUserId : $leaderUserId;
            $ts = gmdate('Y-m-d H:i:s', strtotime("-{$i} hours"));

            $this->db->insert('audit_log', [
                'user_id'     => $userId,
                'action'      => $a['action'],
                'entity_type' => $a['entity_type'],
                'entity_id'   => $a['entity_id'],
                'old_values'  => $a['action'] === 'update' ? json_encode(['status' => 'pending']) : null,
                'new_values'  => $a['action'] !== 'delete' ? json_encode(['status' => 'active']) : null,
                'ip_address'  => '127.0.0.1',
                'user_agent'  => 'NorthlandSeeder/1.0',
                'created_at'  => $ts,
            ]);
        }
    }

    // ── Helper: node ID lookup ───────────────────────────────────

    private function getNodeId(string $name): int
    {
        foreach ($this->nodes as $key => $id) {
            if (strpos($key, $name) === 0) {
                return $id;
            }
        }
        throw new \RuntimeException("Node '$name' not found in seeder");
    }

    // ── Helper: member data generators ───────────────────────────

    private function getMemberNames(): array
    {
        // 155 culturally varied names
        return [
            ['James',    'Anderson'],     ['Sofia',    'Martinez'],     ['Liam',     'O\'Brien'],
            ['Amara',    'Okafor'],       ['Yusuf',    'Hassan'],       ['Mei',      'Chen'],
            ['Ethan',    'Williams'],     ['Fatima',   'Al-Rashid'],    ['Kenji',    'Tanaka'],
            ['Priya',    'Sharma'],       ['Lucas',    'Dubois'],       ['Aisha',    'Mohammed'],
            ['Noah',     'Johansson'],    ['Isla',     'MacLeod'],      ['Ravi',     'Patel'],
            ['Emma',     'Fitzgerald'],   ['Diego',    'Ruiz'],         ['Lily',     'Thompson'],
            ['Kofi',     'Mensah'],       ['Sanna',    'Virtanen'],     ['Max',      'Schmidt'],
            ['Zara',     'Khan'],         ['Oscar',    'Lindqvist'],    ['Chiara',   'Rossi'],
            ['Samuel',   'Nkomo'],        ['Elin',     'Bergström'],    ['Arjun',    'Gupta'],
            ['Hannah',   'Davies'],       ['Jin',      'Park'],         ['Olivia',   'Brown'],
            ['Tariq',    'Ibrahim'],      ['Freya',    'Jensen'],       ['Mateo',    'Lopez'],
            ['Sakura',   'Yamamoto'],     ['Elias',    'Petrov'],       ['Annika',   'Muller'],
            ['Felix',    'König'],        ['Naomi',    'Osei'],         ['Ivan',     'Volkov'],
            ['Rosa',     'Fernandez'],    ['Jasper',   'de Vries'],     ['Aditi',    'Reddy'],
            ['Leo',      'Andersen'],     ['Nia',      'Edwards'],      ['Marco',    'Bianchi'],
            ['Suki',     'Nakamura'],     ['Anders',   'Nilsson'],      ['Chloe',    'Martin'],
            ['Kwame',    'Asante'],       ['Ingrid',   'Olsen'],        ['Rafael',   'Silva'],
            ['Mira',     'Novak'],        ['Hugo',     'Larsson'],      ['Dina',     'Khalil'],
            ['Erik',     'Svensson'],     ['Leila',    'Abbasi'],       ['Tom',      'Miller'],
            ['Aaliya',   'Begum'],        ['Finn',     'Callahan'],     ['Esme',     'Dupont'],
            ['Raj',      'Kapoor'],       ['Marta',    'Kowalski'],     ['Luca',     'Moretti'],
            ['Yuki',     'Sato'],         ['Theo',     'Fischer'],      ['Nina',     'Ivanova'],
            ['Hassan',   'Diallo'],       ['Astrid',   'Holm'],         ['Dante',    'Romano'],
            ['Sienna',   'Taylor'],       ['Idris',    'Bah'],          ['Klara',    'Svoboda'],
            ['Miguel',   'Herrera'],      ['Ava',      'Wilson'],       ['Pierre',   'Lambert'],
            ['Nadia',    'Petrova'],      ['Jack',     'Sullivan'],     ['Elif',     'Yilmaz'],
            ['Soren',    'Madsen'],       ['Kaia',     'Haugen'],       ['Reuben',   'Grant'],
            ['Iris',     'Papadopoulos'], ['Axel',     'Eriksson'],     ['Malika',   'Toure'],
            ['Nils',     'Gustafsson'],   ['Lucia',    'Navarro'],      ['Owen',     'Hughes'],
            ['Amina',    'Farah'],        ['Gabriel',  'Almeida'],      ['Phoebe',   'Clarke'],
            ['Dmitri',   'Kozlov'],       ['Hana',     'Shimizu'],      ['Vincent',  'Leroy'],
            ['Selma',    'Lindgren'],     ['Caleb',    'Stewart'],      ['Yara',     'Mansour'],
            ['Stefan',   'Becker'],       ['Thea',     'Kristiansen'],  ['Emile',    'Bernard'],
            ['Rina',     'Watanabe'],     ['Tobias',   'Haas'],         ['Dalia',    'Nazari'],
            ['Magnus',   'Andreassen'],   ['Ivy',      'Chapman'],      ['Rami',     'Saeed'],
            ['Malin',    'Dahl'],         ['Ezra',     'Bennett'],      ['Farah',    'Jaber'],
            ['Simon',    'Kruger'],       ['Tessa',    'Visser'],       ['Ali',      'Rahman'],
            ['Lena',     'Wolf'],         ['Victor',   'Morin'],        ['Sadia',    'Hussain'],
            ['Emil',     'Strand'],       ['Grace',    'O\'Connor'],    ['Karim',    'Bouzid'],
            ['Julia',    'Engström'],     ['Adrian',   'Costa'],        ['Lydia',    'Palmer'],
            ['Osman',    'Yusuf'],        ['Anna',     'Lundberg'],     ['Daniel',   'Reis'],
            ['Maia',     'Hagen'],        ['Henrik',   'Lund'],         ['Eva',      'Santos'],
            ['Ibrahim',  'Amir'],         ['Clara',    'Neumann'],      ['Ruben',    'Vega'],
            ['Hilde',    'Moen'],         ['Alexander','Popov'],        ['Noor',     'Ahmed'],
            ['Lars',     'Bakken'],       ['Elodie',   'Gauthier'],     ['Tarquin',  'Frost'],
            ['Sigrid',   'Berge'],        ['Marcus',   'Weiss'],        ['Layla',    'Othman'],
            ['Olaf',     'Pedersen'],     ['Carmen',   'Ortega'],       ['Roland',   'Hartmann'],
            ['Vera',     'Kovalenko'],    ['Anton',    'Borg'],
        ];
    }

    private function getMemberStatuses(): array
    {
        // 155 statuses: 120 active, 15 pending, 5 suspended, 10 inactive, 5 left
        $statuses = array_merge(
            array_fill(0, 120, 'active'),
            array_fill(0, 15, 'pending'),
            array_fill(0, 5, 'suspended'),
            array_fill(0, 10, 'inactive'),
            array_fill(0, 5, 'left')
        );
        return $statuses;
    }

    private function pickGender(int $i): string
    {
        $genders = ['male', 'female', 'other', 'prefer_not_to_say'];
        // Roughly 48% male, 48% female, 2% other, 2% prefer_not_to_say
        if ($i % 50 < 24) return 'male';
        if ($i % 50 < 48) return 'female';
        if ($i % 50 < 49) return 'other';
        return 'prefer_not_to_say';
    }

    private function generateDob(int $i): string
    {
        // Ages 6–65 spread across members
        $age = 6 + ($i * 59 / 155);
        $year = (int)(2026 - $age);
        $month = (($i * 7) % 12) + 1;
        $day = (($i * 13) % 28) + 1;
        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function generateJoinDate(int $i): string
    {
        // Join dates spread 2015–2025
        $year = 2015 + ($i % 11);
        $month = (($i * 3) % 12) + 1;
        return sprintf('%04d-%02d-01', $year, $month);
    }

    private function pickStreet(int $i): string
    {
        $streets = ['Oak Lane', 'Maple Drive', 'Pine Road', 'Birch Avenue', 'Cedar Close', 'Elm Street', 'Willow Way', 'Ash Crescent', 'Beech Grove', 'Holly Road'];
        return $streets[$i % count($streets)];
    }

    private function pickCity(int $i): string
    {
        $cities = ['Frostdale', 'Pinewood', 'Coastview', 'Northhaven', 'Lakeside', 'Ridgeway'];
        return $cities[$i % count($cities)];
    }

    private function pickSchool(int $i): string
    {
        $schools = ['Frostdale Primary', 'Northland Academy', 'Coastview High', 'Pinewood Grammar', 'St Mary\'s School', 'Lakeside Comprehensive'];
        return $schools[$i % count($schools)];
    }
}
