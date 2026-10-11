<?php
declare(strict_types=1);

use JobReady\Documents\CvUpload;
use JobReady\Documents\DocumentException;
use JobReady\Http\ErrorResponse;
use JobReady\Http\HttpException;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function jsonResponse(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function jsonRequestBody(int $maxBytes): array
{
    $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
    if ($contentType !== 'application/json') {
        throw new HttpException(415, 'Content-Type must be application/json.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > $maxBytes) {
        throw new HttpException(413, 'Request body exceeds the size limit.');
    }
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || strlen($raw) > $maxBytes) {
        throw new HttpException(413, 'Request body exceeds the size limit.');
    }
    try {
        $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new HttpException(400, 'Request body must be valid JSON.');
    }
    if (!is_array($body)) {
        throw new HttpException(400, 'Request body must be a JSON object.');
    }
    return $body;
}

try {
    $services = require dirname(__DIR__) . '/config/bootstrap.php';
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $demoAccess = $services['demo_access'];

    if ($method === 'POST' && str_starts_with($path, '/api/')) {
        set_time_limit(65);
    }

    if ($method === 'GET' && $path === '/') {
        jsonResponse(200, ['status' => 'ok', 'service' => 'JobReady']);
    }

    if ($path === '/api/demo/session') {
        if ($method !== 'GET') throw new HttpException(405, 'Method not allowed.');
        jsonResponse(200, $demoAccess->sessionInfo());
    }

    if ($path === '/api/demo/login') {
        if ($method !== 'POST') throw new HttpException(405, 'Method not allowed.');
        $body = jsonRequestBody(4096);
        jsonResponse(200, $demoAccess->login($body['access_code'] ?? null));
    }

    if ($path === '/api/dev/status') {
        $enabled = in_array($services['environment'], ['local', 'development'], true)
            && $services['debug'] === true;
        if ($method !== 'GET' || !$enabled) throw new HttpException(404, 'Endpoint not found.');
        $demoAccess->requireAuthenticated();
        $model = $services['gemini_model'];
        jsonResponse(200, [
            'APP_ENV' => $services['environment'],
            'debug_enabled' => $services['debug'],
            'AI_PROVIDER' => $services['ai_provider'],
            'GEMINI_MODEL' => preg_match('/^gemini-[a-zA-Z0-9.-]{1,100}$/D', $model) === 1 ? $model : null,
            'diagnostics_enabled' => $enabled,
            'sqlite_driver_available' => in_array('sqlite', PDO::getAvailableDrivers(), true),
        ]);
    }

    $isApi = str_starts_with($path, '/api/');
    if ($isApi && $path !== '/api/demo/session' && $path !== '/api/demo/login') {
        $demoAccess->requireAuthenticated();
        if ($method === 'POST') $demoAccess->requireCsrf();
        $answerMatch = [];
        $interviewId = preg_match('#^/api/interviews/([a-f0-9-]{36})/answers$#i', $path, $answerMatch)
            ? $answerMatch[1] : null;
        $demoAccess->enforceEndpointLimit($path, $method, $interviewId);
    }

    if ($path === '/api/demo/logout') {
        if ($method !== 'POST') throw new HttpException(405, 'Method not allowed.');
        $demoAccess->logout();
        jsonResponse(200, ['logged_out' => true]);
    }

    if ($path === '/api/cv/extract') {
        if ($method !== 'POST') throw new HttpException(405, 'Method not allowed.');
        if (strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0])) !== 'multipart/form-data') {
            throw new HttpException(415, 'Upload a PDF or DOCX using multipart/form-data.');
        }
        try {
            jsonResponse(200, (new CvUpload())->extract($_FILES, (int) ($_SERVER['CONTENT_LENGTH'] ?? 0)));
        } catch (DocumentException $exception) {
            jsonResponse($exception->status, ['error' => $exception->getMessage(), 'code' => $exception->reason]);
        } catch (Throwable) {
            jsonResponse(503, ['error' => 'Document extraction is unavailable. You can still paste your CV text.', 'code' => 'unavailable']);
        }
    }

    $body = null;
    if ($method === 'POST') $body = jsonRequestBody(50000);

    if ($method === 'POST' && $path === '/api/interviews') {
        jsonResponse(201, $services['interview_service']->start($body));
    }
    if (preg_match('#^/api/interviews/([a-f0-9-]{36})/answers$#i', $path, $matches)) {
        if ($method !== 'POST') throw new HttpException(405, 'Method not allowed.');
        jsonResponse(200, $services['interview_service']->submitAnswer($matches[1], $body['answer'] ?? null));
    }
    if (preg_match('#^/api/interviews/([a-f0-9-]{36})$#i', $path, $matches)) {
        if ($method !== 'GET') throw new HttpException(405, 'Method not allowed.');
        jsonResponse(200, $services['interview_service']->getProgress($matches[1]));
    }

    throw new HttpException(404, 'Endpoint not found.');
} catch (HttpException $exception) {
    jsonResponse($exception->status, ErrorResponse::fromException(
        $exception,
        $services['environment'] ?? 'production',
        ($services['debug'] ?? false) === true,
    ));
} catch (Throwable $exception) {
    error_log('JobReady request failed: ' . get_class($exception));
    jsonResponse(500, ['error' => 'An internal error occurred.']);
}
