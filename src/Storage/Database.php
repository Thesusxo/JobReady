<?php
declare(strict_types=1);

namespace JobReady\Storage;

use PDO;
use RuntimeException;

final class Database
{
    public static function connect(string $databasePath): PDO
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the SQLite data directory.');
        }

        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        self::initialize($pdo);
        return $pdo;
    }

    public static function initialize(PDO $pdo): void
    {
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interview_sessions (
                id TEXT PRIMARY KEY,
                state_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interviews (
                id TEXT PRIMARY KEY,
                cv_text TEXT NOT NULL,
                job_description TEXT NOT NULL,
                target_role TEXT NOT NULL,
                plan_json TEXT NOT NULL,
                status TEXT NOT NULL CHECK (status IN ('active', 'finished')),
                answer_count INTEGER NOT NULL DEFAULT 0,
                current_question TEXT,
                current_question_decision_id INTEGER,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interview_decisions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interview_id TEXT NOT NULL REFERENCES interviews(id) ON DELETE CASCADE,
                sequence_no INTEGER NOT NULL,
                action TEXT NOT NULL CHECK (action IN ('ask_question', 'probe_answer', 'verify_claim', 'finish_interview')),
                arguments_json TEXT NOT NULL,
                rationale TEXT NOT NULL,
                result_json TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (interview_id, sequence_no)
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interview_answers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interview_id TEXT NOT NULL REFERENCES interviews(id) ON DELETE CASCADE,
                answer_no INTEGER NOT NULL,
                question_decision_id INTEGER NOT NULL REFERENCES interview_decisions(id),
                question_text TEXT NOT NULL,
                answer_text TEXT NOT NULL,
                created_at TEXT NOT NULL,
                UNIQUE (interview_id, answer_no)
            )
            SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS interview_evaluations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                interview_id TEXT NOT NULL REFERENCES interviews(id) ON DELETE CASCADE,
                answer_id INTEGER REFERENCES interview_answers(id) ON DELETE CASCADE,
                evaluation_type TEXT NOT NULL,
                data_json TEXT NOT NULL,
                created_at TEXT NOT NULL
            )
            SQL);
    }
}
