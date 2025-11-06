<?php

namespace App\Entity;

/**
 * カテゴリエンティティクラス
 */
class CategoryEntry
{
    const TYPE_INCOME = 1;
    const TYPE_EXPENSE = 2;
    const TYPE_TRANSFER = 3;

    /**
     * @param int|null $id カテゴリID（nullの場合は新規作成）
     * @param string $name カテゴリ名
     * @param int $categoryType カテゴリタイプ（1: 収入, 2: 支出, 3: 振替）
     */

    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $categoryType
    ) {}

    /**
     * カテゴリが収入カテゴリかどうかを判定する
     *
     * @return bool 収入カテゴリであれば true、そうでなければ false
     */
    public function isIncomeCategory(): bool
    {
        return $this->categoryType === 1; // 1: INCOME
    }

    /**
     * カテゴリが支出カテゴリかどうかを判定する
     *
     * @return bool 支出カテゴリであれば true、そうでなければ false
     */
    public function isExpenseCategory(): bool
    {
        return $this->categoryType === 2; // 2: EXPENSE
    }

    /**
     * カテゴリが振替カテゴリかどうかを判定する
     */
    public function isTransferCategory(): bool
    {
        return $this->categoryType === 3; // 3: TRANSFER
    }

    /**
     * カテゴリー名を変更し、新しい CategoryEntry インスタンスを返す
     *
     * @param string $newName 新しいカテゴリー名
     * @return CategoryEntry 更新されたカテゴリー名を持つ新しい CategoryEntryインスタンス
     */
    public function changeCategoryName(string $newName): CategoryEntry
    {
        return new CategoryEntry(
            $this->id,
            $newName,
            $this->categoryType
        );
    }
}
