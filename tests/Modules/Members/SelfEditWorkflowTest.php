<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use App\Core\Database;
use App\Modules\Members\Services\MemberService;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end integration test of the self-edit → pending-review workflow
 * against a real MySQL instance. Pinned down after a bug where a user
 * reported that approved changes stayed listed on the Pending Changes page;
 * the DB actually had status='approved', but this test guarantees the
 * service layer behaves correctly and cheap future regressions (e.g. a
 * query that forgets the status filter) blow up here.
 */
class SelfEditWorkflowTest extends TestCase
{
    private Database $db;
    private MemberService $svc;
    private int $memberId;
    private int $memberUserId;
    private int $adminUserId;

    protected function setUp(): void
    {
        try {
            $this->db = new Database(TEST_CONFIG['db']);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Test database not available: ' . $e->getMessage());
        }

        $this->db->query("SET FOREIGN_KEY_CHECKS = 0");
        foreach (['member_pending_changes', 'member_nodes', 'members', 'users'] as $t) {
            $this->db->query("DROP TABLE IF EXISTS `{$t}`");
        }
        $this->db->query("SET FOREIGN_KEY_CHECKS = 1");

        $this->db->query("CREATE TABLE `users` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `email` VARCHAR(255) NOT NULL UNIQUE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `members` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `membership_number` VARCHAR(20) NOT NULL,
            `first_name` VARCHAR(100) NOT NULL,
            `surname` VARCHAR(100) NOT NULL,
            `email` VARCHAR(255) NULL,
            `phone` VARCHAR(50) NULL,
            `dob` DATE NULL,
            `gender` VARCHAR(30) NULL,
            `address_line1` VARCHAR(200) NULL,
            `city` VARCHAR(100) NULL,
            `postcode` VARCHAR(20) NULL,
            `country` VARCHAR(100) NULL,
            `status` VARCHAR(20) NOT NULL DEFAULT 'active',
            `user_id` INT UNSIGNED NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->db->query("CREATE TABLE `member_pending_changes` (
            `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            `member_id` INT UNSIGNED NOT NULL,
            `field_name` VARCHAR(100) NOT NULL,
            `old_value` TEXT NULL,
            `new_value` TEXT NULL,
            `requested_by` INT UNSIGNED NOT NULL,
            `status` ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            `reviewed_by` INT UNSIGNED NULL,
            `reviewed_at` DATETIME NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $this->memberUserId = $this->db->insert('users', ['email' => 'member@test']);
        $this->adminUserId  = $this->db->insert('users', ['email' => 'admin@test']);
        $this->memberId = $this->db->insert('members', [
            'membership_number' => 'T-1',
            'first_name' => 'Jo', 'surname' => 'Smith',
            'email' => 'old@test', 'user_id' => $this->memberUserId,
        ]);

        $this->svc = new MemberService($this->db);
    }

    public function testFullWorkflowSubmitThenApproveLeavesNoPending(): void
    {
        // 1. Member submits a change
        $queued = $this->svc->submitSelfEdit(
            $this->memberId,
            ['email' => 'new@test'],
            $this->memberUserId,
        );
        $this->assertSame(['email'], $queued);

        // 2. Admin sees exactly one pending row
        $pending = $this->svc->getPendingChanges();
        $this->assertCount(1, $pending);
        $this->assertSame('email', $pending[0]['field_name']);
        $this->assertSame('new@test', $pending[0]['new_value']);

        // 3. Admin approves
        $this->svc->reviewChange((int) $pending[0]['id'], 'approved', $this->adminUserId);

        // 4. Pending list is now empty (regression check for the reported bug)
        $this->assertSame([], $this->svc->getPendingChanges());
        $this->assertSame([], $this->svc->getPendingChanges($this->memberId));

        // 5. The row still exists but is status=approved with audit fields set
        $reviewed = $this->db->fetchOne(
            "SELECT * FROM member_pending_changes ORDER BY id DESC LIMIT 1"
        );
        $this->assertSame('approved', $reviewed['status']);
        $this->assertSame($this->adminUserId, (int) $reviewed['reviewed_by']);
        $this->assertNotNull($reviewed['reviewed_at']);

        // 6. Member record was updated
        $updated = $this->db->fetchOne(
            "SELECT email FROM members WHERE id = :id",
            ['id' => $this->memberId]
        );
        $this->assertSame('new@test', $updated['email']);
    }

    public function testRejectLeavesNoPendingAndDoesNotApply(): void
    {
        $this->svc->submitSelfEdit(
            $this->memberId,
            ['phone' => '555-9999'],
            $this->memberUserId,
        );
        $pending = $this->svc->getPendingChanges();
        $this->assertCount(1, $pending);

        $this->svc->reviewChange((int) $pending[0]['id'], 'rejected', $this->adminUserId);

        $this->assertSame([], $this->svc->getPendingChanges());
        $member = $this->db->fetchOne("SELECT phone FROM members WHERE id = :id", ['id' => $this->memberId]);
        $this->assertNull($member['phone']);
    }

    public function testResubmitAfterApprovalIsTreatedAsNewPending(): void
    {
        // First cycle
        $this->svc->submitSelfEdit($this->memberId, ['phone' => '111-1111'], $this->memberUserId);
        $first = $this->svc->getPendingChanges();
        $this->svc->reviewChange((int) $first[0]['id'], 'approved', $this->adminUserId);
        $this->assertSame([], $this->svc->getPendingChanges());

        // Second cycle: member submits another change to the same field
        $this->svc->submitSelfEdit($this->memberId, ['phone' => '222-2222'], $this->memberUserId);
        $second = $this->svc->getPendingChanges();
        $this->assertCount(1, $second);
        $this->assertSame('222-2222', $second[0]['new_value']);
        // Old value reflects the now-current member.phone, not the pre-first value.
        $this->assertSame('111-1111', $second[0]['old_value']);
    }

    public function testDuplicateSubmissionDoesNotCreateDuplicateRow(): void
    {
        $this->svc->submitSelfEdit($this->memberId, ['email' => 'dup@test'], $this->memberUserId);
        $this->svc->submitSelfEdit($this->memberId, ['email' => 'dup@test'], $this->memberUserId);
        $this->svc->submitSelfEdit($this->memberId, ['email' => 'dup@test'], $this->memberUserId);

        $this->assertCount(1, $this->svc->getPendingChanges());
    }

    public function testReviewingAlreadyReviewedThrows(): void
    {
        $this->svc->submitSelfEdit($this->memberId, ['email' => 'x@test'], $this->memberUserId);
        $row = $this->svc->getPendingChanges()[0];
        $this->svc->reviewChange((int) $row['id'], 'approved', $this->adminUserId);

        $this->expectException(\RuntimeException::class);
        $this->svc->reviewChange((int) $row['id'], 'approved', $this->adminUserId);
    }

    public function testScopeFilteredListExcludesApprovedRows(): void
    {
        $this->svc->submitSelfEdit($this->memberId, ['email' => 'a@test'], $this->memberUserId);
        $this->svc->submitSelfEdit($this->memberId, ['phone' => '111'], $this->memberUserId);

        $all = $this->svc->getPendingChanges();
        $this->assertCount(2, $all);

        // Approve one; remaining list should be exactly one row.
        $this->svc->reviewChange((int) $all[0]['id'], 'approved', $this->adminUserId);
        $remaining = $this->svc->getPendingChanges();
        $this->assertCount(1, $remaining);
        $this->assertNotSame($all[0]['id'], $remaining[0]['id']);
    }
}
