<?php

declare(strict_types=1);

namespace SimpleVerifier;

/** Base class for every error this package throws. */
class SimpleVerifierException extends \RuntimeException
{
    /** @param array<string,mixed>|null $body */
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        public readonly ?array $body = null,
        public readonly ?float $retryAfter = null,
    ) {
        parent::__construct($message, $status ?? 0);
    }
}

/** 400: the email or phone parameter is missing. */
final class BadRequestException extends SimpleVerifierException {}

/** 401: the API key is missing, wrong or revoked. */
final class AuthException extends SimpleVerifierException {}

/**
 * 402: the plan has no API access, the subscription is not active, or a yearly
 * plan used this month's calls. `retryAfter` is set on the quota case only.
 */
final class PaymentRequiredException extends SimpleVerifierException {}

/** 429: more than 120 calls a minute on one key. Retried automatically first. */
final class RateLimitException extends SimpleVerifierException {}

/** 503: the verification service is briefly unavailable. Retried automatically first. */
final class ServiceUnavailableException extends SimpleVerifierException {}
