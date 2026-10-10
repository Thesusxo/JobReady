<?php
declare(strict_types=1);

/** Test-only server using disposable PHP files, SQLite, and no private environment. */
final class IsolatedHttpServer
{
    // Valid fictional input is permitted only to test missing-key diagnostics in this keyless fixture.
    public const DIAGNOSTIC_REQUEST = '{"cv_text":"Fictional developer built a PHP inventory application.","job_description":"Junior PHP role working on APIs, debugging, and security.","target_role":"Junior PHP Developer"}';
    private string $fixture;
    private string $address;
    private $process = null;

    public function __construct(string $environmentName = 'local', bool $debug = false)
    {
        $this->fixture = sys_get_temp_dir() . '/jobready-http-' . bin2hex(random_bytes(8));
        try {
            self::ensure(function_exists('proc_open') && function_exists('curl_init'), 'server_prerequisites');
            self::ensure(in_array($environmentName, ['local', 'development', 'production', 'staging'], true), 'fixture_environment_name');
            self::ensure(mkdir($this->fixture, 0700), 'fixture_creation');
            foreach (['public', 'config', 'src', 'scripts'] as $directory) {
                self::copyFixture(dirname(__DIR__, 2) . '/' . $directory, $this->fixture . '/' . $directory);
            }
            if (is_dir(dirname(__DIR__, 2) . '/vendor')) {
                self::copyFixture(dirname(__DIR__, 2) . '/vendor', $this->fixture . '/vendor');
            }
            self::ensure(mkdir($this->fixture . '/uploads', 0700), 'fixture_upload_directory');
            self::ensure(file_put_contents($this->fixture . '/.env', 'APP_ENV=' . $environmentName . "\nAPP_DEBUG=" . ($debug ? 'true' : 'false') . "\nAPP_DB_PATH=data/test.sqlite\nAI_PROVIDER=gemini\nGEMINI_API_KEY=\nOPENAI_API_KEY=\nNEATLOGS_WRITE_KEY=\nNEATLOGS_PROJECT=\n") !== false, 'fixture_environment');

            $reservation = @stream_socket_server('tcp://127.0.0.1:0', $error, $message);
            self::ensure($reservation !== false, 'port_reservation');
            $address = stream_socket_get_name($reservation, false);
            fclose($reservation);
            self::ensure(is_string($address), 'port_detection');
            $this->address = $address;

            // No inherited provider keys, proxy variables, or application configuration.
            $environment = [];
            foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
                $value = getenv($name);
                if ($value !== false) {
                    $environment[$name] = $value;
                }
            }
            $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
            $this->process = proc_open(
                [PHP_BINARY, '-d', 'display_errors=0', '-d', 'upload_max_filesize=5M', '-d', 'post_max_size=6M',
                    '-d', 'upload_tmp_dir=' . $this->fixture . '/uploads', '-S', $address, '-t', $this->fixture . '/public'],
                [0 => ['file', $null, 'r'], 1 => ['file', $null, 'a'], 2 => ['file', $null, 'a']],
                $pipes,
                $this->fixture,
                $environment,
                ['bypass_shell' => true],
            );
            self::ensure(is_resource($this->process), 'server_startup');
            $ready = false;
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && proc_get_status($this->process)['running']) {
                $socket = @stream_socket_client('tcp://' . $address, $error, $message, 0.1);
                if ($socket !== false) {
                    fclose($socket);
                    $ready = true;
                    break;
                }
                usleep(50000);
            }
            self::ensure($ready, 'server_readiness');
        } catch (Throwable $exception) {
            $this->stop();
            throw $exception;
        }
    }

    /** @return array{status: int, json: array<string, mixed>} */
    public function request(string $method, string $path, ?string $body = null): array
    {
        $response = $this->requestRaw($method, $path, $body);
        self::ensure(str_starts_with(strtolower($response['content_type']), 'application/json'), 'response_content_type');
        try {
            $json = json_decode($response['body'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('response_json');
        }
        self::ensure(is_array($json), 'response_json_object');
        return ['status' => $response['status'], 'json' => $json];
    }

    /** @return array{status: int, content_type: string, body: string} */
    public function requestRaw(string $method, string $path, ?string $body = null): array
    {
        // Every creation probe is invalid or keyless; no provider call can be authenticated.
        self::ensure(($method === 'GET' && in_array($path, [
            '/', '/api/dev/status', '/app.html', '/assets/app.css', '/assets/app.mjs',
            '/assets/interview-api.mjs', '/assets/answer-draft.mjs', '/assets/cv-upload.mjs', '/assets/jobready.svg',
        ], true) && $body === null)
            || ($method === 'POST' && $path === '/api/interviews' && in_array($body, ['{}', '{', self::DIAGNOSTIC_REQUEST], true)), 'request_allowlist');
        $curl = curl_init('http://' . $this->address . $path);
        self::ensure($curl !== false, 'http_initialization');
        try {
            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_PROXY => '',
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 5,
            ];
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
                $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            }
            curl_setopt_array($curl, $options);
            $response = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $type = curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
            self::ensure(is_string($response) && curl_errno($curl) === 0, 'http_transport');
        } finally {
            curl_close($curl);
        }
        return ['status' => $status, 'content_type' => is_string($type) ? $type : '', 'body' => $response];
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
        self::removeFixture($this->fixture);
    }

    /** Sends only a generated fictional document to the isolated extraction endpoint. */
    public function upload(string $filename, string $contents, string $clientMime = 'application/octet-stream'): array
    {
        $path = $this->fixture . '/fixture-document';
        self::ensure(file_put_contents($path, $contents) !== false, 'fixture_document');
        $curl = curl_init('http://' . $this->address . '/api/cv/extract');
        try {
            curl_setopt_array($curl, [CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => ['cv_file' => new CURLFile($path, $clientMime, $filename)],
                CURLOPT_RETURNTRANSFER => true, CURLOPT_PROXY => '', CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            self::ensure(is_string($body) && curl_errno($curl) === 0, 'upload_http_transport');
            $json = json_decode($body, true, 8, JSON_THROW_ON_ERROR);
            self::ensure(is_array($json), 'upload_json');
            foreach (new DirectoryIterator($this->fixture . '/uploads') as $entry) {
                self::ensure($entry->isDot(), 'temporary_upload_not_deleted');
            }
            return ['status' => $status, 'json' => $json];
        } finally {
            curl_close($curl);
            unlink($path);
        }
    }

    private static function ensure(bool $condition, string $category): void
    {
        if (!$condition) {
            throw new RuntimeException($category);
        }
    }

    private static function copyFixture(string $source, string $destination): void
    {
        self::ensure(mkdir($destination, 0700, true), 'fixture_directory');
        foreach (new DirectoryIterator($source) as $entry) {
            if ($entry->isDot() || $entry->isLink()) {
                continue;
            }
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isDir()) {
                self::copyFixture($entry->getPathname(), $target);
            } elseif (in_array($entry->getExtension(), ['php', 'html', 'css', 'mjs', 'svg'], true)) {
                self::ensure(copy($entry->getPathname(), $target), 'fixture_copy');
            }
        }
    }

    private static function removeFixture(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir() && !$entry->isLink()) {
                self::removeFixture($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
