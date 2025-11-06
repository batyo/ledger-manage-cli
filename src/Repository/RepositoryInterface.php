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
    public function init(): void;

    // Transaction
    public function insertTransaction(TransactionEntry $entry): int;
    public function updateTransaction(TransactionEntry $entry): void;
    public function deleteTransaction(TransactionEntry $entry): void;
    public function fetchTransactions(array $filter = []): array;
    public function fetchTransactionById(int $id): ?TransactionEntry;

    // transfer_groups
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
    public function insertCategory(CategoryEntry $entry): void;
    public function updateCategory(CategoryEntry $entry): void;
    public function deleteCategory(CategoryEntry $entry): void;
    public function fetchAllCategories(): array;
    public function fetchCategoryById(int $id): ?CategoryEntry;
    public function fetchCategoryByName(string $name): ?CategoryEntry;

    // Account
    public function insertAccount(AccountEntry $entry): void;
    public function updateAccount(AccountEntry $entry): void;
    public function deleteAccount(AccountEntry $entry): void;
    public function fetchAllAccounts(): array;
    public function fetchAccountById(int $id): ?AccountEntry;
    public function fetchAccountByName(string $name): ?AccountEntry;

    // Ledger
    public function insertLedger(LedgerEnrtry $entry): void;
    public function fetchLedgers(array $filter = []): array;
    public function fetchLedgerByPeriod(string $period): ?LedgerEnrtry;

    // ledger_transactions
    public function insertLedgerTransaction(int $ledgerId, int $transactionId): void;
    public function updateLedgerTransactions(int $ledgerId, array $transactionIds): void;
    public function deleteLedgerTxByTxId(int $transactionId): void;
    public function fetchAllLedgerTxs(): array;
    public function fetchLedgerTxByLedgerId(int $ledgerId): array;

    // PDO
    public function beginTransaction(): void;
    public function inTransaction(): bool;
    public function commit(): void;
    public function rollBack(): void;
}
