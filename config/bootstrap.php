<?php
declare(strict_types=1);

use JobReady\Config\Environment;
use JobReady\AI\OpenAIClient;
use JobReady\AI\GeminiClient;
use JobReady\Interview\DecisionValidator;
use JobReady\Interview\InterviewService;
use JobReady\Observability\NeatlogsClientInterface;
use JobReady\Observability\NeatlogsClient;
use JobReady\Observability\NullNeatlogsClient;
use JobReady\Storage\Database;
use JobReady\Storage\InterviewStore;
use JobReady\Storage\SessionStore;

define('JOBREADY_ROOT', dirname(__DIR__));

spl_autoload_register(static function (string $class): void {
    $prefix = 'JobReady\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = JOBREADY_ROOT . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Environment::load(JOBREADY_ROOT . '/.env');
$databasePath = Environment::get('APP_DB_PATH', 'data/jobready.sqlite');
if ($databasePath === null || $databasePath === '') {
    throw new RuntimeException('APP_DB_PATH must be configured.');
}
if (!str_starts_with($databasePath, DIRECTORY_SEPARATOR) && !preg_match('/^[A-Za-z]:[\\\\\/]/', $databasePath)) {
    $databasePath = JOBREADY_ROOT . DIRECTORY_SEPARATOR . $databasePath;
}

$pdo = Database::connect($databasePath);
$sessionStore = new SessionStore($pdo);
$interviewStore = new InterviewStore($pdo);
$writeKey = Environment::get('NEATLOGS_WRITE_KEY');
$project = Environment::get('NEATLOGS_PROJECT');
$neatlogsClient = ($writeKey !== null && trim($writeKey) !== '' && $project !== null && trim($project) !== '')
    ? new NeatlogsClient($writeKey, $project)
    : new NullNeatlogsClient();
assert($neatlogsClient instanceof NeatlogsClientInterface);
$provider = strtolower(trim(Environment::get('AI_PROVIDER', 'gemini') ?? 'gemini'));
$openAI = match ($provider) {
    'gemini' => new GeminiClient(
        Environment::get('GEMINI_API_KEY'),
        Environment::get('GEMINI_MODEL', GeminiClient::DEFAULT_MODEL) ?? GeminiClient::DEFAULT_MODEL,
    ),
    'openai' => new OpenAIClient(
        Environment::get('OPENAI_API_KEY'),
        Environment::get('OPENAI_MODEL', 'gpt-4o-mini') ?? 'gpt-4o-mini',
    ),
    default => throw new RuntimeException('Unsupported AI provider configuration.'),
};
$interviewService = new InterviewService($pdo, $interviewStore, $openAI, new DecisionValidator(), $neatlogsClient);

return [
    'environment' => Environment::get('APP_ENV', 'production'),
    'debug' => Environment::get('APP_DEBUG', 'false') === 'true',
    'pdo' => $pdo,
    'session_store' => $sessionStore,
    'interview_store' => $interviewStore,
    'interview_service' => $interviewService,
    'neatlogs' => $neatlogsClient,
];
