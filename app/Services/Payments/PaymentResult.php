<?php

namespace App\Services\Payments;

class PaymentResult
{
    public const PAID = 'paid';
    public const PENDING = 'pending';
    public const REJECTED = 'rejected';
    public const ERROR = 'error';

    /**
     * @param array{qr_code:?string,qr_base64:?string,ticket_url:?string,expires_at:?string}|null $pix
     */
    public function __construct(
        public string $status,
        public string $message = '',
        public ?string $gatewayId = null,
        public ?array $pix = null,
        public ?string $recurringId = null,
        public ?string $paymentType = null,   // credit_card | debit_card | bank_transfer ...
    ) {
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'message' => $this->message,
            'pix' => $this->pix,
        ], fn ($v) => $v !== null);
    }
}
