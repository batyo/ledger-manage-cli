<?php

namespace App\Repository;

use App\Entity\TransactionEntry;
use App\Entity\CategoryEntry;
use App\Entity\AccountEntry;
use App\Entity\LedgerEnrtry;

/**
 * リポジトリインターフェース
 */
interface RepositoryInterface
{
    // 初期化

    /**
     * データベースの初期化を行う
     */
    public function init(): void;

    // Transaction

    /**
     * 取引を挿入する
     *
     * @param TransactionEntry $entry 挿入する取引エントリ
     * @return int 挿入された取引のID
     */
    public function insertTransaction(TransactionEntry $entry): int;


    /**
     * 取引を更新する
     *
     * @param TransactionEntry $entry 更新する取引エントリ
     */
    public function updateTransaction(TransactionEntry $entry): void;


    /**
     * 取引を削除する
     *
     * @param TransactionEntry $entry 削除する取引エントリ
     */
    public function deleteTransaction(TransactionEntry $entry): void;


    /**     
     * 取引を条件でフィルタリングして取得する
     *
     * @param array $filter フィルタリング条件の配列
     * @return TransactionEntry[] 条件に一致する取引の配列
     */
    public function fetchTransactions(array $filter = []): array;


    /**
     * IDで取引を取得する
     *
     * @param int $id 取引ID
     * @return TransactionEntry|null 取引エントリ、存在しない場合は null
     */
    public function fetchTransactionById(int $id): ?TransactionEntry;


    // transfer_groups

    /**
     * 振替グループを挿入する
     *
     * @return int 挿入された振替グループのID
     */
    public function insertTransferGroup(): int;


    // Audit (transaction_audit)

    /**
     * 監査ログを挿入する
     *
     * @param int|null $txId 関連する取引ID（無い場合は null）
     * @param string $operate 'insert'|'update'|'delete' 等
     * @param string|null $info 任意の追加情報（JSON など）
     * @return int 挿入された監査レコードのID
     */
    public function insertAudit(?int $txId, string $operate, ?string $info = null): int;


    /**
     * 監査ログを検索する（簡易フィルタ: txId, operate）
     *
     * @param array $filter ['txId' => int, 'operate' => string]
     * @return array 監査レコードの配列（連想配列）
     */
    public function fetchAudits(array $filter = []): array;


    // Category

    /**
     * カテゴリを挿入する
     *
     * @param CategoryEntry $entry 挿入するカテゴリエントリ
     */
    public function insertCategory(CategoryEntry $entry): void;


    /**
     * カテゴリを更新する
     *
     * @param CategoryEntry $entry 更新するカテゴリエントリ
     */
    public function updateCategory(CategoryEntry $entry): void;


    /**
     * カテゴリを削除する
     *
     * @param CategoryEntry $entry 削除するカテゴリエントリ
     */
    public function deleteCategory(CategoryEntry $entry): void;


    /**
     * 全てのカテゴリを取得する
     *
     * @return CategoryEntry[] カテゴリエントリの配列
     */
    public function fetchAllCategories(): array;


    /**
     * IDでカテゴリを取得する
     *
     * @param int $id カテゴリID
     * @return CategoryEntry|null カテゴリエントリ、存在しない場合は null
     */
    public function fetchCategoryById(int $id): ?CategoryEntry;


    /**
     * 名前でカテゴリを取得する
     *
     * @param string $name カテゴリ名
     * @return CategoryEntry|null カテゴリエントリ、存在しない場合は null
     */
    public function fetchCategoryByName(string $name): ?CategoryEntry;


    // Account

    /**
     * アカウントを挿入する
     *
     * @param AccountEntry $entry 挿入するアカウントエントリ
     */
    public function insertAccount(AccountEntry $entry): void;


    /**
     * アカウントを更新する
     *
     * @param AccountEntry $entry 更新するアカウントエントリ
     */
    public function updateAccount(AccountEntry $entry): void;


    /**
     * アカウントを削除する
     *
     * @param AccountEntry $entry 削除するアカウントエントリ
     */
    public function deleteAccount(AccountEntry $entry): void;


    /**
     * 全てのアカウントを取得する
     *
     * @return AccountEntry[] アカウントエントリの配列
     */
    public function fetchAllAccounts(): array;


    /**
     * IDでアカウントを取得する
     *
     * @param int $id アカウントID
     * @return AccountEntry|null アカウントエントリ、存在しない場合は null
     */
    public function fetchAccountById(int $id): ?AccountEntry;


    /**
     * 名前でアカウントを取得する
     *
     * @param string $name アカウント名
     * @return AccountEntry|null アカウントエントリ、存在しない場合は null
     */
    public function fetchAccountByName(string $name): ?AccountEntry;

    // Ledger

    /**
     * 台帳を挿入する
     *
     * @param LedgerEnrtry $entry 挿入する台帳エントリ
     */
    public function insertLedger(LedgerEnrtry $entry): void;


    /**
     * 台帳を条件でフィルタリングして取得する
     *
     * @param array $filter フィルタリング条件の配列
     * @return LedgerEnrtry[] 条件に一致する台帳の配列
     */
    public function fetchLedgers(array $filter = []): array;


    /**
     * 期間で台帳を取得する
     *
     * @param string $period 期間（例: '2023-09'）
     * @return LedgerEnrtry|null 台帳エントリ、存在しない場合は null
     */
    public function fetchLedgerByPeriod(string $period): ?LedgerEnrtry;


    // ledger_transactions

    /**
     * 台帳取引を挿入する
     *
     * @param int $ledgerId 台帳ID
     * @param int $transactionId 取引ID
     */
    public function insertLedgerTransaction(int $ledgerId, int $transactionId): void;


    /**
     * 台帳取引を更新する
     *
     * @param int $ledgerId 台帳ID
     * @param array $transactionIds 取引IDの配列
     */
    public function updateLedgerTransactions(int $ledgerId, array $transactionIds): void;


    /**
     * 取引IDで台帳取引を削除する
     *
     * @param int $transactionId 取引ID
     */
    public function deleteLedgerTxByTxId(int $transactionId): void;


    /**
     * 全ての台帳取引を取得する
     *
     * @return array 台帳取引の配列（連想配列）
     */
    public function fetchAllLedgerTxs(): array;


    /**
     * 台帳IDで台帳取引を取得する
     *
     * @param int $ledgerId 台帳ID
     * @return array 台帳取引の配列（連想配列）
     */
    public function fetchLedgerTxByLedgerId(int $ledgerId): array;


    // PDO

    /**
     * トランザクションを開始する
     */
    public function beginTransaction(): void;


        /**
        * トランザクション中かどうかを取得する
        *
        * @return bool トランザクション中であれば true、そうでなければ false
        */
    public function inTransaction(): bool;


    /**
     * トランザクションをコミットする
     */
    public function commit(): void;


    /**
     * トランザクションをロールバックする
     */
    public function rollBack(): void;
}
