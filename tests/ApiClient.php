<?php

// Testler için gerçek HTTP istekleri atan küçük bir istemci — çalışan bir
// `php -S`/web sunucusuna ihtiyaç duyar (bkz. README / GitHub Actions workflow).
class ApiClient
{
    private string $baseUrl;
    private string $cookieFile;
    public ?string $csrfToken = null;

    public function __construct(string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieFile = tempnam(sys_get_temp_dir(), 'phpunit_cookies_');
    }

    public function __destruct()
    {
        if (is_file($this->cookieFile)) {
            @unlink($this->cookieFile);
        }
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $ch = curl_init($this->baseUrl . $path);
        $headers = ['Content-Type: application/json'];
        if ($this->csrfToken) {
            $headers[] = 'X-CSRF-Token: ' . $this->csrfToken;
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_COOKIEJAR => $this->cookieFile,
            CURLOPT_COOKIEFILE => $this->cookieFile,
            CURLOPT_RETURNTRANSFER => true,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch);
        if ($response === false) {
            throw new RuntimeException('İstek başarısız: ' . curl_error($ch));
        }
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $statusCode, 'data' => json_decode($response, true) ?? []];
    }

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $body = []): array
    {
        return $this->request('POST', $path, $body);
    }

    public function put(string $path, array $body = []): array
    {
        return $this->request('PUT', $path, $body);
    }

    public function delete(string $path, array $body = []): array
    {
        return $this->request('DELETE', $path, $body);
    }

    public function register(string $username, string $email, string $password): array
    {
        $res = $this->post('/api/register.php', ['username' => $username, 'email' => $email, 'password' => $password]);
        if ($res['data']['success'] ?? false) {
            $this->csrfToken = $res['data']['csrf_token'] ?? null;
        }
        return $res;
    }

    public function login(string $email, string $password): array
    {
        $res = $this->post('/api/login.php', ['email' => $email, 'password' => $password]);
        if ($res['data']['success'] ?? false) {
            $this->csrfToken = $res['data']['csrf_token'] ?? null;
        }
        return $res;
    }
}
