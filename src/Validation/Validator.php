<?php

namespace App\Validation;

use App\Entity\AccountEntry;
use App\Entity\CategoryEntry;
use App\Entity\TransactionEntry;
use App\Repository\RepositoryInterface;

/**
 * 入力データの検証を行うクラス
 */
class Validator
{
    /**
     * 金額が正の数であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 正の数であれば true、そうでなければ false
     */
    public static function validateAmount($value): bool
    {
        return is_numeric($value) && $value > 0;
    }

    /**
     * 日付が 'Y-m-d' 形式であること、有効な日付であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 'Y-m-d' 形式であれば true、そうでなければ false
     */
    public static function validateDate($value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false) return false;
        return $date->format('Y-m-d') === $value;
    }


    /**
     * 日付が 'Y-m' 形式であること、有効な日付であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 'Y-m' 形式であれば true、そうでなければ false
     */
    public static function validateDateInYM($value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m', $value);
        if ($date === false) return false;
        return $date->format('Y-m') === $value;
    }


    /**
     * 取引IDが正の整数であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 正の整数であれば true、そうでなければ false
     */
    public static function validateTxId($value): bool
    {
        return is_int($value) && $value > 0;
    }


    /**
     * 取引IDの存在チェック
     *
     * @param integer $id
     * @param RepositoryInterface $repo
     * @return boolean 取引が見つかれば true 見つからない場合は false
     */
    public static function isFoundTxId(int $id, RepositoryInterface $repo): bool
    {
        $entry = $repo->fetchTransactionById($id);
        if ($entry === null) return false;
        return true;
    }


    /**
     * カテゴリIDが正の整数であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 正の整数であれば true、そうでなければ false
     */
    public static function validateCategoryId($value): bool
    {
        if (is_numeric($value)) $value = (int)$value;
        return is_int($value) && $value > 0;
    }


    /**
     * カテゴリIDの存在チェック
     *
     * @param integer $id
     * @param RepositoryInterface $repo
     * @return bool カテゴリが見つかった場合は true 見つからない場合は false
     */
    public static function isFoundCategoryId(int $id, RepositoryInterface $repo): bool
    {
        $entry = $repo->fetchCategoryById($id);
        if ($entry === null) return false;
        return true;
    }


    /**
     * カテゴリ名が文字列であることを検証する
     *
     * @param mixed $value
     * @return boolean
     */
    public static function validateCategoryName(mixed $value): bool
    {
        return is_string($value) && strlen($value) > 0;
    }


    /**
     * カテゴリタイプが有効な値であることを検証する
     *
     * @param mixed $value
     * @return boolean
     */
    public static function validateCategoryType(mixed $value): bool
    {
        // 1: INCOME, 2: EXPENSE, 3: TRANSFER
        return in_array($value, [CategoryEntry::TYPE_INCOME, CategoryEntry::TYPE_EXPENSE, CategoryEntry::TYPE_TRANSFER], true);
    }


    /**
     * アカウントIDが正の整数であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 正の整数であれば true、そうでなければ false
     */
    public static function validateAccountId($value): bool
    {
        return (is_int($value) || is_numeric($value)) && $value > 0;
    }


    /**
     * アカウントIDの存在チェック
     *
     * @param integer $id
     * @param RepositoryInterface $repo
     * @return bool アカウントが存在する場合は true 見つからない場合は false
     */
    public static function isFoundAccountId(int $id, RepositoryInterface $repo): bool
    {
        $entry = $repo->fetchAccountById($id);
        if ($entry === null) return false;
        return true;
    }


    /**
     * アカウント名が有効であることを検証する
     *
     * @param mixed $value
     * @return boolean
     */
    public static function validateAccountName(mixed $value): bool
    {
        return is_string($value) && strlen($value) > 0;
    }


    /**
     * アカウントタイプが有効であることを検証する
     *
     * @param mixed $value
     * @return boolean
     */
    public static function validateAccountType(mixed $value): bool
    {
        return in_array($value, AccountEntry::ACCOUNT_LIST, true);
    }


    /**
     * アカウント残高が有効であることを検証する
     *
     * @param mixed $value
     * @return boolean
     */
    public static function validateAccountBalance(mixed $value): bool
    {
        return is_float($value) && $value >= 0.0;
    }


    /**
     * 取引タイプが有効な値であることを検証する
     *
     * @param mixed $value 検証する値
     * @return bool 有効な取引タイプであれば true、そうでなければ false
     */
    public static function validateTransactionType($value): bool
    {
        if (is_numeric($value)) $value = (int)$value;
        return in_array($value, TransactionEntry::TX_TYPE_LIST, true); // 1: INCOME, 2: EXPENSE, 3: TRANSFER
    }
}
