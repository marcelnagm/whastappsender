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
        'pagarme' => [
            'label' => 'Stone (Pagar.me)',
            'fees' => ['pix' => 'sob negociação', 'card' => '~3,19% (tabela) / negociável', 'settlement' => 'conforme contrato'],
            'docs' => 'https://docs.pagar.me',
            'keys' => 'https://dash.pagar.me',
        ],
        'stripe' => [
            'label' => 'Stripe',
            'fees' => ['pix' => '1,19% (sob convite)', 'card' => '3,99% + R$ 0,39', 'settlement' => 'conforme a conta'],
            'docs' => 'https://docs.stripe.com/payments/pix',
            'keys' => 'https://dashboard.stripe.com/apikeys',
        ],
        'pagbank' => [
            'label' => 'PagBank',
            'fees' => ['pix' => 'até 1,89%', 'card' => '4,99% + R$ 0,40 (14 dias) · 3,99% + R$ 0,40 (30 dias)', 'settlement' => '14 ou 30 dias'],
            'docs' => 'https://developer.pagbank.com.br',
            'keys' => 'https://minhaconta.pagbank.com.br',
        ],
        'mercadopago' => [
            'label' => 'Mercado Pago',
            'fees' => ['pix' => '0,99%', 'card' => '4,98% (na hora) · 3,98% (30 dias)', 'settlement' => 'na hora, 14 ou 30 dias'],
            'docs' => 'https://www.mercadopago.com.br/developers',
            'keys' => 'https://www.mercadopago.com.br/developers/panel',
        ],
    ],
];
