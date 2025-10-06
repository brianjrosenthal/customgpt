<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/UserContext.php';
require_once __DIR__ . '/ActivityLog.php';
require_once __DIR__ . '/Files.php';

class CustomGPTDocumentManagement {
    private static function pdo(): PDO {
        return pdo();
    }

    // Activity logging
    private static function log(string $action, ?int $documentId, array $details = []): void {
        try {
            $ctx = UserContext::getLoggedInUserContext();
            $meta = $details;
            if ($documentId !== null && !array_key_exists('document_id', $meta)) {
                $meta['document_id'] = (int)$documentId;
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

    // Find document by ID
    public static function findById(int $id): ?array {
        $st = self::pdo()->prepare('SELECT * FROM customgpt_documents WHERE id=? LIMIT 1');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Create a new document (upload file and link to CustomGPT)
    public static function createDocument(UserContext $ctx, int $customGptId, array $uploadedFile): int {
        self::assertAdmin($ctx);
        
        if (!isset($uploadedFile['tmp_name']) || !is_uploaded_file($uploadedFile['tmp_name'])) {
            throw new InvalidArgumentException('Invalid file upload.');
        }

        $data = file_get_contents($uploadedFile['tmp_name']);
        if ($data === false) {
            throw new RuntimeException('Failed to read uploaded file.');
        }

        $contentType = $uploadedFile['type'] ?? 'application/octet-stream';
        $originalFilename = $uploadedFile['name'] ?? 'upload';

        // Insert file into secure_files
        $fileId = Files::insertSecureFile($data, $contentType, $originalFilename, $ctx->id);

        // Create document record
        $st = self::pdo()->prepare(
            "INSERT INTO customgpt_documents (customgpt_id, file_id, created_at)
             VALUES (?, ?, NOW())"
        );
        $st->execute([$customGptId, $fileId]);
        $documentId = (int)self::pdo()->lastInsertId();
        
        self::log('document.create', $documentId, [
            'customgpt_id' => $customGptId,
            'file_id' => $fileId,
            'filename' => $originalFilename
        ]);
        
        return $documentId;
    }

    // List all documents for a CustomGPT
    public static function listDocumentsByCustomGPT(int $customGptId): array {
        $sql = 'SELECT d.*, sf.original_filename, sf.byte_length, sf.content_type, sf.created_at as file_created_at
                FROM customgpt_documents d
                INNER JOIN secure_files sf ON d.file_id = sf.id
                WHERE d.customgpt_id = ?
                ORDER BY d.created_at DESC';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$customGptId]);
        return $st->fetchAll();
    }

    // Get document with file details
    public static function getDocumentWithFile(int $documentId): ?array {
        $sql = 'SELECT d.*, sf.original_filename, sf.byte_length, sf.content_type, sf.sha256
                FROM customgpt_documents d
                INNER JOIN secure_files sf ON d.file_id = sf.id
                WHERE d.id = ?
                LIMIT 1';
        
        $st = self::pdo()->prepare($sql);
        $st->execute([$documentId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    // Delete document (will cascade delete file due to FK constraint)
    public static function deleteDocument(UserContext $ctx, int $id): bool {
        self::assertAdmin($ctx);
        
        // Get document info for logging
        $doc = self::findById($id);
        if (!$doc) {
            return false;
        }
        
        $st = self::pdo()->prepare('DELETE FROM customgpt_documents WHERE id = ?');
        $ok = $st->execute([$id]);
        
        if ($ok) {
            self::log('document.delete', $id, [
                'customgpt_id' => (int)$doc['customgpt_id'],
                'file_id' => (int)$doc['file_id']
            ]);
        }
        
        return $ok;
    }

    // Count documents for a CustomGPT
    public static function countDocumentsByCustomGPT(int $customGptId): int {
        $st = self::pdo()->prepare('SELECT COUNT(*) FROM customgpt_documents WHERE customgpt_id = ?');
        $st->execute([$customGptId]);
        return (int)$st->fetchColumn();
    }

    // Get file ID for a document
    public static function getFileId(int $documentId): ?int {
        $st = self::pdo()->prepare('SELECT file_id FROM customgpt_documents WHERE id = ? LIMIT 1');
        $st->execute([$documentId]);
        $result = $st->fetchColumn();
        return $result !== false ? (int)$result : null;
    }
}
