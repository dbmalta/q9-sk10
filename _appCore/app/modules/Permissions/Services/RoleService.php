<?php

declare(strict_types=1);

namespace AppCore\Modules\Permissions\Services;

use AppCore\Core\Database;

/**
 * Role CRUD.
 *
 * Permissions are stored as a JSON object on the role:
 *   {"module.action": true, "module.action": true, ...}
 *
 * The Permissions column is authoritative — each role's grants are
 * self-contained. Effective permissions are the union of all of a
 * user's active role assignments (see PermissionResolver).
 */
class RoleService
{
    private Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->fetchAll(
            "SELECT id, name, description, permissions, is_system, created_at, updated_at
             FROM roles ORDER BY is_system DESC, name ASC"
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT id, name, description, permissions, is_system FROM roles WHERE id = :id",
            ['id' => $id]
        );
    }

    /**
     * @param array<string, bool> $permissions
     * @return array{success: bool, errors: array<string>, id?: int}
     */
    public function create(string $name, string $description, array $permissions): array
    {
        $errors = $this->validate($name, $permissions);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $id = $this->db->insert('roles', [
            'name'        => $name,
            'description' => $description,
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
            'is_system'   => 0,
        ]);

        return ['success' => true, 'errors' => [], 'id' => $id];
    }

    /**
     * @param array<string, bool> $permissions
     * @return array{success: bool, errors: array<string>}
     */
    public function update(int $id, string $name, string $description, array $permissions): array
    {
        $errors = $this->validate($name, $permissions);
        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $this->db->update('roles', [
            'name'        => $name,
            'description' => $description,
            'permissions' => json_encode($permissions, JSON_UNESCAPED_UNICODE),
        ], ['id' => $id]);

        return ['success' => true, 'errors' => []];
    }

    public function delete(int $id): bool
    {
        $role = $this->find($id);
        if ($role === null || (int) $role['is_system'] === 1) {
            return false;
        }
        $this->db->delete('roles', ['id' => $id]);
        return true;
    }

    /**
     * @return array<string>
     */
    private function validate(string $name, array $permissions): array
    {
        $errors = [];
        if (trim($name) === '') {
            $errors[] = 'Role name is required.';
        }
        foreach ($permissions as $value) {
            if (!is_bool($value)) {
                $errors[] = 'Invalid permission value.';
                break;
            }
        }
        return $errors;
    }
}
