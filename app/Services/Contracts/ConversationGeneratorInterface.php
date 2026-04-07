<?php

namespace App\Services\Contracts;

interface ConversationGeneratorInterface
{
    public function generate(int $count, string $topic): array;
}