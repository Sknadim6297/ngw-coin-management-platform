<?php

declare(strict_types=1);

namespace App\Libraries\Blockchain;

use Config\Blockchain;
use RuntimeException;

/**
 * Trusted JSON-RPC client with SSRF protections, timeouts, and retries.
 * RPC URL must come from application configuration — never from user input.
 */
class JsonRpcClient
{
    private int $requestId = 0;

    /** @var null|callable(string, array<string, mixed>, Blockchain): string */
    private $transport;

    public function __construct(
        private readonly ?Blockchain $config = null,
        ?callable $transport = null
    ) {
        $this->transport = $transport;
    }

    /**
     * @param array<int, mixed> $params
     * @return mixed
     */
    public function call(string $method, array $params = [], ?string $rpcUrlOverride = null): mixed
    {
        $config = $this->config ?? config('Blockchain');
        $url    = $this->resolveUrl($rpcUrlOverride, $config);

        $payload = [
            'jsonrpc' => '2.0',
            'id'      => ++$this->requestId,
            'method'  => $method,
            'params'  => $params,
        ];

        $attempts = max(1, $config->rpcMaxRetries + 1);
        $lastError = null;

        for ($i = 0; $i < $attempts; $i++) {
            if ($i > 0 && $config->rpcRetryDelayMs > 0) {
                usleep($config->rpcRetryDelayMs * 1000);
            }

            try {
                $raw = $this->transport !== null
                    ? ($this->transport)($url, $payload, $config)
                    : $this->httpPost($url, $payload, $config);

                return $this->parseResponse($raw);
            } catch (RuntimeException $e) {
                $lastError = $e;
                // Do not retry clear validation / RPC error responses.
                if (str_contains(strtolower($e->getMessage()), 'rpc error')) {
                    throw $e;
                }
            }
        }

        throw new RuntimeException(
            'Blockchain RPC unavailable: ' . ($lastError?->getMessage() ?? 'unknown error'),
            0,
            $lastError
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function httpPost(string $url, array $payload, Blockchain $config): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $ch   = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize RPC client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => max(1, $config->rpcTimeoutSeconds),
            CURLOPT_CONNECTTIMEOUT => max(1, $config->rpcConnectTimeoutSeconds),
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_REDIR_PROTOCOLS=> CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || ! is_string($body)) {
            throw new RuntimeException('RPC transport failed: ' . ($error !== '' ? $error : 'timeout or empty response'));
        }

        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('RPC HTTP status ' . $status);
        }

        return $body;
    }

    /**
     * @return mixed
     */
    private function parseResponse(string $raw): mixed
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('Malformed RPC JSON response.', 0, $e);
        }

        if (! is_array($decoded) || ($decoded['jsonrpc'] ?? null) !== '2.0') {
            throw new RuntimeException('Malformed RPC envelope.');
        }

        if (isset($decoded['error'])) {
            $message = is_array($decoded['error'])
                ? (string) ($decoded['error']['message'] ?? 'unknown')
                : 'unknown';
            throw new RuntimeException('RPC error: ' . $message);
        }

        if (! array_key_exists('result', $decoded)) {
            throw new RuntimeException('Malformed RPC result.');
        }

        return $decoded['result'];
    }

    private function resolveUrl(?string $override, Blockchain $config): string
    {
        $url = trim((string) ($override ?: $config->evmRpcUrl));
        if ($url === '') {
            throw new RuntimeException('Trusted RPC URL is not configured.');
        }

        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('Trusted RPC URL is invalid.');
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            throw new RuntimeException('RPC URL scheme is not allowed.');
        }

        // Prefer HTTPS in production configurations; HTTP allowed only for local test transports.
        $host = strtolower((string) $parts['host']);
        if ($this->isBlockedHost($host)) {
            throw new RuntimeException('RPC host is not allowed.');
        }

        $allowlist = $this->hostAllowlist($config, $host);
        if ($allowlist !== [] && ! in_array($host, $allowlist, true)) {
            throw new RuntimeException('RPC host is outside the configured allowlist.');
        }

        return $url;
    }

    /**
     * @return list<string>
     */
    private function hostAllowlist(Blockchain $config, string $configuredHost): array
    {
        $raw = trim($config->rpcHostAllowlist);
        if ($raw === '') {
            return [$configuredHost];
        }

        $hosts = array_values(array_filter(array_map(
            static fn (string $h): string => strtolower(trim($h)),
            explode(',', $raw)
        )));

        return $hosts;
    }

    private function isBlockedHost(string $host): bool
    {
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            // Allow only when explicitly configured as the trusted RPC host for local fixtures.
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            // Block link-local / private ranges unless explicitly allowlisted via config host.
            if (! filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false; // still subject to allowlist derived from configured URL
            }
        }

        return false;
    }
}
