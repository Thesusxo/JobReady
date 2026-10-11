<?php
declare(strict_types=1);

namespace JobReady\Security;

use JobReady\Http\HttpException;
use PDO;

final class DemoAccess
{
    private const SESSION_NAME = 'JOBREADY_DEMO';
    private const SESSION_TTL = 8 * 60 * 60;
    private const INTERVIEW_STARTS_PER_IP_HOUR = 2;
    private const INTERVIEW_STARTS_GLOBAL_PER_DAY = 10;
    private const ANSWERS_PER_SESSION_HOUR = 8;
    private const ANSWERS_PER_IP_HOUR = 20;
    private const ANSWERS_GLOBAL_PER_DAY = 40;
    private const UPLOADS_PER_IP_HOUR = 3;
    private const UPLOADS_GLOBAL_PER_DAY = 20;
    private readonly DemoRateLimiter $limiter;
    /** @var list<string> */
    private readonly array $trustedProxies;

    public function __construct(
        PDO $pdo,
        private readonly string $environment,
        private readonly ?string $accessCodeHash,
        string $trustedProxyIps,
    ) {
        $this->limiter = new DemoRateLimiter($pdo,
            is_string($accessCodeHash) && $accessCodeHash !== '' ? $accessCodeHash : 'local-only-rate-limit-' . JOBREADY_ROOT);
        $this->trustedProxies = array_values(array_filter(array_map('trim', explode(',', $trustedProxyIps)),
            static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP) !== false));
    }

    public function requiresGate(): bool
    {
        return !in_array($this->environment, ['local', 'development'], true)
            || ($this->accessCodeHash !== null && trim($this->accessCodeHash) !== '');
    }

    public function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        session_name(self::SESSION_NAME);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) self::SESSION_TTL);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => !$this->isLocalEnvironment() || $this->isSecureRequest(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        if (!session_start()) {
            throw new HttpException(503, 'Demo access is temporarily unavailable.');
        }
    }

    /** @return array{access_required: bool, authenticated: bool, csrf_token: string|null} */
    public function sessionInfo(): array
    {
        if (!$this->requiresGate()) return ['access_required' => false, 'authenticated' => true, 'csrf_token' => null];
        $ip = $this->clientIp();
        if (!$this->limiter->consume('demo_session_ip', $ip, 60, 60)) {
            throw new HttpException(429, 'Too many demo session checks. Wait before trying again.');
        }
        if (!$this->limiter->consume('demo_session_global', 'all', 1200, 60)) {
            throw new HttpException(429, 'Too many demo session checks. Wait before trying again.');
        }
        if (empty($_COOKIE[self::SESSION_NAME])) {
            return ['access_required' => true, 'authenticated' => false, 'csrf_token' => null];
        }
        $this->startSession();
        $authenticated = $this->isAuthenticated();
        return ['access_required' => true, 'authenticated' => $authenticated,
            'csrf_token' => $authenticated ? $_SESSION['demo_csrf'] : null];
    }

    public function login(mixed $code): array
    {
        if (!$this->requiresGate()) return $this->sessionInfo();
        if (!$this->hasValidVerifier()) {
            throw new HttpException(503, 'Demo access is not configured.');
        }
        if (!$this->isSecureRequest() && !$this->isLocalEnvironment()) {
            throw new HttpException(403, 'Use the secure HTTPS demo link.');
        }
        $ip = $this->clientIp();
        if (!$this->limiter->consume('demo_login_ip', $ip, 10, 3600)
            || !$this->limiter->consume('demo_login_global', 'all', 100, 3600)) {
            throw new HttpException(429, 'Too many demo sign-in attempts. Wait before trying again.');
        }
        if (!is_string($code) || strlen($code) > 256 || !password_verify($code, $this->accessCodeHash)) {
            throw new HttpException(401, 'That demo access code was not accepted.');
        }
        $this->startSession();
        if (!session_regenerate_id(true)) {
            throw new HttpException(503, 'Demo access is temporarily unavailable.');
        }
        $_SESSION = [];
        $_SESSION['demo_authenticated_at'] = time();
        $_SESSION['demo_csrf'] = bin2hex(random_bytes(32));
        return ['access_required' => true, 'authenticated' => true, 'csrf_token' => $_SESSION['demo_csrf']];
    }

    public function requireAuthenticated(): void
    {
        if (!$this->requiresGate()) return;
        $ip = $this->clientIp();
        if (!$this->limiter->consume('demo_api_ip', $ip, 120, 60)
            || !$this->limiter->consume('demo_api_global', 'all', 600, 60)) {
            throw new HttpException(429, 'Too many demo requests. Wait before trying again.');
        }
        if (empty($_COOKIE[self::SESSION_NAME])) {
            if (!$this->limiter->consume('demo_unauthenticated_ip', $ip, 60, 60)) {
                throw new HttpException(429, 'Too many unauthenticated requests. Wait before trying again.');
            }
            throw new HttpException(401, 'Enter the judge access code to continue.');
        }
        $this->startSession();
        if (!$this->isAuthenticated()) throw new HttpException(401, 'Enter the judge access code to continue.');
    }

    public function requireCsrf(): void
    {
        if (!$this->requiresGate()) return;
        $this->startSession();
        $given = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['demo_csrf'] ?? '';
        if (!is_string($given) || !is_string($expected) || strlen($given) !== 64 || !hash_equals($expected, $given)) {
            throw new HttpException(403, 'Refresh the page session and try again.');
        }
    }

    public function logout(): void
    {
        $this->requireAuthenticated();
        $params = session_get_cookie_params();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            setcookie(session_name(), '', ['expires' => time() - 42000, 'path' => $params['path'] ?: '/',
                'secure' => (bool) $params['secure'], 'httponly' => true, 'samesite' => 'Strict']);
        }
        session_destroy();
    }

    public function enforceEndpointLimit(string $path, string $method, ?string $sessionId): void
    {
        if ($method !== 'POST') return;
        $ip = $this->clientIp();
        $limits = match ($path) {
            '/api/interviews' => ['interview_start', $ip, self::INTERVIEW_STARTS_PER_IP_HOUR, 3600,
                'interview_start_global', self::INTERVIEW_STARTS_GLOBAL_PER_DAY, 86400],
            '/api/cv/extract' => ['cv_extract', $ip, self::UPLOADS_PER_IP_HOUR, 3600,
                'cv_extract_global', self::UPLOADS_GLOBAL_PER_DAY, 86400],
            default => null,
        };
        if (preg_match('#^/api/interviews/[a-f0-9-]{36}/answers$#i', $path)) {
            if ($sessionId === null) {
                throw new HttpException(401, 'Enter the judge access code to continue.');
            }
            $limits = ['answer_turn_session', $sessionId, self::ANSWERS_PER_SESSION_HOUR, 3600,
                'answer_turn_global', self::ANSWERS_GLOBAL_PER_DAY, 86400];
            if (!$this->limiter->consume('answer_turn_ip', $ip, self::ANSWERS_PER_IP_HOUR, 3600)) {
                throw new HttpException(429, 'The demo answer limit has been reached. Wait before trying again.');
            }
        }
        if ($limits === null) return;
        [$scope, $identity, $limit, $window, $globalScope, $globalLimit, $globalWindow] = $limits;
        if (!$this->limiter->consume($scope, $identity, $limit, $window)
            || !$this->limiter->consume($globalScope, 'all', $globalLimit, $globalWindow)) {
            throw new HttpException(429, 'The demo request limit has been reached. Wait before trying again.');
        }
    }

    private function isAuthenticated(): bool
    {
        $at = $_SESSION['demo_authenticated_at'] ?? null;
        $csrf = $_SESSION['demo_csrf'] ?? null;
        if (!is_int($at) || time() - $at > self::SESSION_TTL || !is_string($csrf) || !preg_match('/^[a-f0-9]{64}$/', $csrf)) {
            if (isset($_SESSION['demo_authenticated_at'])) $_SESSION = [];
            return false;
        }
        return true;
    }

    private function hasValidVerifier(): bool
    {
        return is_string($this->accessCodeHash) && $this->accessCodeHash !== ''
            && (password_get_info($this->accessCodeHash)['algoName'] ?? 'unknown') !== 'unknown';
    }

    private function isLocalEnvironment(): bool
    {
        return in_array($this->environment, ['local', 'development'], true);
    }

    private function isSecureRequest(): bool
    {
        if (isset($_SERVER['HTTPS']) && in_array(strtolower((string) $_SERVER['HTTPS']), ['on', '1'], true)) return true;
        $remote = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!is_string($remote) || !in_array($remote, $this->trustedProxies, true)) return false;
        // Only trust Cloudflare's scheme header when the direct peer is explicitly trusted.
        $visitor = json_decode((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
        return is_array($visitor) && ($visitor['scheme'] ?? null) === 'https';
    }

    private function clientIp(): string
    {
        $remote = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if (!is_string($remote) || filter_var($remote, FILTER_VALIDATE_IP) === false) $remote = 'unknown';
        if (in_array($remote, $this->trustedProxies, true)) {
            $forwarded = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
            if (is_string($forwarded) && filter_var($forwarded, FILTER_VALIDATE_IP) !== false) return $forwarded;
        }
        return $remote;
    }
}
