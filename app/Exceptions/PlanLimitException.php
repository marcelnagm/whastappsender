<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an action is not allowed by the user's plan or because the account is blocked for non-payment.
 * Rendered as a redirect to the billing page (web) or HTTP 402 (JSON).
 */
class PlanLimitException extends RuntimeException
{
    public const FEATURE = 'feature_not_in_plan';
    public const LIMIT = 'plan_limit_reached';
    public const BLOCKED = 'account_blocked';

    public function __construct(string $message, public string $reason = self::LIMIT, public ?string $key = null)
    {
        parent::__construct($message);
    }

    public static function feature(string $feature): self
    {
        return new self(__('Your plan does not include this feature. Upgrade to use it.'), self::FEATURE, $feature);
    }

    public static function limit(string $key): self
    {
        return new self(__('You reached the limit of your plan for this resource. Upgrade to continue.'), self::LIMIT, $key);
    }

    public static function blocked(): self
    {
        return new self(__('Your account is blocked because of an overdue payment. Pay the open invoice to restore access.'), self::BLOCKED);
    }
}
