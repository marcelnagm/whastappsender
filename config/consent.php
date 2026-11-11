<?php

$list = fn (string $v) => array_values(array_filter(array_map(fn ($k) => mb_strtolower(trim($k)), explode(',', $v))));

return [
    // A message that is exactly one of these words (any case) opts the person out: no more campaigns, no AI replies, on every channel they use.
    'optout_keywords' => $list((string) env('CONSENT_OPTOUT_KEYWORDS', '#sair,/stop,#parar,#stop,parar,stop')),

    // Whole messages in plain language that opt out too (accents and punctuation ignored); add yours with CONSENT_OPTOUT_PHRASES
    'optout_phrases' => array_values(array_unique(array_merge(
        ['descadastrar', 'cancelar inscricao', 'sair da lista', 'quero sair da lista', 'nao quero mais receber', 'nao quero receber', 'unsubscribe', 'remove me', 'darme de baja', 'no quiero recibir mas'],
        $list((string) env('CONSENT_OPTOUT_PHRASES', ''))
    ))),

    // ...and one of these opts them in (a Telegram bot's /start counts)
    'optin_keywords' => $list((string) env('CONSENT_OPTIN_KEYWORDS', '#entrar,#aceito,/start')),
];
