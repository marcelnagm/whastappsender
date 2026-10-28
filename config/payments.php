<?php

/*
| Payment providers offered to customers (Admin > Payment gateway). Fees are the public list prices found in
| Sep/2026 (Pix / credit card in one payment / settlement) and are shown to admins only as orientation:
| always confirm them with the provider, they change and can be negotiated.
*/
return [
    'providers' => [
        'asaas' => [
            'label' => 'Asaas',
            'fees' => ['pix' => 'R$ 1,99', 'card' => '2,99% + R$ 0,49', 'settlement' => 'D+32 (cartão)'],
            'docs' => 'https://docs.asaas.com',
            'keys' => 'https://www.asaas.com/customerApiAccessToken/index',
        ],
        'efi' => [
            'label' => 'Efí (Gerencianet)',
            'fees' => ['pix' => '1,19%', 'card' => '3,49%', 'settlement' => 'até 31 dias (cartão)'],
            'docs' => 'https://dev.efipay.com.br',
            'keys' => 'https://sejaefi.com.br',
        ],
        'abacatepay' => [
            'label' => 'AbacatePay',
            'fees' => ['pix' => 'R$ 0,80', 'card' => '3,50% + R$ 0,60', 'settlement' => 'conforme operação'],
            'docs' => 'https://docs.abacatepay.com',
            'keys' => 'https://abacatepay.com',
        ],
        'mercadopago' => [
            'label' => 'Mercado Pago',
            'fees' => ['pix' => '0,99%', 'card' => '4,98% (na hora) · 3,98% (30 dias)', 'settlement' => 'na hora, 14 ou 30 dias'],
            'docs' => 'https://www.mercadopago.com.br/developers',
            'keys' => 'https://www.mercadopago.com.br/developers/panel',
        ],
    ],
];
