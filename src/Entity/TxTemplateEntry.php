<?php

namespace App\Entity;

class TxTemplateEntry
{
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly float $amount,
        public readonly int $categoryId,
        public readonly int $accountId,
        public readonly int $transactionType,
        public readonly ?string $note = null
    ) {}
}
