<?php

namespace App\Services\Payments;

/** What the customer chose at checkout. Card data never reaches this server: only the gateway's token does. */
class PayRequest
{
    public function __construct(
        public string $method,                 // card | pix
        public bool $recurring,                // "renew automatically"
        public string $email,
        public string $name,
        public string $document,               // CPF/CNPJ, digits only
        public ?string $cardToken = null,      // created in the browser with the gateway's JS (card only)
        public ?string $paymentMethodId = null, // visa, master, elo... (card only)
        public ?string $issuerId = null,
    ) {
    }

    public function documentType(): string
    {
        return strlen($this->document) === 14 ? 'CNPJ' : 'CPF';
    }

    /** @return array{0:string,1:string} first and last name */
    public function nameParts(): array
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $first = array_shift($parts) ?: 'Cliente';

        return [$first, $parts ? implode(' ', $parts) : $first];
    }
}
