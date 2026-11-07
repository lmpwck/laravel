<?php

namespace Lampwick\Laravel\Agent;

use CurlHandle;
use CurlMultiHandle;

/**
 * Sends one batch at a time to `POST {url}/ingest` without blocking the agent's event loop
 * (curl_multi, driven by `poll()`).
 */
final class HttpSender implements Sender
{
    private CurlMultiHandle $multi;

    private ?CurlHandle $handle = null;

    private ?float $retryAfter = null;

    private string $endpoint;

    public function __construct(
        string $url,
        #[\SensitiveParameter] private readonly string $token,
        private readonly float $connectTimeout = 5.0,
        private readonly float $timeout = 30.0,
        private readonly string $userAgent = 'lampwick-laravel',
        private readonly ?string $caBundle = null,
    ) {
        $this->endpoint = rtrim($url, '/').'/ingest';
        $this->multi = curl_multi_init();
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function start(string $body): void
    {
        if ($this->handle !== null) {
            throw new \LogicException('a request is already in flight');
        }

        $this->retryAfter = null;
        $handle = curl_init($this->endpoint);
        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->token,
                'Content-Type: application/json',
                'Accept: application/json',
                'Content-Length: '.strlen($body),
                'Expect:',
            ],
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => (int) ($this->connectTimeout * 1000),
            CURLOPT_TIMEOUT_MS => (int) ($this->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line): int {
                if (stripos($line, 'retry-after:') === 0) {
                    $this->retryAfter = self::parseRetryAfter(trim(substr($line, 12)));
                }

                return strlen($line);
            },
        ]);
        if ($this->caBundle !== null && $this->caBundle !== '') {
            curl_setopt($handle, CURLOPT_CAINFO, $this->caBundle);
        }
        curl_multi_add_handle($this->multi, $handle);
        $this->handle = $handle;
        $this->poll();
    }

    public function busy(): bool
    {
        return $this->handle !== null;
    }

    public function poll(): ?Response
    {
        if ($this->handle === null) {
            return null;
        }

        do {
            $code = curl_multi_exec($this->multi, $running);
        } while ($code === CURLM_CALL_MULTI_PERFORM);

        while ($info = curl_multi_info_read($this->multi)) {
            if ($info['handle'] !== $this->handle) {
                continue;
            }
            $handle = $this->handle;
            $this->handle = null;
            curl_multi_remove_handle($this->multi, $handle);

            if ($info['result'] !== CURLE_OK) {
                return new Response(0, null, curl_error($handle) ?: curl_strerror($info['result']));
            }

            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $body = (string) curl_multi_getcontent($handle);

            return new Response($status, $this->retryAfter, $status >= 300 ? self::errorMessage($body) : null);
        }

        return null;
    }

    public static function parseRetryAfter(string $value): ?float
    {
        if (is_numeric($value)) {
            return max(0.0, (float) $value);
        }
        $at = strtotime($value);

        return $at === false ? null : max(0.0, $at - time());
    }

    private static function errorMessage(string $body): string
    {
        $json = json_decode($body, true);
        if (is_array($json) && is_string($json['error'] ?? null)) {
            return $json['error'];
        }

        return mb_strimwidth(trim($body), 0, 200, '…');
    }
}
