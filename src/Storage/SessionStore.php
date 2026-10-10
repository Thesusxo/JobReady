<?php
declare(strict_types=1);

namespace JobReady\Storage;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class SessionStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $state */
    public function create(string $id, array $state): void
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare('INSERT INTO interview_sessions (id, state_json, created_at, updated_at) VALUES (:id, :state, :created, :updated)');
        $statement->execute(['id' => $this->validateId($id), 'state' => $this->encode($state), 'created' => $now, 'updated' => $now]);
    }

    /** @return array{ id: string, state: array<string, mixed>, created_at: string, updated_at: string }|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, state_json, created_at, updated_at FROM interview_sessions WHERE id = :id');
        $statement->execute(['id' => $this->validateId($id)]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        try {
            $state = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new RuntimeException('Stored session state is invalid.', 0, $exception);
        }
        return ['id' => $row['id'], 'state' => $state, 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at']];
    }

    /** @param array<string, mixed> $state */
    public function save(string $id, array $state): bool
    {
        $statement = $this->pdo->prepare('UPDATE interview_sessions SET state_json = :state, updated_at = :updated WHERE id = :id');
        $statement->execute(['state' => $this->encode($state), 'updated' => gmdate('c'), 'id' => $this->validateId($id)]);
        return $statement->rowCount() === 1;
    }

    private function validateId(string $id): string
    {
        if (!preg_match('/^[a-f0-9-]{36}$/i', $id)) {
            throw new InvalidArgumentException('Session ID must be a UUID.');
        }
        return $id;
    }

    /** @param array<string, mixed> $state */
    private function encode(array $state): string
    {
        try {
            return json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('Session state cannot be encoded as JSON.', 0, $exception);
        }
    }
}
