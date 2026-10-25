<?php

declare(strict_types=1);

namespace SimpleVerifier;

/**
 * Thin client for https://simpleverifier.com/docs/api.
 *
 * Email results use status "valid", "risky" or "invalid"; only "valid" is a
 * confirmation. The phone endpoint never says a number is in service.
 */
final class Client
{
    public const VERSION = '0.1.0';
    public const DEFAULT_BASE_URL = 'https://simpleverifier.com';

    private string $apiKey;
    private string $baseUrl;
    /** @var callable(float):void */
    private $sleeper;

    /**
     * @param array{base_url?:string,timeout?:float,max_retries?:int,base_delay?:float,max_delay?:float,sleep?:callable} $options
     */
    public function __construct(?string $apiKey = null, private array $options = [])
    {
        $key = $apiKey ?? (getenv('SIMPLEVERIFIER_KEY') ?: '');
        if ($key === '') {
            throw new SimpleVerifierException('Missing API key. Pass one or set SIMPLEVERIFIER_KEY. Create one at https://simpleverifier.com/api-keys');
        }
        $this->apiKey = $key;
        $this->baseUrl = rtrim($options['base_url'] ?? self::DEFAULT_BASE_URL, '/');
        $this->sleeper = $options['sleep'] ?? static fn (float $s) => usleep((int) ($s * 1_000_000));
    }

    /** @return array<string,mixed> */
    public function verifyEmail(string $email): array
    {
        $email = trim($email);
        if ($email === '') {
            throw new BadRequestException('email must be a non-empty string', 400);
        }
        return $this->request('/api/v1/verify', ['email' => $email]);
    }

    /** @return array<string,mixed> */
    public function verifyPhone(string $phone, ?string $country = null, ?bool $live = null): array
    {
        $phone = trim($phone);
        if ($phone === '') {
            throw new BadRequestException('phone must be a non-empty string', 400);
        }
        $body = ['phone' => $phone];
        if ($country !== null && $country !== '') {
            $body['country'] = strtoupper(trim($country));
        }
        if ($live !== null) {
            $body['live'] = $live;
        }
        return $this->request('/api/v1/phone', $body);
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function request(string $path, array $body): array
    {
        $maxRetries = max(0, (int) ($this->options['max_retries'] ?? 2));
        for ($attempt = 0;; $attempt++) {
            try {
                return $this->send($path, $body);
            } catch (RateLimitException | ServiceUnavailableException $e) {
                if ($attempt >= $maxRetries) {
                    throw $e;
                }
                ($this->sleeper)($this->backoff($attempt, $e));
            }
        }
    }

    private function backoff(int $attempt, SimpleVerifierException $e): float
    {
        $max = (float) ($this->options['max_delay'] ?? 30.0);
        $wait = min($max, (float) ($this->options['base_delay'] ?? 1.0) * (2 ** $attempt));
        return min($max, max($wait, $e->retryAfter ?? 0.0));
    }

    /** @param array<string,mixed> $body @return array<string,mixed> */
    private function send(string $path, array $body): array
    {
        $headers = [];
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) ceil($this->options['timeout'] ?? 30.0),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'Accept: application/json',
                'User-Agent: simpleverifier-php/' . self::VERSION,
            ],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new SimpleVerifierException('Network error: ' . curl_error($ch));
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        $parsed = json_decode((string) $raw, true);
        $parsed = is_array($parsed) ? $parsed : null;
        if ($status >= 200 && $status < 300) {
            if ($parsed === null) {
                throw new SimpleVerifierException('Unexpected response from SimpleVerifier', $status);
            }
            return $parsed;
        }

        $retryAfter = isset($headers['retry-after']) && is_numeric($headers['retry-after'])
            ? (float) $headers['retry-after']
            : (isset($parsed['retry_after_seconds']) && is_numeric($parsed['retry_after_seconds']) ? (float) $parsed['retry_after_seconds'] : null);
        $message = is_string($parsed['error'] ?? null) ? $parsed['error'] : "Request failed with status $status";
        throw match ($status) {
            400 => new BadRequestException($message, $status, $parsed, $retryAfter),
            401 => new AuthException($message, $status, $parsed, $retryAfter),
            402 => new PaymentRequiredException($message, $status, $parsed, $retryAfter),
            429 => new RateLimitException($message, $status, $parsed, $retryAfter),
            503 => new ServiceUnavailableException($message, $status, $parsed, $retryAfter),
            default => new SimpleVerifierException($message, $status, $parsed, $retryAfter),
        };
    }
}
