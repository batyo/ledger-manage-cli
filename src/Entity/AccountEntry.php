<?php

namespace App\Entity;

/**
 * アカウントエンティティクラス
 */
class AccountEntry
{
    const TYPE_CASH = 1;
    const TYPE_BANK = 2;
    const TYPE_CREDIT_CARD = 3;
    const TYPE_E_WALLET = 4;
    const TYPE_CRYPTO = 5;

    const ACCOUNT_TYPE_NAME = [
        self::TYPE_CASH => 'cash',          // 現金
        self::TYPE_BANK => 'bank',         // 銀行
        self::TYPE_CREDIT_CARD => 'credit_card',  // クレジットカード
        self::TYPE_E_WALLET => 'e_wallet',      // 電子マネー
        self::TYPE_CRYPTO => 'crypto',       // 暗号資産
    ];

    const ACCOUNT_LIST = [
        self::TYPE_CASH,
        self::TYPE_BANK,
        self::TYPE_CREDIT_CARD,
        self::TYPE_E_WALLET,
        self::TYPE_CRYPTO
    ];

    /**
     * @param int|null $id アカウントID（nullの場合は新規作成）
     * @param string $name アカウント名
     * @param int $accountType アカウントタイプ（1: 現金, 2: 銀行, 3: クレジットカード, 4: 電子マネー, 5:暗号資産）
     * @param float $balance 残高
     */

    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly int $accountType,
        public readonly float $balance
    ) {}

    /**
     * 指定された金額をアカウントに入金し、新しいAccountEntryインスタンスを返す
     *
     * @param float $amount 入金する金額
     * @return AccountEntry 更新された残高を持つ新しい AccountEntry インスタンス。
     */
    public function deposit(float $amount): AccountEntry
    {
        return new AccountEntry(
            $this->id,
            $this->name,
            $this->accountType,
            $this->balance + $amount
        );
    }

    /**
     * 指定された金額をアカウントから引き出し、新しいAccountEntryインスタンスを返す
     *
     * @param float $amount 引き出す金額
     * @return AccountEntry 更新された残高を持つ新しい AccountEntry インスタンス。
     */
    public function withdraw(float $amount): AccountEntry
    {
        return new AccountEntry(
            $this->id,
            $this->name,
            $this->accountType,
            $this->balance - $amount
        );
    }

    /**
     * 指定された金額を別のアカウントに振替し、更新された両方のAccountEntryインスタンスを返す
     * 
     * 例: [$updatedFrom, $updatedTo] = $fromAccount->transfer(1000, $toAccount);
     *
     * @param float $amount 振替する金額
     * @param AccountEntry $toAccount 振替先のアカウントエントリ
     * @return array 更新された両方の AccountEntry インスタンス。[0]が振替元、[1]が振替先
     */
    public function transfer(float $amount, AccountEntry $toAccount): array
    {
        $updatedFromAccount = $this->withdraw($amount);
        $updatedToAccount = $toAccount->deposit($amount);

        return [$updatedFromAccount, $updatedToAccount];
    }

    /**
     * 実際の残高に調整し、新しいAccountEntryインスタンスを返す
     *
     * @param float $actualBalance 実際の残高
     * @return AccountEntry 更新された残高を持つ新しい AccountEntry インスタンス。
     */
    public function adjustBalance(float $actualBalance): AccountEntry
    {
        return new AccountEntry(
            $this->id,
            $this->name,
            $this->accountType,
            $actualBalance
        );
    }


    /**
     * アカウントタイプ名を取得する
     *
     * @return string アカウントタイプ名
     */
    public function getAccountTypeName(): string
    {
        return self::ACCOUNT_TYPE_NAME[$this->accountType];
    }
}
