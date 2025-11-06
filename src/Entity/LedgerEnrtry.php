<?php

namespace App\Entity;

/**
 * 台帳エンティティクラス
 */
class LedgerEnrtry
{
    /**
     * @param int|null $id 台帳ID（nullの場合は新規作成）
     * @param string $period 台帳の期間（例: '2025-09'）
     * @param TransactionEntry[] $transactions 取引の配列
     */

    public function __construct(
        public readonly ?int $id,
        public readonly string $period, // 例: '2025-09'
        /** @var TransactionEntry[] */
        public readonly array $transactions = []
    ) {}

    /**
     * 新しい取引を追加し、新しいLedgerEnrtryインスタンスを返す
     *
     * @param TransactionEntry $transaction 追加する取引
     * @return LedgerEnrtry 新しい取引が追加された新しい LedgerEnrtry インスタンス。
     */
    public function appendTransaction(TransactionEntry $transaction): LedgerEnrtry
    {
        $newTransactions = $this->transactions;
        $newTransactions[] = $transaction;
        return new LedgerEnrtry($this->id, $this->period, $newTransactions);
    }

    /**
     * 収入の合計を計算する
     *
     * @return float 収入の合計金額
     */
    public function getTotalIncome(): float
    {
        return array_reduce($this->transactions, function($sum, $t) {
            return $sum + ($t->isIncome() ? $t->amount : 0);
        }, 0.0);
    }

    /**
     * 支出の合計を計算する
     *
     * @return float 支出の合計金額
     */
    public function getTotalExpense(): float
    {
        return array_reduce($this->transactions, function($sum, $t) {
            return $sum + ($t->isExpense() ? $t->amount : 0);
        }, 0.0);
    }

    /**
     * 収支を計算する
     *
     * @return float 収入から支出を引いた収支
     */
    public function getBalance(): float
    {
        return $this->getTotalIncome() - $this->getTotalExpense();
    }

    /**
     * 指定されたカテゴリIDで取引をフィルタリングする
     *
     * @param int $categoryId フィルタリングするカテゴリID
     * @return TransactionEntry[] 指定されたカテゴリIDに一致する取引の配列
     */
    public function filterByCategory(int $categoryId): array
    {
        return array_filter($this->transactions, fn($t) => $t->categoryId === $categoryId);
    }

    /**
     * 指定されたアカウントIDで取引をフィルタリングする
     *
     * @param int $accountId フィルタリングするアカウントID
     * @return TransactionEntry[] 指定されたアカウントIDに一致する取引の配列
     */
    public function filterByAccount(int $accountId): array
    {
        return array_filter($this->transactions, fn($t) => $t->accountId === $accountId);
    }
}
