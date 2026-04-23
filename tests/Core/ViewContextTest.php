<?php

declare(strict_types=1);

namespace Tests\Core;

use App\Core\ViewContext;
use PHPUnit\Framework\TestCase;

class ViewContextTest extends TestCase
{
    public function testRejectsInvalidMode(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ViewContext('bogus', null, [], false, false);
    }

    public function testAllNodesReturnsAvailableScopeIds(): void
    {
        $ctx = new ViewContext(
            mode: ViewContext::MODE_ADMIN,
            activeScopeNodeId: null,
            availableScopes: [
                ['node_id' => 4, 'path' => 'District A', 'leaf' => 'District A'],
                ['node_id' => 12, 'path' => 'District A > Group 1', 'leaf' => 'Group 1'],
            ],
            canSwitchToAdmin: true,
            canSwitchToMember: false,
        );

        $this->assertTrue($ctx->isAllNodes());
        $this->assertSame([4, 12], $ctx->scopeNodeIds());
    }

    public function testSpecificScopeReturnsSingleNodeId(): void
    {
        $ctx = new ViewContext(
            mode: ViewContext::MODE_ADMIN,
            activeScopeNodeId: 12,
            availableScopes: [
                ['node_id' => 4, 'path' => 'District A', 'leaf' => 'District A'],
                ['node_id' => 12, 'path' => 'District A > Group 1', 'leaf' => 'Group 1'],
            ],
            canSwitchToAdmin: true,
            canSwitchToMember: false,
        );

        $this->assertFalse($ctx->isAllNodes());
        $this->assertSame([12], $ctx->scopeNodeIds());
    }

    public function testShowsModePillsOnlyWhenDualRole(): void
    {
        $dual = new ViewContext(ViewContext::MODE_ADMIN, null, [], true, true);
        $this->assertTrue($dual->showsModePills());

        $adminOnly = new ViewContext(ViewContext::MODE_ADMIN, null, [], true, false);
        $this->assertFalse($adminOnly->showsModePills());

        $memberOnly = new ViewContext(ViewContext::MODE_MEMBER, null, [], false, true);
        $this->assertFalse($memberOnly->showsModePills());
    }

    public function testScopePickerHiddenInMemberMode(): void
    {
        $ctx = new ViewContext(
            ViewContext::MODE_MEMBER,
            null,
            [
                ['node_id' => 4, 'path' => 'A', 'leaf' => 'A'],
                ['node_id' => 5, 'path' => 'B', 'leaf' => 'B'],
            ],
            true,
            true,
        );
        $this->assertFalse($ctx->showsScopePicker());
    }

    public function testScopePickerHiddenOnNonScopablePage(): void
    {
        $ctx = new ViewContext(
            ViewContext::MODE_ADMIN,
            null,
            [
                ['node_id' => 4, 'path' => 'A', 'leaf' => 'A'],
                ['node_id' => 5, 'path' => 'B', 'leaf' => 'B'],
            ],
            true,
            false,
            scopeAppliesToCurrentPage: false,
        );
        $this->assertFalse($ctx->showsScopePicker());
    }

    public function testScopePickerHiddenForSingleScopeUser(): void
    {
        $ctx = new ViewContext(
            ViewContext::MODE_ADMIN,
            4,
            [['node_id' => 4, 'path' => 'A', 'leaf' => 'A']],
            true,
            false,
        );
        $this->assertFalse($ctx->showsScopePicker());
    }

    public function testToArrayResolvesActiveScope(): void
    {
        $ctx = new ViewContext(
            ViewContext::MODE_ADMIN,
            12,
            [
                ['node_id' => 4, 'path' => 'A', 'leaf' => 'A'],
                ['node_id' => 12, 'path' => 'A > G', 'leaf' => 'G'],
            ],
            true,
            false,
        );
        $arr = $ctx->toArray();
        $this->assertSame(12, $arr['active_scope_node_id']);
        $this->assertSame('G', $arr['active_scope']['leaf']);
        $this->assertFalse($arr['shows_mode_pills']);
    }
}
