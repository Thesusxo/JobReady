<?php
declare(strict_types=1);

require dirname(__DIR__) . '/config/autoload.php';

if (($argv[1] ?? null) !== '--send' || $argc !== 2) {
    fwrite(STDOUT, "No request sent. Run php scripts/test-groq.php --send for one fictional provider-only plan check.\n");
    exit(0);
}

try {
    JobReady\Config\Environment::load(JOBREADY_ROOT . '/.env');
    $apiKey = JobReady\Config\Environment::get('GROQ_API_KEY');
    $model = JobReady\Config\Environment::get('GROQ_MODEL', JobReady\AI\GroqClient::DEFAULT_MODEL)
        ?? JobReady\AI\GroqClient::DEFAULT_MODEL;
    if ($apiKey === null || trim($apiKey) === '') {
        fwrite(STDERR, "Groq provider-only check not run: GROQ_API_KEY is not configured.\n");
        exit(2);
    }

    $client = new JobReady\AI\GroqClient($apiKey, $model);
    $plan = $client->createPlan([
        'target_role' => 'Fictional junior software tester',
        'cv_text' => 'Fictional candidate with basic PHP testing and documentation experience.',
        'job_description' => 'Fictional role covering PHP APIs, test cases, and bug reports.',
    ]);
    (new JobReady\Interview\DecisionValidator())->validatePlan($plan);
    fwrite(STDOUT, "Groq provider-only plan check passed. No interview, database, neatlogs, or JobReady rate-limit path was used.\n");
} catch (Throwable) {
    // Never print provider bodies, prompts, or the credential.
    fwrite(STDERR, "Groq provider-only plan check failed. Raw provider details were suppressed.\n");
    exit(1);
}
