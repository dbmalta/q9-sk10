-- Performance indexes for large datasets (5000+ members)
-- Added as part of Phase 7 performance review

-- Members: status filtering in search and count queries
CREATE INDEX idx_members_status ON members(status);

-- Member nodes: reverse lookup for scope-filtered member queries
CREATE INDEX idx_member_nodes_node ON member_nodes(node_id);

-- Pending changes: status-based reviews
CREATE INDEX idx_pending_changes_status ON member_pending_changes(status, member_id);

-- Role assignments: role-based lookups for reports
CREATE INDEX idx_role_assignments_role ON role_assignments(role_id);

-- Audit log: user+date filtering for audit trail
CREATE INDEX idx_audit_user_date ON audit_log(user_id, created_at DESC);

-- Audit log: entity trail with date ordering
CREATE INDEX idx_audit_entity_date ON audit_log(entity_type, entity_id, created_at DESC);

-- Email queue: batch processing query
CREATE INDEX idx_queue_batch ON email_queue(status, attempts, scheduled_at);

-- Articles: published articles filtered by scope
CREATE INDEX idx_article_pub_node ON articles(is_published, node_scope_id, published_at DESC);

-- Events: published events filtered by scope and date
CREATE INDEX idx_event_pub_scope_date ON events(is_published, node_scope_id, start_date);
