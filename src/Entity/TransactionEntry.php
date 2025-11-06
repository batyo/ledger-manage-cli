<?php

namespace App\Entity;

/**
 * 取引エンティティクラス
 */
class TransactionEntry
{
    const TYPE_INCOME = 1;
    const TYPE_EXPENSE = 2;
    const TYPE_TRANSFER = 3;

    const TX_TYPE_LIST = [
        self::TYPE_INCOME,
        self::TYPE_EXPENSE,
        self::TYPE_TRANSFER
    ];

    /**
     * @param int|null $id 取引ID（nullの場合は新規作成）
     * @param \DateTimeImmutable $date 取引日
     * @param float $amount 金額
     * @param int $categoryId カテゴリID
     * @param int $accountId アカウントID
     * @param int $transactionType 取引タイプ（1: 収入, 2: 支出, 3: 振替）
     * @param string|null $note メモ（任意）
     * @param int|null $transferGroupId 振替グループID（振替時は同じグループを割当てる）
     */

    public function __construct(
        public readonly ?int $id,
        public readonly \DateTimeImmutable $date,
        public readonly float $amount,
        public readonly int $categoryId,
        public readonly int $accountId,
        public readonly int $transactionType,
        public readonly ?string $note = null,
        public readonly ?int $transferGroupId = null,
    ) {}

    /**
     * 取引が収入かどうかを判定する
     *
     * @return bool 収入であれば true、そうでなければ false
     */
    public function isIncome(): bool
    {
        return $this->transactionType === 1; // 1: INCOME
    }

    /**
     * 取引が支出かどうかを判定する
     *
     * @return bool 支出であれば true、そうでなければ false
     */
    public function isExpense(): bool
    {
        return $this->transactionType === 2; // 2: EXPENSE
    }

    /**
     * 取引が振替かどうかを判定する
     *
     * @return bool 振替であれば true、そうでなければ false
     */
    public function isTransfer(): bool
    {
        return $this->transactionType === 3; // 3: TRANSFER
    }

    /**
     * 取引のカテゴリを変更し、新しいTransactionEntryインスタンスを返す
     *
     * @param int $newCategoryId 新しいカテゴリID
     * @return TransactionEntry 更新されたカテゴリIDを持つ新しい TransactionEntry インスタンス。
     */
    public function changeCategory(int $newCategoryId): TransactionEntry
    {
        return new TransactionEntry(
            $this->id,
            $this->date,
            $this->amount,
            $newCategoryId,
            $this->accountId,
            $this->transactionType,
            $this->note,
            $this->transferGroupId
        );
    }


    /**
     * 取引のアカウントを変更し、新しいTransactionEntryインスタンスを返す
     *
     * @param int $newAccountId 新しいアカウントID
     * @return TransactionEntry 更新されたアカウントIDを持つ新しい TransactionEntry インスタンス。
     */
    public function changeAccount(int $newAccountId): TransactionEntry
    {
        return new TransactionEntry(
            $this->id,
            $this->date,
            $this->amount,
            $this->categoryId,
            $newAccountId,
            $this->transactionType,
            $this->note,
            $this->transferGroupId
        );
    }


    /**
     * 取引の金額を調整し、新しいTransactionEntryインスタンスを返す
     *
     * @param float $newAmount 新しい金額
     * @return TransactionEntry 更新された金額を持つ新しい TransactionEntry インスタンス。
     */
    public function adjustAmount(float $newAmount): TransactionEntry
    {
        return new TransactionEntry(
            $this->id,
            $this->date,
            $newAmount,
            $this->categoryId,
            $this->accountId,
            $this->transactionType,
            $this->note,
            $this->transferGroupId
        );
    }
}
