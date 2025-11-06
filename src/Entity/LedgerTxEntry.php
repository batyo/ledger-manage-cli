<?php

namespace App\Entity;

/**
 * ledger_transactions テーブルのエントリを表す Value Object
 */
class LedgerTxEntry
{
    /**
     * @param int $ledgerId
     * @param int $transactionId
     */
    public function __construct(
        public readonly int $ledgerId,
        public readonly int $transactionId
    ) {}

    /**
     * データベースの行配列からインスタンスを生成する
     *
     * @param array $row ['ledger_id'|'ledgerId' => int, 'transaction_id'|'transactionId' => int]
     * @return self
     */
    public static function fromArray(array $row): self
    {
        $ledgerId = $row['ledger_id'] ?? $row['ledgerId'] ?? null;
        $txId = $row['transaction_id'] ?? $row['transactionId'] ?? null;

        if ($ledgerId === null || $txId === null) {
            throw new \InvalidArgumentException('Invalid row data for LedgerTxEntry');
        }

        return new self((int)$ledgerId, (int)$txId);
    }

    /**
     * 配列に変換する（DB挿入やシリアライズ用）
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'ledger_id' => $this->ledgerId,
            'transaction_id' => $this->transactionId,
        ];
    }

    /**
     * 等価比較
     *
     * @param LedgerTxEntry $other
     * @return bool
     */
    public function equals(LedgerTxEntry $other): bool
    {
        return $this->ledgerId === $other->ledgerId && $this->transactionId === $other->transactionId;
    }
}
