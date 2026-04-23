<?php

declare(strict_types=1);

namespace App\Core;

/**
 * View context value object.
 *
 * Captures the resolved mode (admin|member) and active node scope for the
 * current request, along with the set of scopes the user may switch to and
 * which mode pills the switcher should render.
 *
 * The scope/mode pair is a FILTER and a UX affordance — security is still
 * enforced by explicit capability checks against the active scope.
 */
final class ViewContext
{
    public const MODE_ADMIN  = 'admin';
    public const MODE_MEMBER = 'member';

    /**
     * @param string $mode One of MODE_ADMIN or MODE_MEMBER.
     * @param int|null $activeScopeNodeId Null = "All nodes".
     * @param array<int, array{node_id:int, path:string, leaf:string}> $availableScopes
     * @param bool $canSwitchToAdmin User has at least one admin scope.
     * @param bool $canSwitchToMember User has a member record.
     * @param bool $scopeAppliesToCurrentPage Whether the current route is scopable.
     */
    public function __construct(
        public readonly string $mode,
        public readonly ?int $activeScopeNodeId,
        public readonly array $availableScopes,
        public readonly bool $canSwitchToAdmin,
        public readonly bool $canSwitchToMember,
        public readonly bool $scopeAppliesToCurrentPage = true,
    ) {
        if ($mode !== self::MODE_ADMIN && $mode !== self::MODE_MEMBER) {
            throw new \InvalidArgumentException("Invalid view mode: {$mode}");
        }
    }

    /**
     * The list of node_ids this context's queries should filter by.
     *
     * Returns [] if scope is "All nodes" (caller should union across
     * availableScopes) or a single-element array when a specific node
     * is active. The actual subtree expansion happens at query time
     * via org_closure — this method only returns the root(s).
     *
     * @return array<int, int>
     */
    public function scopeNodeIds(): array
    {
        if ($this->activeScopeNodeId === null) {
            return array_map(fn(array $s) => $s['node_id'], $this->availableScopes);
        }
        return [$this->activeScopeNodeId];
    }

    public function isAdmin(): bool
    {
        return $this->mode === self::MODE_ADMIN;
    }

    public function isAllNodes(): bool
    {
        return $this->activeScopeNodeId === null;
    }

    /**
     * Whether the topbar switcher should render mode pills for this user.
     * Hidden when the user is purely one or the other.
     */
    public function showsModePills(): bool
    {
        return $this->canSwitchToAdmin && $this->canSwitchToMember;
    }

    /**
     * Whether the topbar switcher should render the scope dropdown.
     * Hidden in member mode, on non-scopable pages, or for single-scope users.
     */
    public function showsScopePicker(): bool
    {
        return $this->isAdmin()
            && $this->scopeAppliesToCurrentPage
            && count($this->availableScopes) > 1;
    }

    /**
     * Expose as an associative array for Twig globals.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $active = null;
        if ($this->activeScopeNodeId !== null) {
            foreach ($this->availableScopes as $s) {
                if ($s['node_id'] === $this->activeScopeNodeId) {
                    $active = $s;
                    break;
                }
            }
        }

        return [
            'mode' => $this->mode,
            'active_scope_node_id' => $this->activeScopeNodeId,
            'active_scope' => $active,
            'available_scopes' => $this->availableScopes,
            'can_switch_to_admin' => $this->canSwitchToAdmin,
            'can_switch_to_member' => $this->canSwitchToMember,
            'scope_applies_to_current_page' => $this->scopeAppliesToCurrentPage,
            'shows_mode_pills' => $this->showsModePills(),
            'shows_scope_picker' => $this->showsScopePicker(),
        ];
    }
}
