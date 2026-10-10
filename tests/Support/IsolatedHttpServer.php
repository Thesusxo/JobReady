<?php
declare(strict_types=1);

/** Test-only server using disposable PHP files, SQLite, and no private environment. */
final class IsolatedHttpServer
{
    private string $fixture;
    private string $address;
    private $process = null;

    public function __construct()
    {
        $this->fixture = sys_get_temp_dir() . '/jobready-http-' . bin2hex(random_bytes(8));
        try {
            self::ensure(function_exists('proc_open') && function_exists('curl_init'), 'server_prerequisites');
            self::ensure(mkdir($this->fixture, 0700), 'fixture_creation');
            foreach (['public', 'config', 'src'] as $directory) {
                self::copyFixture(dirname(__DIR__, 2) . '/' . $directory, $this->fixture . '/' . $directory);
            }
            self::ensure(file_put_contents($this->fixture . '/.env', "APP_ENV=local\nAPP_DB_PATH=data/test.sqlite\nAI_PROVIDER=gemini\nGEMINI_API_KEY=\nOPENAI_API_KEY=\nNEATLOGS_WRITE_KEY=\nNEATLOGS_PROJECT=\n") !== false, 'fixture_environment');

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
                [PHP_BINARY, '-d', 'display_errors=0', '-S', $address, '-t', $this->fixture . '/public'],
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
        // Limit the fixture to health and invalid creation probes, never valid AI requests.
        self::ensure(($method === 'GET' && $path === '/' && $body === null)
            || ($method === 'POST' && $path === '/api/interviews' && in_array($body, ['{}', '{'], true)), 'request_allowlist');
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
        self::ensure(is_string($type) && str_starts_with(strtolower($type), 'application/json'), 'response_content_type');
        try {
            $json = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new RuntimeException('response_json');
        }
        self::ensure(is_array($json), 'response_json_object');
        return ['status' => $status, 'json' => $json];
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
            } elseif ($entry->getExtension() === 'php') {
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
