<?php

declare(strict_types=1);

namespace Tests\Modules\Members;

use App\Core\Database;
use App\Modules\Members\Services\MemberService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MemberService::submitSelfEdit — the routing rule that
 * every member-initiated change lands in member_pending_changes for
 * admin review, with none applied directly.
 */
class MemberSelfEditTest extends TestCase
{
    /** @var Database&\PHPUnit\Framework\MockObject\MockObject */
    private $db;

    protected function setUp(): void
    {
        $this->db = $this->createMock(Database::class);
    }

    private function stubCurrent(array $member): void
    {
        // fetchOne is called twice per field: once for the current member,
        // and then once per field to check for duplicate pending rows.
        // willReturnOnConsecutiveCalls lets us script it.
        $returns = [$member]; // first call: current member
        // subsequent calls: duplicate-pending check always returns null
        for ($i = 0; $i < 20; $i++) {
            $returns[] = null;
        }
        $this->db->method('fetchOne')->willReturnOnConsecutiveCalls(...$returns);
    }

    public function testQueuesPendingChangeForAllowedField(): void
    {
        $this->stubCurrent([
            'id' => 42, 'first_name' => 'Jo', 'surname' => 'Smith',
            'email' => 'old@test', 'phone' => null,
        ]);
        $this->db->expects($this->once())
            ->method('insert')
            ->with('member_pending_changes', $this->callback(function (array $row): bool {
                return $row['member_id'] === 42
                    && $row['field_name'] === 'email'
                    && $row['old_value'] === 'old@test'
                    && $row['new_value'] === 'new@test'
                    && $row['status'] === 'pending';
            }))
            ->willReturn(7);

        $svc = new MemberService($this->db);
        $queued = $svc->submitSelfEdit(42, ['email' => 'new@test'], 99);
        $this->assertSame(['email'], $queued);
    }

    public function testNeverAppliesChangesDirectlyToMembersTable(): void
    {
        $this->stubCurrent([
            'id' => 42, 'first_name' => 'Jo', 'surname' => 'Smith',
        ]);
        // Every insert goes to member_pending_changes, never to `members`.
        $this->db->expects($this->atLeastOnce())
            ->method('insert')
            ->with('member_pending_changes', $this->anything())
            ->willReturn(1);
        $this->db->expects($this->never())
            ->method('update')
            ->with('members', $this->anything(), $this->anything());

        $svc = new MemberService($this->db);
        $svc->submitSelfEdit(42, [
            'first_name' => 'Joanna',
            'surname'    => 'Smithson',
        ], 99);
    }

    public function testRejectsFieldsInNeverList(): void
    {
        $this->stubCurrent([
            'id' => 42, 'status' => 'active', 'membership_number' => 'SK-1',
            'medical_notes' => null, 'gdpr_consent' => 1,
        ]);
        $this->db->expects($this->never())->method('insert');

        $svc = new MemberService($this->db);
        $queued = $svc->submitSelfEdit(42, [
            'status' => 'suspended',
            'membership_number' => 'HACK-1',
            'medical_notes' => 'nope',
            'gdpr_consent' => 0,
            'user_id' => 99,
        ], 99);
        $this->assertSame([], $queued);
    }

    public function testSilentlyDropsNoOpChanges(): void
    {
        $this->stubCurrent([
            'id' => 42, 'email' => 'same@test', 'phone' => '555-0100',
        ]);
        $this->db->expects($this->never())->method('insert');

        $svc = new MemberService($this->db);
        $queued = $svc->submitSelfEdit(42, [
            'email' => 'same@test',
            'phone' => '555-0100',
        ], 99);
        $this->assertSame([], $queued);
    }

    public function testLowercasesEmailBeforeQueueing(): void
    {
        $this->stubCurrent(['id' => 42, 'email' => 'old@test']);
        $this->db->expects($this->once())
            ->method('insert')
            ->with('member_pending_changes', $this->callback(
                fn(array $r) => $r['new_value'] === 'new@test'
            ))
            ->willReturn(1);

        $svc = new MemberService($this->db);
        $svc->submitSelfEdit(42, ['email' => '  NEW@Test  '], 99);
    }

    public function testTreatsEmptyStringAsNull(): void
    {
        $this->stubCurrent(['id' => 42, 'phone' => '555-0100']);
        $this->db->expects($this->once())
            ->method('insert')
            ->with('member_pending_changes', $this->callback(
                fn(array $r) => $r['new_value'] === null && $r['old_value'] === '555-0100'
            ))
            ->willReturn(1);

        $svc = new MemberService($this->db);
        $svc->submitSelfEdit(42, ['phone' => '  '], 99);
    }
}
