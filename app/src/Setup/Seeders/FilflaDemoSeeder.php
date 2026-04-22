<?php

declare(strict_types=1);

namespace Tests\Seeders;

use App\Core\Database;

/**
 * Large-scale demo seeder — "Scout Association of Filfla".
 *
 * Builds a realistic stress-test organisation with:
 *   - 1 National body, 7 Regions, 28 Districts, ~200 Groups, ~800 Sections
 *   - ~25,000 youth members (Beavers/Cubs/Scouts/Ventures)
 *   - ~5,000 adult leaders with login accounts
 *   - Section leader + assistant structure per section
 *   - Events, articles, achievements, custom fields, settings
 *
 * Wipes and re-creates everything. Batched inserts for speed.
 * Expected runtime: 2–5 minutes on local XAMPP.
 */
class FilflaDemoSeeder
{
    public const SHARED_PASSWORD = 'Demo123!';
    public const ORG_NAME = 'Scout Association of Filfla';

    private Database $db;
    private string $passwordHash;
    private $progressCallback = null;
    private string $adminEmail = 'admin@filfla.test';
    private ?string $adminPasswordHash = null;
    private string $adminFirstName = 'Demo';
    private string $adminSurname = 'Administrator';

    /** @var array<string,int> levelType name => id */
    private array $levelTypes = [];
    /** @var array<string,int> roleName => id */
    private array $roles = [];
    /** @var int root national node id */
    private int $natId = 0;
    /** @var int[] region node ids */
    private array $regionIds = [];
    /** @var int[] district node ids */
    private array $districtIds = [];
    /** @var int[] group node ids */
    private array $groupIds = [];
    /** @var array<int,array{beaver:int,cub:int,scout:int,venture:int}> groupId => section ids */
    private array $groupSections = [];

    private int $adminUserId = 0;
    private int $memberSeq = 0;
    private int $userSeq = 0;

    public function __construct(Database $db)
    {
        $this->db = $db;
        $this->passwordHash = password_hash(self::SHARED_PASSWORD, PASSWORD_BCRYPT);
    }

    public function setProgressCallback(callable $cb): void
    {
        $this->progressCallback = $cb;
    }

    /**
     * Override the admin account details (for use from the setup wizard so the
     * installing user can still log in with the credentials they entered).
     */
    public function setAdminOverride(string $email, string $passwordHash, string $firstName, string $surname): void
    {
        $this->adminEmail = $email;
        $this->adminPasswordHash = $passwordHash;
        $this->adminFirstName = $firstName;
        $this->adminSurname = $surname;
    }

    private function progress(string $msg): void
    {
        if ($this->progressCallback !== null) {
            ($this->progressCallback)($msg);
        } else {
            echo "  $msg\n";
        }
    }

    public function run(): void
    {
        $this->truncateAll();

        // Reset auto_increment on tables where we rely on sequential IDs
        $this->db->query("ALTER TABLE members AUTO_INCREMENT = 1");
        $this->db->query("ALTER TABLE users AUTO_INCREMENT = 1");

        $this->progress('Seeding level types…');
        $this->seedLevelTypes();

        $this->progress('Seeding roles…');
        $this->seedRoles();

        $this->progress('Seeding org tree (1 national, 7 regions, 28 districts)…');
        $this->seedTopTree();

        $this->progress('Seeding groups and sections…');
        $this->seedGroupsAndSections();

        $this->progress('Seeding admin user…');
        $this->seedAdminUser();

        $this->progress('Seeding youth members…');
        $this->seedYouthMembers();

        $this->progress('Seeding adult leaders + user accounts…');
        $this->seedAdultMembers();

        $this->progress('Seeding custom fields…');
        $this->seedCustomFields();

        $this->progress('Seeding achievements…');
        $this->seedAchievements();

        $this->progress('Seeding events…');
        $this->seedEvents();

        $this->progress('Seeding articles…');
        $this->seedArticles();

        $this->progress('Seeding settings, policies, notices…');
        $this->seedSettingsAndPolicies();

        $this->progress('Done.');
    }

    // ── Truncation ───────────────────────────────────────────────

    private function truncateAll(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $tables = [
            'notice_acknowledgements', 'notices',
            'terms_acceptances', 'terms_versions', 'policies',
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
            try {
                $this->db->query("TRUNCATE TABLE `$t`");
            } catch (\Throwable) {
                // Table might not exist in older schemas — skip.
            }
        }
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    // ── Level types ──────────────────────────────────────────────

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

    // ── Roles ────────────────────────────────────────────────────

    private function seedRoles(): void
    {
        $flat = static function (array $modules): string {
            $out = [];
            foreach ($modules as $m => $actions) {
                foreach ($actions as $a) {
                    $out["$m.$a"] = true;
                }
            }
            return json_encode($out);
        };

        $roles = [
            'Super Admin' => [
                'description' => 'Full system access',
                'permissions' => $flat([
                    'members' => ['read', 'write'], 'org_structure' => ['read', 'write'],
                    'admin' => ['dashboard', 'reports', 'terms', 'notices', 'settings', 'audit', 'logs', 'export', 'backup', 'languages', 'updates', 'monitoring'],
                    'communications' => ['read', 'write'], 'events' => ['read', 'write'],
                    'achievements' => ['read', 'write'], 'directory' => ['read'],
                    'custom_fields' => ['write'], 'registrations' => ['manage'],
                ]),
                'can_publish_events' => 1, 'can_access_medical' => 1, 'can_access_financial' => 1,
                'is_directory_visible' => 1, 'is_system' => 1,
            ],
            'Regional Commissioner' => [
                'description' => 'Oversees a Region',
                'permissions' => $flat(['members' => ['read'], 'org_structure' => ['read'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'directory' => ['read']]),
                'can_publish_events' => 1, 'can_access_medical' => 0, 'can_access_financial' => 0,
                'is_directory_visible' => 1, 'is_system' => 0,
            ],
            'District Commissioner' => [
                'description' => 'Oversees a District',
                'permissions' => $flat(['members' => ['read'], 'org_structure' => ['read'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'directory' => ['read']]),
                'can_publish_events' => 1, 'can_access_medical' => 0, 'can_access_financial' => 0,
                'is_directory_visible' => 1, 'is_system' => 0,
            ],
            'Group Scout Leader' => [
                'description' => 'Leads a Scout Group',
                'permissions' => $flat(['members' => ['read', 'write'], 'org_structure' => ['read'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read'], 'registrations' => ['manage']]),
                'can_publish_events' => 1, 'can_access_medical' => 1, 'can_access_financial' => 0,
                'is_directory_visible' => 1, 'is_system' => 0,
            ],
            'Assistant Group Scout Leader' => [
                'description' => 'Assistant to the Group Scout Leader',
                'permissions' => $flat(['members' => ['read', 'write'], 'communications' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read']]),
                'can_publish_events' => 1, 'can_access_medical' => 1, 'can_access_financial' => 0,
                'is_directory_visible' => 1, 'is_system' => 0,
            ],
            'Beaver Scout Leader' => ['desc' => 'Leads the Beaver Colony'],
            'Assistant Beaver Scout Leader' => ['desc' => 'Assistant to the Beaver Scout Leader'],
            'Cub Scout Leader' => ['desc' => 'Leads the Cub Pack'],
            'Assistant Cub Scout Leader' => ['desc' => 'Assistant to the Cub Scout Leader'],
            'Scout Leader' => ['desc' => 'Leads the Scout Troop'],
            'Assistant Scout Leader' => ['desc' => 'Assistant to the Scout Leader'],
            'Venture Scout Leader' => ['desc' => 'Leads the Venture Unit'],
            'Assistant Venture Scout Leader' => ['desc' => 'Assistant to the Venture Scout Leader'],
            'Member' => [
                'description' => 'Youth member',
                'permissions' => $flat(['directory' => ['read'], 'events' => ['read']]),
                'can_publish_events' => 0, 'can_access_medical' => 0, 'can_access_financial' => 0,
                'is_directory_visible' => 0, 'is_system' => 1,
            ],
        ];

        // Shorthand section-leader permission template
        $sectionPerms = $flat(['members' => ['read', 'write'], 'events' => ['read', 'write'], 'achievements' => ['read', 'write'], 'directory' => ['read']]);
        $assistantPerms = $flat(['members' => ['read'], 'events' => ['read', 'write'], 'achievements' => ['read'], 'directory' => ['read']]);

        foreach ($roles as $name => $spec) {
            if (isset($spec['desc'])) {
                $isAssistant = str_starts_with($name, 'Assistant');
                $spec = [
                    'description' => $spec['desc'],
                    'permissions' => $isAssistant ? $assistantPerms : $sectionPerms,
                    'can_publish_events' => 1,
                    'can_access_medical' => 1,
                    'can_access_financial' => 0,
                    'is_directory_visible' => 1,
                    'is_system' => 0,
                ];
            }
            $spec['name'] = $name;
            $this->roles[$name] = $this->db->insert('roles', $spec);
        }
    }

    // ── Top-level org tree ───────────────────────────────────────

    private function seedTopTree(): void
    {
        $this->natId = $this->createNode(null, 'National Organisation', self::ORG_NAME, 'SAF');

        $regions = [
            ['Northern Region',    'NR'],
            ['Southern Region',    'SR'],
            ['Eastern Region',     'ER'],
            ['Western Region',     'WR'],
            ['Central Region',     'CR'],
            ['Coastal Region',     'CoR'],
            ['Gozo Region',        'GR'],
        ];
        foreach ($regions as $r) {
            $this->regionIds[] = $this->createNode($this->natId, 'Region', $r[0], $r[1]);
        }

        // 4 districts per region = 28 districts
        $districtNames = ['North', 'South', 'East', 'West'];
        foreach ($this->regionIds as $ri => $regionId) {
            foreach ($districtNames as $di => $dn) {
                $rName = $regions[$ri][0];
                $short = $regions[$ri][1] . '-' . $dn[0];
                $this->districtIds[] = $this->createNode($regionId, 'District', "$dn $rName District", $short);
            }
        }
    }

    // ── Groups and sections ──────────────────────────────────────

    private function seedGroupsAndSections(): void
    {
        // Distribute ~200 groups across 28 districts.
        // Vary 6–9 groups per district → roughly 200 total.
        $totalGroups = 0;
        foreach ($this->districtIds as $di => $districtId) {
            $groupCount = 6 + ($di % 4); // 6,7,8,9,6,7,8,9,…
            for ($g = 1; $g <= $groupCount; $g++) {
                $totalGroups++;
                $groupName = $totalGroups . $this->ordinalSuffix($totalGroups) . ' ' . $this->groupTownName($totalGroups);
                $short = 'G' . $totalGroups;
                $groupId = $this->createNode($districtId, 'Group', $groupName, $short);
                $this->groupIds[] = $groupId;

                // Each group has four sections
                $bId = $this->createNode($groupId, 'Section', 'Beaver Colony', 'B', 6, 8);
                $cId = $this->createNode($groupId, 'Section', 'Cub Pack',      'C', 8, 11);
                $sId = $this->createNode($groupId, 'Section', 'Scout Troop',   'S', 11, 14);
                $vId = $this->createNode($groupId, 'Section', 'Venture Unit',  'V', 14, 18);
                $this->groupSections[$groupId] = [
                    'beaver'  => $bId,
                    'cub'     => $cId,
                    'scout'   => $sId,
                    'venture' => $vId,
                ];
            }
        }
        $this->progress("  Created $totalGroups groups, " . ($totalGroups * 4) . " sections");
    }

    private function createNode(?int $parentId, string $levelType, string $name, string $shortName, ?int $ageMin = null, ?int $ageMax = null): int
    {
        $data = [
            'parent_id'     => $parentId,
            'level_type_id' => $this->levelTypes[$levelType],
            'name'          => $name,
            'short_name'    => $shortName,
            'sort_order'    => 0,
            'is_active'     => 1,
        ];
        if ($ageMin !== null) {
            $data['age_group_min'] = $ageMin;
            $data['age_group_max'] = $ageMax;
        }

        $nodeId = $this->db->insert('org_nodes', $data);

        if ($parentId === null) {
            $this->db->insert('org_closure', ['ancestor_id' => $nodeId, 'descendant_id' => $nodeId, 'depth' => 0]);
        } else {
            $this->db->query(
                'INSERT INTO org_closure (ancestor_id, descendant_id, depth)
                 SELECT ancestor_id, :newNode, depth + 1
                 FROM org_closure WHERE descendant_id = :parent',
                ['newNode' => $nodeId, 'parent' => $parentId]
            );
            $this->db->insert('org_closure', ['ancestor_id' => $nodeId, 'descendant_id' => $nodeId, 'depth' => 0]);
        }

        return $nodeId;
    }

    // ── Admin user ───────────────────────────────────────────────

    private function seedAdminUser(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->adminUserId = $this->db->insert('users', [
            'email' => $this->adminEmail,
            'password_hash' => $this->adminPasswordHash ?? $this->passwordHash,
            'is_super_admin' => 1,
            'is_active' => 1,
            'password_changed_at' => $now,
        ]);

        $memberId = $this->db->insert('members', [
            'membership_number' => 'SAF-000001',
            'user_id'     => $this->adminUserId,
            'first_name'  => $this->adminFirstName,
            'surname'     => $this->adminSurname,
            'dob'         => '1980-01-15',
            'gender'      => 'prefer_not_to_say',
            'email'       => $this->adminEmail,
            'status'      => 'active',
            'joined_date' => '2015-01-01',
            'country'     => 'Filfla',
            'gdpr_consent'=> 1,
            'created_at'  => $now,
        ]);
        $this->db->insert('member_nodes', ['member_id' => $memberId, 'node_id' => $this->natId, 'is_primary' => 1]);

        // Assign Super Admin role at national level
        $assignId = $this->db->insert('role_assignments', [
            'user_id'      => $this->adminUserId,
            'role_id'      => $this->roles['Super Admin'],
            'context_type' => 'node',
            'context_id'   => $this->natId,
            'start_date'   => '2015-01-01',
            'assigned_by'  => $this->adminUserId,
        ]);
        $this->db->insert('role_assignment_scopes', ['assignment_id' => $assignId, 'node_id' => $this->natId]);

        $this->memberSeq = 1;
        $this->userSeq = 1;
    }

    // ── Youth members ────────────────────────────────────────────

    private function seedYouthMembers(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $thisYear = (int)date('Y');

        $memberBatch = [];
        $memberNodeBatch = [];
        $totalYouth = 0;

        // Age band boundaries
        $ageBands = [
            'beaver'  => [6, 8],
            'cub'     => [8, 11],
            'scout'   => [11, 14],
            'venture' => [14, 18],
        ];
        $split = ['beaver' => 0.25, 'cub' => 0.30, 'scout' => 0.35, 'venture' => 0.10];

        foreach ($this->groupIds as $gIdx => $groupId) {
            // Group size: 50-200, weighted toward mean ~125
            $groupSize = 80 + mt_rand(0, 90); // 80-170 roughly; averages near 125
            if (mt_rand(0, 99) < 10) $groupSize = mt_rand(50, 79);   // 10% small
            if (mt_rand(0, 99) < 10) $groupSize = mt_rand(170, 200); // 10% large

            foreach ($ageBands as $section => $ageRange) {
                $count = (int)round($groupSize * $split[$section]);
                // Ventures: clamp 5–21
                if ($section === 'venture') {
                    $count = max(5, min(21, $count));
                }
                $sectionNodeId = $this->groupSections[$groupId][$section];

                for ($i = 0; $i < $count; $i++) {
                    $totalYouth++;
                    $this->memberSeq++;
                    $gender = mt_rand(0, 1) === 0 ? 'male' : 'female';
                    $first = $this->pickFirstName($gender, $this->memberSeq);
                    $surname = $this->pickSurname($this->memberSeq);
                    $ageYears = mt_rand($ageRange[0], $ageRange[1] - 1);
                    $birthYear = $thisYear - $ageYears - (mt_rand(0, 1));
                    $birthMonth = mt_rand(1, 12);
                    $birthDay = mt_rand(1, 28);
                    $dob = sprintf('%04d-%02d-%02d', $birthYear, $birthMonth, $birthDay);
                    $joinYear = max($thisYear - mt_rand(0, 3), $birthYear + $ageRange[0]);

                    $memberBatch[] = [
                        sprintf('SAF-%06d', $this->memberSeq),
                        null, // user_id
                        $first,
                        $surname,
                        $dob,
                        $gender,
                        strtolower("$first.$surname.{$this->memberSeq}@demo.filfla.test"),
                        sprintf('+356 %04d %04d', mt_rand(2000, 9999), mt_rand(1000, 9999)),
                        mt_rand(1, 999) . ' ' . $this->pickStreet($this->memberSeq),
                        $this->pickCity($this->memberSeq),
                        sprintf('FL%04d', mt_rand(1000, 9999)),
                        'Filfla',
                        'active',
                        sprintf('%04d-09-01', $joinYear),
                        1,
                        $now,
                    ];

                    // memberNodeBatch uses memberSeq as placeholder for now — we'll
                    // translate to real IDs after flushing member inserts.
                    $memberNodeBatch[] = [$this->memberSeq, $sectionNodeId];

                    if (count($memberBatch) >= 1000) {
                        $this->flushMembers($memberBatch, $memberNodeBatch);
                        $memberBatch = [];
                        $memberNodeBatch = [];
                        if ($totalYouth % 5000 === 0) {
                            $this->progress("  Inserted $totalYouth youth members…");
                        }
                    }
                }
            }
        }

        if (!empty($memberBatch)) {
            $this->flushMembers($memberBatch, $memberNodeBatch);
        }
        $this->progress("  Total youth: $totalYouth");
    }

    /**
     * Flush a batch of members + their section assignments.
     * Uses LAST_INSERT_ID() trick to map member sequence numbers → real IDs.
     */
    private function flushMembers(array $members, array $memberNodes): void
    {
        if (empty($members)) return;

        $cols = ['membership_number', 'user_id', 'first_name', 'surname', 'dob', 'gender',
                 'email', 'phone', 'address_line1', 'city', 'postcode', 'country', 'status',
                 'joined_date', 'gdpr_consent', 'created_at'];
        $firstId = $this->bulkInsert('members', $cols, $members);

        $rows = [];
        foreach ($memberNodes as $i => [$seq, $sectionNodeId]) {
            $memberId = $firstId + $i;
            $rows[] = [$memberId, $sectionNodeId, 1];
        }
        $this->bulkInsert('member_nodes', ['member_id', 'node_id', 'is_primary'], $rows);
    }

    // ── Adult leaders ────────────────────────────────────────────

    private function seedAdultMembers(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $thisYear = (int)date('Y');

        $memberBatch = [];
        $userBatch = [];
        $nodeBatch = [];
        $assignBatch = []; // [memberSeqInBatch, roleName, contextType, contextId, scopeNodeId]

        $totalAdults = 0;

        // For each group, generate leaders per ratio
        foreach ($this->groupIds as $groupId) {
            $sections = $this->groupSections[$groupId];

            // Count youth per section (for ratio calc)
            $youthCounts = $this->db->fetchAll(
                "SELECT node_id, COUNT(*) AS c FROM member_nodes WHERE node_id IN (?, ?, ?, ?) GROUP BY node_id",
                [$sections['beaver'], $sections['cub'], $sections['scout'], $sections['venture']]
            );
            $counts = [];
            foreach ($youthCounts as $row) {
                $counts[(int)$row['node_id']] = (int)$row['c'];
            }

            $sectionSpecs = [
                ['beaver',  1, 5, 'Beaver Scout Leader',  'Assistant Beaver Scout Leader'],
                ['cub',     1, 6, 'Cub Scout Leader',     'Assistant Cub Scout Leader'],
                ['scout',   1, 8, 'Scout Leader',         'Assistant Scout Leader'],
            ];
            foreach ($sectionSpecs as [$key, $lead, $ratio, $slRole, $aslRole]) {
                $nodeId = $sections[$key];
                $youthCount = $counts[$nodeId] ?? 0;
                $leadersNeeded = max($lead + 1, (int)ceil($youthCount / $ratio));
                // 1 section leader + (leadersNeeded-1) assistants
                $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, $slRole, $nodeId, $now, $thisYear);
                for ($a = 1; $a < $leadersNeeded; $a++) {
                    $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, $aslRole, $nodeId, $now, $thisYear);
                }
                $totalAdults += $leadersNeeded;
            }

            // Ventures: 1 VSL + 1-2 assistants (2 if venture has >12 youth)
            $ventureNode = $sections['venture'];
            $ventureCount = $counts[$ventureNode] ?? 0;
            $ventureAssistants = $ventureCount > 12 ? 2 : 1;
            $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Venture Scout Leader', $ventureNode, $now, $thisYear);
            for ($a = 0; $a < $ventureAssistants; $a++) {
                $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Assistant Venture Scout Leader', $ventureNode, $now, $thisYear);
            }
            $totalAdults += 1 + $ventureAssistants;

            // Group-level: 1 GSL + 1 AGSL + 0-2 extras
            $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Group Scout Leader', $groupId, $now, $thisYear);
            $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Assistant Group Scout Leader', $groupId, $now, $thisYear);
            $extras = mt_rand(0, 2);
            for ($x = 0; $x < $extras; $x++) {
                $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Assistant Group Scout Leader', $groupId, $now, $thisYear);
            }
            $totalAdults += 2 + $extras;

            if (count($memberBatch) >= 1000) {
                $this->flushAdults($memberBatch, $userBatch, $nodeBatch, $assignBatch);
                $memberBatch = $userBatch = $nodeBatch = $assignBatch = [];
                if ($totalAdults % 1000 < 50) {
                    $this->progress("  Inserted ~$totalAdults adult leaders…");
                }
            }
        }

        // District Commissioners
        foreach ($this->districtIds as $districtId) {
            $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'District Commissioner', $districtId, $now, $thisYear);
            $totalAdults++;
        }
        // Regional Commissioners
        foreach ($this->regionIds as $regionId) {
            $this->addLeader($memberBatch, $userBatch, $nodeBatch, $assignBatch, 'Regional Commissioner', $regionId, $now, $thisYear);
            $totalAdults++;
        }

        if (!empty($memberBatch)) {
            $this->flushAdults($memberBatch, $userBatch, $nodeBatch, $assignBatch);
        }
        $this->progress("  Total adult leaders: $totalAdults");
    }

    private function addLeader(array &$memberBatch, array &$userBatch, array &$nodeBatch, array &$assignBatch, string $roleName, int $nodeId, string $now, int $thisYear): void
    {
        $this->memberSeq++;
        $this->userSeq++;
        $gender = mt_rand(0, 1) === 0 ? 'male' : 'female';
        $first = $this->pickFirstName($gender, $this->memberSeq);
        $surname = $this->pickSurname($this->memberSeq);
        $ageYears = mt_rand(22, 60);
        $birthYear = $thisYear - $ageYears;
        $dob = sprintf('%04d-%02d-%02d', $birthYear, mt_rand(1, 12), mt_rand(1, 28));
        $joinYear = $thisYear - mt_rand(1, 15);
        $email = sprintf('leader%06d@demo.filfla.test', $this->userSeq);

        $userBatch[] = [
            $email, $this->passwordHash, 0, 1, $now,
        ];
        $memberBatch[] = [
            sprintf('SAF-%06d', $this->memberSeq),
            // user_id populated after user insert (null placeholder — patched in flushAdults)
            null,
            $first,
            $surname,
            $dob,
            $gender,
            $email,
            sprintf('+356 %04d %04d', mt_rand(2000, 9999), mt_rand(1000, 9999)),
            mt_rand(1, 999) . ' ' . $this->pickStreet($this->memberSeq),
            $this->pickCity($this->memberSeq),
            sprintf('FL%04d', mt_rand(1000, 9999)),
            'Filfla',
            'active',
            sprintf('%04d-09-01', $joinYear),
            1,
            $now,
        ];
        $nodeBatch[] = $nodeId;
        $assignBatch[] = [$roleName, $nodeId, sprintf('%04d-09-01', $joinYear)];
    }

    private function flushAdults(array $members, array $users, array $nodes, array $assigns): void
    {
        if (empty($users)) return;

        // 1. Insert users — get first user id
        $firstUserId = $this->bulkInsert('users', ['email', 'password_hash', 'is_super_admin', 'is_active', 'password_changed_at'], $users);

        // 2. Patch user_id into each member row (col index 1)
        foreach ($members as $i => &$row) {
            $row[1] = $firstUserId + $i;
        }
        unset($row);

        $cols = ['membership_number', 'user_id', 'first_name', 'surname', 'dob', 'gender',
                 'email', 'phone', 'address_line1', 'city', 'postcode', 'country', 'status',
                 'joined_date', 'gdpr_consent', 'created_at'];
        $firstMemberId = $this->bulkInsert('members', $cols, $members);

        // 3. member_nodes
        $nodeRows = [];
        foreach ($nodes as $i => $nodeId) {
            $nodeRows[] = [$firstMemberId + $i, $nodeId, 1];
        }
        $this->bulkInsert('member_nodes', ['member_id', 'node_id', 'is_primary'], $nodeRows);

        // 4. role_assignments + scopes
        $assignRows = [];
        foreach ($assigns as $i => [$roleName, $contextId, $startDate]) {
            $assignRows[] = [
                $firstUserId + $i,
                $this->roles[$roleName],
                'node',
                $contextId,
                $startDate,
                null,
                $this->adminUserId,
            ];
        }
        $firstAssignId = $this->bulkInsert('role_assignments',
            ['user_id', 'role_id', 'context_type', 'context_id', 'start_date', 'end_date', 'assigned_by'],
            $assignRows);

        $scopeRows = [];
        foreach ($assigns as $i => [$roleName, $contextId, $startDate]) {
            $scopeRows[] = [$firstAssignId + $i, $contextId];
        }
        $this->bulkInsert('role_assignment_scopes', ['assignment_id', 'node_id'], $scopeRows);
    }

    // ── Custom fields ────────────────────────────────────────────

    private function seedCustomFields(): void
    {
        $fields = [
            ['field_key' => 'uniform_size',     'field_type' => 'dropdown',   'label' => 'Uniform Size',    'is_required' => 0, 'validation_rules' => json_encode(['options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']]), 'display_group' => 'additional', 'sort_order' => 1, 'is_active' => 1],
            ['field_key' => 'allergies',        'field_type' => 'long_text',  'label' => 'Allergies',       'is_required' => 0, 'display_group' => 'medical',    'sort_order' => 2, 'is_active' => 1],
            ['field_key' => 'emergency_phone',  'field_type' => 'short_text', 'label' => 'Emergency Phone', 'is_required' => 1, 'display_group' => 'contact',    'sort_order' => 3, 'is_active' => 1],
            ['field_key' => 'swimming_ability', 'field_type' => 'dropdown',   'label' => 'Swimming Ability','is_required' => 0, 'validation_rules' => json_encode(['options' => ['None', 'Basic', 'Competent', 'Strong']]), 'display_group' => 'additional', 'sort_order' => 4, 'is_active' => 1],
            ['field_key' => 'school_name',      'field_type' => 'short_text', 'label' => 'School Name',     'is_required' => 0, 'display_group' => 'additional', 'sort_order' => 5, 'is_active' => 1],
            ['field_key' => 'dietary_needs',    'field_type' => 'short_text', 'label' => 'Dietary Needs',   'is_required' => 0, 'display_group' => 'medical',    'sort_order' => 6, 'is_active' => 1],
        ];
        foreach ($fields as $f) {
            $this->db->insert('custom_field_definitions', $f);
        }
    }

    // ── Achievements ─────────────────────────────────────────────

    private function seedAchievements(): void
    {
        $defs = [
            ['Woodcraft Badge',             'achievement', 'Bushcraft, fire-lighting, shelter building.'],
            ['Navigation Badge',            'achievement', 'Map reading, compass use, orienteering.'],
            ['First Aid Badge',             'achievement', 'Emergency first aid knowledge and skills.'],
            ['Swimmer Badge',               'achievement', 'Swimming proficiency and water safety.'],
            ['Cyclist Badge',               'achievement', 'Bicycle maintenance and safe riding.'],
            ['Cook Badge',                  'achievement', 'Camp cooking and nutrition.'],
            ['Community Service Award',     'achievement', 'Sustained community service.'],
            ['Chief Scout Award',           'achievement', 'Highest youth achievement.'],
            ['Safeguarding Training',       'training',    'Mandatory safeguarding and child protection training.'],
            ['Leadership Skills Course',    'training',    'National leadership development programme.'],
            ['First Aid Instructor',        'training',    'Qualified to teach first aid.'],
            ['Wood Badge',                  'training',    'Advanced adult leader training.'],
        ];
        foreach ($defs as $i => [$name, $cat, $desc]) {
            $this->db->insert('achievement_definitions', [
                'name' => $name, 'category' => $cat, 'description' => $desc,
                'is_active' => 1, 'sort_order' => $i + 1,
            ]);
        }
    }

    // ── Events ───────────────────────────────────────────────────

    private function seedEvents(): void
    {
        $thisYear = (int)date('Y');
        $events = [
            ['National AGM',              '+1 month',  '+1 month 7 hours',   0, $this->natId],
            ['Founders Day',              '+2 months', '+2 months 6 hours',  0, $this->natId],
            ['Summer National Jamboree',  '+3 months', '+3 months 7 days',   1, $this->natId],
            ['Leadership Training Week',  '+4 months', '+4 months 5 days',   1, $this->natId],
            ['St George\'s Day Parade',   '-1 month',  '-1 month 3 hours',   0, $this->natId],
            ['National Census Deadline',  '-2 months', '-2 months 1 day',    1, $this->natId],
        ];
        foreach ($events as [$title, $start, $end, $allDay, $node]) {
            $this->db->insert('events', [
                'title' => $title,
                'description' => "National-level event: $title",
                'location' => 'National HQ',
                'start_date' => gmdate('Y-m-d H:i:s', strtotime($start)),
                'end_date' => gmdate('Y-m-d H:i:s', strtotime($end)),
                'all_day' => $allDay,
                'node_scope_id' => $node,
                'created_by' => $this->adminUserId,
                'is_published' => 1,
            ]);
        }

        // Generate ~3 events per region (21)
        $eventTitles = ['Regional Camp', 'Swimming Gala', 'Orienteering Challenge', 'Service Day'];
        foreach ($this->regionIds as $ri => $regionId) {
            foreach (array_slice($eventTitles, 0, 3) as $ti => $title) {
                $offset = ($ri * 7 + $ti * 3) . ' days';
                $this->db->insert('events', [
                    'title' => "$title (Region $ri)",
                    'description' => "Regional $title",
                    'location' => 'Regional Centre',
                    'start_date' => gmdate('Y-m-d H:i:s', strtotime("+$offset")),
                    'end_date' => gmdate('Y-m-d H:i:s', strtotime("+$offset +6 hours")),
                    'all_day' => 0,
                    'node_scope_id' => $regionId,
                    'created_by' => $this->adminUserId,
                    'is_published' => 1,
                ]);
            }
        }
    }

    // ── Articles ─────────────────────────────────────────────────

    private function seedArticles(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $articles = [
            ['Welcome to Scout Association of Filfla', 'Welcome message to all members of the Scout Association of Filfla.', 'members'],
            ['Summer Camp Registration Open', 'Registration for this year\'s summer camp is now open.', 'members'],
            ['New Safeguarding Policy', 'Updated safeguarding policy — all leaders must review and acknowledge.', 'members'],
            ['Annual Report Published', 'The annual report is now available.', 'public'],
            ['Founders Day Celebrations', 'Join us for Founders Day celebrations across all regions.', 'members'],
            ['Volunteer of the Year Awards', 'Nominations for the Volunteer of the Year awards are now open.', 'members'],
            ['Regional Commissioner Appointments', 'New Regional Commissioners have been appointed.', 'members'],
            ['First Aid Training Schedule', 'First aid training sessions scheduled across all regions.', 'members'],
            ['Membership Census Reminder', 'Reminder to Group Scout Leaders to submit membership census.', 'members'],
            ['IT Maintenance Window', 'Scheduled IT maintenance this weekend.', 'portal'],
        ];
        foreach ($articles as [$title, $body, $visibility]) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $title));
            $slug = trim($slug, '-');
            $this->db->insert('articles', [
                'title'        => $title,
                'slug'         => $slug,
                'body'         => '<p>' . htmlspecialchars($body) . '</p>',
                'excerpt'      => substr($body, 0, 200),
                'visibility'   => $visibility,
                'is_published' => 1,
                'published_at' => $now,
                'author_id'    => $this->adminUserId,
            ]);
        }
    }

    // ── Settings, policies, notices ──────────────────────────────

    private function seedSettingsAndPolicies(): void
    {
        $settings = [
            ['org_name',            self::ORG_NAME,   'general'],
            ['timezone',            'Europe/Malta',   'general'],
            ['date_format',         'd/m/Y',          'general'],
            ['self_registration',   '1',              'registration'],
            ['waiting_list',        '1',              'registration'],
            ['admin_approval',      '1',              'registration'],
            ['session_timeout',     '3600',           'security'],
            ['mfa_enforcement',     'optional',       'security'],
            ['gdpr_enabled',        '1',              'privacy'],
            ['gdpr_retention_days', '2555',           'privacy'],
            ['cron_mode',           'pseudo',         'system'],
        ];
        foreach ($settings as [$k, $v, $g]) {
            $this->db->query(
                'INSERT INTO settings (`key`, `value`, `group`) VALUES (:k, :v, :g)
                 ON DUPLICATE KEY UPDATE `value` = :v2, `group` = :g2',
                ['k' => $k, 'v' => $v, 'g' => $g, 'v2' => $v, 'g2' => $g]
            );
        }

        $now = gmdate('Y-m-d H:i:s');
        $policyId = $this->db->insert('policies', [
            'name' => 'Membership Terms and Conditions',
            'description' => 'Core membership agreement.',
            'is_active' => 1,
            'created_by' => $this->adminUserId,
        ]);
        $this->db->insert('terms_versions', [
            'policy_id' => $policyId,
            'title' => 'Membership Terms and Conditions',
            'content' => '<h2>Membership Terms and Conditions</h2><p>By becoming a member of the ' . self::ORG_NAME . ', you agree to abide by the Scout Promise and Law.</p>',
            'version_number' => '1.0',
            'is_published' => 1,
            'published_at' => $now,
            'grace_period_days' => 14,
            'created_by' => $this->adminUserId,
        ]);

        $this->db->insert('notices', [
            'title' => 'Welcome to the ScoutKeeper Demo',
            'content' => 'This is a demonstration instance of the ' . self::ORG_NAME . ' seeded with stress-test data. All accounts use the shared password "' . self::SHARED_PASSWORD . '".',
            'type' => 'informational',
            'is_active' => 1,
            'created_by' => $this->adminUserId,
        ]);
    }

    // ── Bulk insert helper ───────────────────────────────────────

    /**
     * Multi-row bulk insert. Returns the auto-increment ID of the first inserted row
     * (of the first batch). MySQL guarantees consecutive IDs *within* a single
     * multi-row INSERT (innodb_autoinc_lock_mode default), and consecutive IDs
     * across batches provided no other inserter is racing — safe for seed runs.
     */
    private function bulkInsert(string $table, array $columns, array $rows, int $batchSize = 500): int
    {
        if (empty($rows)) return 0;
        $colList = '`' . implode('`,`', $columns) . '`';
        $rowPlaceholder = '(' . implode(',', array_fill(0, count($columns), '?')) . ')';
        $firstId = 0;
        foreach (array_chunk($rows, $batchSize) as $chunkIdx => $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), $rowPlaceholder));
            $sql = "INSERT INTO `$table` ($colList) VALUES $placeholders";
            $values = [];
            foreach ($chunk as $row) {
                foreach ($row as $v) {
                    $values[] = $v;
                }
            }
            $this->db->query($sql, $values);
            if ($chunkIdx === 0) {
                $firstId = (int)$this->db->fetchColumn('SELECT LAST_INSERT_ID()');
            }
        }
        return $firstId;
    }

    // ── Name / place generators ──────────────────────────────────

    private function pickFirstName(string $gender, int $i): string
    {
        static $male = ['Luca', 'Matteo', 'Jake', 'Liam', 'Noah', 'Ethan', 'Mario', 'Joseph', 'Karl', 'Paul', 'Peter', 'Andrew', 'Mark', 'David', 'Michael', 'Kevin', 'Simon', 'Daniel', 'Thomas', 'Stephen', 'John', 'James', 'Nicholas', 'Antoine', 'Leon', 'Gabriel', 'Samuel', 'Owen', 'Finn', 'Marco', 'Dario', 'Rafael', 'Carlo', 'Emilio', 'Vito'];
        static $female = ['Maria', 'Sarah', 'Emma', 'Sofia', 'Olivia', 'Isabella', 'Nicole', 'Chloe', 'Martha', 'Julia', 'Elena', 'Lara', 'Amy', 'Rachel', 'Rebecca', 'Hannah', 'Sara', 'Stephanie', 'Claire', 'Alice', 'Laura', 'Anna', 'Catherine', 'Deborah', 'Michelle', 'Fiona', 'Gloria', 'Helen', 'Ingrid', 'Jessica', 'Kelly', 'Lisa', 'Nina', 'Paula', 'Roberta'];
        $pool = $gender === 'female' ? $female : $male;
        return $pool[$i % count($pool)];
    }

    private function pickSurname(int $i): string
    {
        static $surnames = ['Borg', 'Camilleri', 'Vella', 'Farrugia', 'Grech', 'Zammit', 'Mifsud', 'Attard', 'Bonello', 'Spiteri', 'Azzopardi', 'Galea', 'Micallef', 'Cassar', 'Sultana', 'Caruana', 'Pace', 'Schembri', 'Gatt', 'Muscat', 'Debono', 'Abela', 'Said', 'Fenech', 'Xuereb', 'Bugeja', 'Buttigieg', 'Aquilina', 'Psaila', 'Cutajar', 'Zahra', 'Scicluna', 'Grima', 'Portelli', 'Calleja', 'Cauchi', 'Chetcuti', 'Gauci', 'Mallia', 'Ellul', 'Mangion', 'Bartolo', 'Testa', 'Pullicino', 'Agius'];
        return $surnames[$i % count($surnames)];
    }

    private function pickStreet(int $i): string
    {
        static $streets = ['Triq il-Kbira', 'Triq San Pawl', 'Triq il-Mithna', 'Triq il-Kanonku', 'Triq il-Parrocca', 'Old Bakery Street', 'Republic Street', 'Merchants Street', 'St John Street', 'Archbishop Street', 'Triq il-Wied', 'Triq il-Baħar', 'Triq il-Kappella'];
        return $streets[$i % count($streets)];
    }

    private function pickCity(int $i): string
    {
        static $cities = ['Valletta', 'Sliema', 'Birkirkara', 'Mosta', 'Qormi', 'Naxxar', 'Zabbar', 'Zejtun', 'Rabat', 'Mdina', 'Victoria', 'Xlendi', 'Mellieha', 'St Julian\'s', 'Marsascala', 'Gzira', 'San Gwann', 'Attard', 'Balzan', 'Lija', 'Marsa', 'Paola', 'Tarxien', 'Hamrun'];
        return $cities[$i % count($cities)];
    }

    private function groupTownName(int $n): string
    {
        static $towns = ['Valletta', 'Sliema', 'Birkirkara', 'Mosta', 'Qormi', 'Naxxar', 'Zabbar', 'Zejtun', 'Rabat', 'Mdina', 'Victoria', 'Mellieha', 'St Julian\'s', 'Marsascala', 'Gzira', 'San Gwann', 'Attard', 'Balzan', 'Lija', 'Marsa', 'Paola', 'Tarxien', 'Hamrun', 'Cospicua', 'Senglea', 'Vittoriosa', 'Kalkara', 'Xghajra', 'Gharghur', 'Pembroke', 'Swieqi', 'Madliena', 'Bahar ic-Caghaq', 'Qawra', 'Bugibba', 'San Pawl il-Bahar', 'Xemxija', 'Mgarr', 'Gharb', 'Zebbug', 'Kercem', 'Sannat', 'Nadur', 'Qala', 'Xaghra', 'Ghajnsielem', 'Munxar', 'Fontana', 'Dingli', 'Siggiewi', 'Luqa', 'Kirkop', 'Mqabba', 'Qrendi', 'Safi', 'Zurrieq', 'Ghaxaq', 'Gudja', 'Birzebbuga', 'Fgura', 'Iklin', 'Mtarfa'];
        return $towns[($n - 1) % count($towns)];
    }

    private function ordinalSuffix(int $n): string
    {
        if ($n % 100 >= 11 && $n % 100 <= 13) return 'th';
        return match ($n % 10) {
            1 => 'st',
            2 => 'nd',
            3 => 'rd',
            default => 'th',
        };
    }
}
