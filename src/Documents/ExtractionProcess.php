<?php
declare(strict_types=1);

namespace JobReady\Documents;

final class ExtractionProcess
{
    public function extract(string $path, string $format): string
    {
        if (!function_exists('proc_open')) {
            throw new DocumentException('unavailable');
        }
        $binary = in_array(PHP_SAPI, ['cli', 'cli-server'], true) ? PHP_BINARY
            : PHP_BINDIR . '/php' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        if (!is_executable($binary)) {
            throw new DocumentException('unavailable');
        }
        $environment = [];
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if ($value !== false) {
                $environment[$name] = $value;
            }
        }
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $process = @proc_open([
            $binary, '-d', 'memory_limit=96M', '-d', 'max_execution_time=8',
            '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'error_reporting=0',
            '-d', 'allow_url_fopen=0', '-d', 'allow_url_include=0',
            '-d', 'auto_prepend_file=', '-d', 'auto_append_file=',
            '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec,dl,curl_exec,curl_multi_exec',
            dirname(__DIR__, 2) . '/scripts/extract-document.php', $path, $format,
        ], [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'a']], $pipes,
            dirname(__DIR__, 2), $environment, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new DocumentException('unavailable');
        }
        $output = '';
        $exitCode = -1;
        try {
            stream_set_blocking($pipes[1], false);
            $deadline = microtime(true) + 8;
            while (true) {
                $output .= stream_get_contents($pipes[1]);
                if (strlen($output) > 512 * 1024 || microtime(true) > $deadline) {
                    throw new DocumentException('too_complex');
                }
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    $output .= stream_get_contents($pipes[1]);
                    break;
                }
                usleep(10000);
            }
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            fclose($pipes[1]);
            proc_close($process);
        }
        if ($exitCode !== 0 || strlen($output) > 512 * 1024) {
            throw new DocumentException('too_complex');
        }
        $result = json_decode($output, true, 8);
        if (!is_array($result)) {
            throw new DocumentException('malformed');
        }
        if (isset($result['error']) && is_string($result['error'])) {
            throw new DocumentException($result['error']);
        }
        if (!isset($result['text']) || !is_string($result['text']) || strlen($result['text']) > DocumentExtractor::MAX_TEXT_BYTES) {
            throw new DocumentException('malformed');
        }
        return $result['text'];
    }
}
