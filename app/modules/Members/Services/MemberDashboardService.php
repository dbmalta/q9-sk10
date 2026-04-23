<?php

declare(strict_types=1);

namespace App\Modules\Members\Services;

use App\Core\Database;
use App\Modules\Admin\Services\NoticeService;
use App\Modules\Communications\Services\ArticleService;
use App\Modules\Events\Services\EventService;

/**
 * Aggregates the data shown on the member-facing landing page: greeting,
 * node context, upcoming events, recent articles, and unread notices —
 * all scoped to the member's own node memberships.
 */
class MemberDashboardService
{
    public function __construct(
        private readonly Database $db,
    ) {
    }

    /**
     * Load the full dashboard payload for the given member/user pair.
     *
     * @return array{
     *   member: array,
     *   nodes: array<int, array{id:int, name:string}>,
     *   upcoming_events: array,
     *   recent_articles: array,
     *   notices: array
     * }
     */
    public function load(int $memberId, int $userId): array
    {
        $member = $this->db->fetchOne(
            "SELECT id, first_name, surname, membership_number, status
             FROM members WHERE id = :id",
            ['id' => $memberId]
        ) ?? [];

        $nodes = $this->db->fetchAll(
            "SELECT n.id, n.name
             FROM member_nodes mn
             JOIN org_nodes n ON n.id = mn.node_id
             WHERE mn.member_id = :mid
             ORDER BY mn.is_primary DESC, n.name",
            ['mid' => $memberId]
        );
        $nodeIds = array_map(fn(array $n): int => (int) $n['id'], $nodes);

        $eventService   = new EventService($this->db);
        $articleService = new ArticleService($this->db);
        $noticeService  = new NoticeService($this->db);

        $upcoming = $nodeIds !== []
            ? $eventService->getUpcoming($nodeIds, 5)
            : [];

        // ArticleService::getPublished takes a single optional node_id. Pull a
        // small page across the member's nodes and de-duplicate by article id.
        $articles = [];
        $seen = [];
        foreach ($nodeIds as $nid) {
            $page = $articleService->getPublished($nid, 1, 5);
            foreach (($page['items'] ?? []) as $row) {
                if (!isset($row['id']) || isset($seen[$row['id']])) {
                    continue;
                }
                $seen[$row['id']] = true;
                $articles[] = $row;
            }
        }
        // Also include org-wide articles (no node scope).
        $allOrg = $articleService->getPublished(null, 1, 5);
        foreach (($allOrg['items'] ?? []) as $row) {
            if (!isset($row['id']) || isset($seen[$row['id']])) {
                continue;
            }
            $seen[$row['id']] = true;
            $articles[] = $row;
        }
        // Sort newest first, keep top 5.
        usort($articles, fn($a, $b) => strcmp(
            (string) ($b['published_at'] ?? $b['created_at'] ?? ''),
            (string) ($a['published_at'] ?? $a['created_at'] ?? ''),
        ));
        $articles = array_slice($articles, 0, 5);

        $notices = $noticeService->getUnacknowledgedForUser($userId);

        return [
            'member' => $member,
            'nodes' => $nodes,
            'upcoming_events' => $upcoming,
            'recent_articles' => $articles,
            'notices' => $notices,
        ];
    }
}
