<?php
/**
 * VPS API istemcisi — online modda tüm CRUD buradan geçer
 */
class ApiClient {
    private string $base;
    private string $token;
    private int    $timeout;

    public function __construct(string $base, string $token, int $timeout = 10) {
        $this->base    = rtrim($base, '/');
        $this->token   = $token;
        $this->timeout = $timeout;
    }

    public function get(string $path, array $query = []): array {
        $url = $this->base . $path;
        if ($query) $url .= '?' . http_build_query($query);
        return $this->request('GET', $url);
    }

    public function post(string $path, array $data = []): array {
        return $this->request('POST', $this->base . $path, $data);
    }

    public function put(string $path, array $data = []): array {
        return $this->request('PUT', $this->base . $path, $data);
    }

    public function delete(string $path): array {
        return $this->request('DELETE', $this->base . $path);
    }

    private function request(string $method, string $url, array $data = []): array {
        $ctx = stream_context_create([
            'http' => [
                'method'  => $method,
                'header'  => implode("\r\n", [
                    'Authorization: Bearer ' . $this->token,
                    'Content-Type: application/json',
                    'Accept: application/json',
                ]),
                'content' => $data ? json_encode($data) : null,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true],
        ]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) {
            return ['ok' => false, 'mesaj' => 'API bağlantı hatası', '_offline' => true];
        }
        $json = json_decode($body, true);
        return is_array($json) ? $json : ['ok' => false, 'mesaj' => 'Geçersiz API yanıtı'];
    }

    public function getToken(): string { return $this->token; }
}
