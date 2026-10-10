<?php
declare(strict_types=1);

namespace JobReady\Storage;

use PDO;
use RuntimeException;

final class InterviewStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<string, mixed> $plan */
    public function create(string $id, string $cv, string $jobDescription, string $role, array $plan): void
    {
        $now = gmdate('c');
        $statement = $this->pdo->prepare('INSERT INTO interviews (id, cv_text, job_description, target_role, plan_json, status, created_at, updated_at) VALUES (:id, :cv, :job, :role, :plan, :status, :created, :updated)');
        $statement->execute([
            'id' => $id,
            'cv' => $cv,
            'job' => $jobDescription,
            'role' => $role,
            'plan' => $this->encode($plan),
            'status' => 'active',
            'created' => $now,
            'updated' => $now,
        ]);
    }

    /** @return array<string, mixed>|null */
    public function find(string $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM interviews WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch();
        if ($row === false) {
            return null;
        }
        $row['plan'] = $this->decode($row['plan_json']);
        unset($row['plan_json']);
        return $row;
    }

    /** @param array<string, mixed> $arguments @param array<string, mixed> $result */
    public function addDecision(string $interviewId, string $action, array $arguments, string $rationale, array $result): int
    {
        $sequence = $this->nextSequence('interview_decisions', $interviewId);
        $statement = $this->pdo->prepare('INSERT INTO interview_decisions (interview_id, sequence_no, action, arguments_json, rationale, result_json, created_at) VALUES (:interview, :sequence, :action, :arguments, :rationale, :result, :created)');
        $statement->execute([
            'interview' => $interviewId,
            'sequence' => $sequence,
            'action' => $action,
            'arguments' => $this->encode($arguments),
            'rationale' => $rationale,
            'result' => $this->encode($result),
            'created' => gmdate('c'),
        ]);
        $this->touch($interviewId);
        return (int) $this->pdo->lastInsertId();
    }

    public function setCurrentQuestion(string $interviewId, string $question, int $decisionId): void
    {
        $statement = $this->pdo->prepare('UPDATE interviews SET current_question = :question, current_question_decision_id = :decision, updated_at = :updated WHERE id = :id AND status = \'active\'');
        $statement->execute(['question' => $question, 'decision' => $decisionId, 'updated' => gmdate('c'), 'id' => $interviewId]);
        if ($statement->rowCount() !== 1) {
            throw new RuntimeException('Interview is not active.');
        }
    }

    public function addAnswer(string $interviewId, string $answer): int
    {
        $interview = $this->find($interviewId);
        if ($interview === null || $interview['status'] !== 'active' || !is_string($interview['current_question']) || $interview['current_question'] === '') {
            throw new RuntimeException('Interview has no answerable question.');
        }
        $answerNo = (int) $interview['answer_count'] + 1;
        $statement = $this->pdo->prepare('INSERT INTO interview_answers (interview_id, answer_no, question_decision_id, question_text, answer_text, created_at) VALUES (:interview, :answer_no, :decision, :question, :answer, :created)');
        $statement->execute([
            'interview' => $interviewId,
            'answer_no' => $answerNo,
            'decision' => $interview['current_question_decision_id'],
            'question' => $interview['current_question'],
            'answer' => $answer,
            'created' => gmdate('c'),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $update = $this->pdo->prepare('UPDATE interviews SET answer_count = :count, current_question = NULL, current_question_decision_id = NULL, updated_at = :updated WHERE id = :id');
        $update->execute(['count' => $answerNo, 'updated' => gmdate('c'), 'id' => $interviewId]);
        return $id;
    }

    /** @param array<string, mixed> $evaluation */
    public function addEvaluation(string $interviewId, ?int $answerId, string $type, array $evaluation): void
    {
        $statement = $this->pdo->prepare('INSERT INTO interview_evaluations (interview_id, answer_id, evaluation_type, data_json, created_at) VALUES (:interview, :answer, :type, :data, :created)');
        $statement->execute(['interview' => $interviewId, 'answer' => $answerId, 'type' => $type, 'data' => $this->encode($evaluation), 'created' => gmdate('c')]);
        $this->touch($interviewId);
    }

    public function finish(string $interviewId): void
    {
        $statement = $this->pdo->prepare("UPDATE interviews SET status = 'finished', current_question = NULL, current_question_decision_id = NULL, updated_at = :updated WHERE id = :id");
        $statement->execute(['updated' => gmdate('c'), 'id' => $interviewId]);
    }

    /** @return list<array<string, mixed>> */
    public function getAnswers(string $interviewId): array
    {
        $statement = $this->pdo->prepare('SELECT id, answer_no, question_text, answer_text, created_at FROM interview_answers WHERE interview_id = :id ORDER BY answer_no');
        $statement->execute(['id' => $interviewId]);
        return $statement->fetchAll();
    }

    /** @return list<array<string, mixed>> */
    public function getDecisions(string $interviewId): array
    {
        $statement = $this->pdo->prepare('SELECT sequence_no, action, arguments_json, rationale, result_json, created_at FROM interview_decisions WHERE interview_id = :id ORDER BY sequence_no');
        $statement->execute(['id' => $interviewId]);
        return array_map(function (array $row): array {
            $row['arguments'] = $this->decode($row['arguments_json']);
            $row['result'] = $this->decode($row['result_json']);
            unset($row['arguments_json'], $row['result_json']);
            return $row;
        }, $statement->fetchAll());
    }

    /** @return list<array<string, mixed>> */
    public function getEvaluations(string $interviewId): array
    {
        $statement = $this->pdo->prepare('SELECT evaluation_type, data_json, created_at FROM interview_evaluations WHERE interview_id = :id ORDER BY id');
        $statement->execute(['id' => $interviewId]);
        return array_map(function (array $row): array {
            $row['data'] = $this->decode($row['data_json']);
            unset($row['data_json']);
            return $row;
        }, $statement->fetchAll());
    }

    /** @return array<string, mixed>|null */
    public function getLatestAnswer(string $interviewId): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, answer_no, question_text, answer_text FROM interview_answers WHERE interview_id = :id ORDER BY answer_no DESC LIMIT 1');
        $statement->execute(['id' => $interviewId]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    private function nextSequence(string $table, string $interviewId): int
    {
        if (!in_array($table, ['interview_decisions'], true)) {
            throw new RuntimeException('Invalid sequence table.');
        }
        $statement = $this->pdo->prepare('SELECT COALESCE(MAX(sequence_no), 0) + 1 FROM ' . $table . ' WHERE interview_id = :id');
        $statement->execute(['id' => $interviewId]);
        return (int) $statement->fetchColumn();
    }

    private function touch(string $id): void
    {
        $statement = $this->pdo->prepare('UPDATE interviews SET updated_at = :updated WHERE id = :id');
        $statement->execute(['updated' => gmdate('c'), 'id' => $id]);
    }

    /** @param array<string, mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($value) ? $value : [];
    }
}
