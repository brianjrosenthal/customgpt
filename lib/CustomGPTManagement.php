<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';

class CustomGPTManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    private static function str(string $v): string {
        return trim($v);
    }

    private static function boolInt($v): int {
        return !empty($v) ? 1 : 0;
    }

    // Activity logging - do not perform extra queries, just log what's provided.
    private static function log(string $action, ?int $customGptId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($customGptId !== null && !array_key_exists('customgpt_id', $meta)) {
                $meta['customgpt_id'] = (int)$customGptId;
            }
            ActivityLog::log($ctx, (string)$action, (array)$meta);
        } catch (\Throwable $e) {
            // Best-effort logging; never disrupt the main flow.
        }
    }

    private static function assertAdmin(?UserContext $ctx): void {
        if (!$ctx || !$ctx->admin) { 
            throw new RuntimeException('Admins only'); 
        }
    }

    // Find CustomGPT by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM customgpts WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Create a new CustomGPT
    public static function createCustomGPT(UserContext $ctx, array $data): int {
        self::assertAdmin($ctx);
        
        $name = self::str($data['name'] ?? '');
        $description = self::str($data['description'] ?? '');
        $isPublic = self::boolInt($data['is_public'] ?? 0);

        if ($name === '') {
            throw new InvalidArgumentException('Name is required for CustomGPT creation.');
        }

        $st = self::pdo()->prepare(
            "INSERT INTO customgpts (name, description, created_by, is_public)
             VALUES (?, ?, ?, ?)"
        );
        $st->execute([$name, $description, $ctx->id, $isPublic]);
        $id = (int)self::pdo()->lastInsertId();
        
        self::log('customgpt.create', $id, ['name' => $name, 'is_public' => $isPublic]);
        
        return $id;
    }

    // List all CustomGPTs with optional search
    public static function listCustomGPTs(string $search = ''): array {
        $sql = 'SELECT c.*, u.first_name, u.last_name, u.email 
                FROM customgpts c 
                LEFT JOIN users u ON c.created_by = u.id';
        $params = [];

        if ($search !== '') {
            $sql .= ' WHERE c.name LIKE ? OR c.description LIKE ?';
            $searchTerm = '%' . $search . '%';
            $params = [$searchTerm, $searchTerm];
        }

        $sql .= ' ORDER BY c.created_at DESC';

        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    // Update CustomGPT
    public static function updateCustomGPT(UserContext $ctx, int $id, array $fields): bool {
        self::assertAdmin($ctx);
        
        $allowed = ['name', 'description', 'is_public'];
        $set = [];
        $params = [];

        foreach ($allowed as $key) {
            if (!array_key_exists($key, $fields)) continue;

            if ($key === 'is_public') {
                $val = self::boolInt($fields['is_public']);
                $set[] = 'is_public = ?';
                $params[] = $val;
            } elseif ($key === 'name') {
                $val = self::str($fields['name']);
                if ($val === '') {
                    throw new InvalidArgumentException("Name cannot be empty");
                }
                $set[] = "name = ?";
                $params[] = $val;
            } else {
                $val = self::str($fields[$key]);
                $set[] = "$key = ?";
                $params[] = $val;
            }
        }

        if (empty($set)) return false;
        $params[] = $id;

        $sql = 'UPDATE customgpts SET ' . implode(', ', $set) . ' WHERE id = ?';
        $st = self::pdo()->prepare($sql);
        $ok = $st->execute($params);
        
        if ($ok) {
            $updatedFields = array_intersect_key($fields, array_flip($allowed));
            self::log('customgpt.update', $id, $updatedFields);
        }
        
        return $ok;
    }

    // Delete CustomGPT
    public static function deleteCustomGPT(UserContext $ctx, int $id): bool {
        self::assertAdmin($ctx);
        
        $st = self::pdo()->prepare('DELETE FROM customgpts WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok) {
            self::log('customgpt.delete', $id);
        }
        
        return $ok;
    }

    // Get creator information for a CustomGPT
    public static function getCreatorInfo(int $customGptId): ?array {
        $sql = 'SELECT u.id, u.first_name, u.last_name, u.email 
                FROM users u 
                INNER JOIN customgpts c ON c.created_by = u.id 
                WHERE c.id = ? 
                LIMIT 1';
        $st = self::pdo()->prepare($sql);
        $st->execute([$customGptId]);
        $row = $st->fetch();
        return $row ?: null;
    }
}
