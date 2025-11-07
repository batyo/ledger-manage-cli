<?php

namespace App\Repository;

use App\Entity\TransactionEntry;
use App\Entity\CategoryEntry;
use App\Entity\AccountEntry;
use App\Entity\LedgerEnrtry;
use App\Entity\LedgerTxEntry;
use PDO;

/**
 * SQLiteを使用したリポジトリ実装
 */
class SqliteRepository implements RepositoryInterface
{
    private PDO $pdo;

    public function __construct(string $dbPath)
    {
        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON;');
    }

    /**
     * データベースとテーブルを初期化する
     */
    public function init(): void
    {
        $sqlFile = __DIR__ . '/../../migrations/001_create_tables.sql';
        $sql = file_get_contents($sqlFile);
        $this->pdo->exec($sql);
    }


    /**Transactions */


    /**
     * 新しい取引を保存し、挿入された取引IDを返す
     *
     * @param TransactionEntry $entry
     * @return int 挿入された取引のID
     */
    public function insertTransaction(TransactionEntry $entry): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO transactions (date, amount, category_id, account_id, transaction_type, note, transfer_group_id) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $stmt->execute([
            $entry->date->format('Y-m-d'),
            $entry->amount,
            $entry->categoryId,
            $entry->accountId,
            $entry->transactionType,
            $entry->note,
            $entry->transferGroupId
        ]);

        $txId = (int)$this->pdo->lastInsertId();

        // 監査ログを追加
        $info = json_encode([
            'date' => $entry->date->format('Y-m-d'),
            'amount' => $entry->amount,
            'categoryId' => $entry->categoryId,
            'accountId' => $entry->accountId,
            'transactionType' => $entry->transactionType,
            'note' => $entry->note,
            'transferGroupId' => $entry->transferGroupId
        ], JSON_UNESCAPED_UNICODE);
        $this->insertAudit($txId, 'insert', $info);

        return $txId;
    }


    /**
     * 取引を更新する
     * @param TransactionEntry $entry 更新する取引
     * @throws \InvalidArgumentException 取引IDがnullの場合
     */
    public function updateTransaction(TransactionEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Transaction ID is required for update.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE transactions SET date = ?, amount = ?, category_id = ?, account_id = ?, transaction_type = ?, note = ?, transfer_group_id = ? WHERE id = ?'
        );

        $stmt->execute([
            $entry->date->format('Y-m-d'),
            $entry->amount,
            $entry->categoryId,
            $entry->accountId,
            $entry->transactionType,
            $entry->note,
            $entry->transferGroupId,
            $entry->id
        ]);

        // 監査ログを追加
        $info = json_encode([
            'date' => $entry->date->format('Y-m-d'),
            'amount' => $entry->amount,
            'categoryId' => $entry->categoryId,
            'accountId' => $entry->accountId,
            'transactionType' => $entry->transactionType,
            'note' => $entry->note,
            'transferGroupId' => $entry->transferGroupId
        ], JSON_UNESCAPED_UNICODE);
        $this->insertAudit($entry->id, 'update', $info);
    }


    /**
     * IDで取引を削除する
     *
     * @param TransactionEntry $entry
     * @throws \InvalidArgumentException 取引IDがnullの場合
     */
    public function deleteTransaction(TransactionEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Transaction ID is required for update.');
        }

        // 監査ログを追加（削除前の情報を残す）
        $info = json_encode([
            'id' => $entry->id,
            'date' => $entry->date->format('Y-m-d'),
            'amount' => $entry->amount,
            'categoryId' => $entry->categoryId,
            'accountId' => $entry->accountId,
            'transactionType' => $entry->transactionType,
            'note' => $entry->note,
            'transferGroupId' => $entry->transferGroupId
        ], JSON_UNESCAPED_UNICODE);
        $this->insertAudit($entry->id, 'delete', $info);

        // ledger_transactions の参照を先に削除してから transactions を削除する
        $stmt = $this->pdo->prepare(
            'DELETE FROM ledger_transactions WHERE transaction_id = ?'
        );
        $stmt->execute([
            $entry->id
        ]);

        $stmt2 = $this->pdo->prepare(
            'DELETE FROM transactions WHERE id = ?'
        );
        $stmt2->execute([
            $entry->id
        ]);
    }


    /**
     * 取引を条件でフィルタリングして取得する
     *
     * @param array $filter フィルタリング条件の配列
     */
    public function fetchTransactions(array $filter = []): array
    {
        $sql = 'SELECT * FROM transactions';
        $params = [];
        $where = [];

        if (isset($filter['categoryId'])) {
            $where[] = 'category_id = ?';
            $params[] = $filter['categoryId'];
        }
        if (isset($filter['accountId'])) {
            $where[] = 'account_id = ?';
            $params[] = $filter['accountId'];
        }
        if (isset($filter['period'])) {
            $where[] = 'date LIKE ?';
            $params[] = $filter['period'] . '%';
        }
        if (isset($filter['transactionType'])) {
            $where[] = 'transaction_type = ?';
            $params[] = $filter['transactionType'];
        }
        if (isset($filter['transferGroupId'])) {
            $where[] = 'transfer_group_id = ?';
            $params[] = $filter['transferGroupId'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new TransactionEntry(
                (int)$row['id'],
                new \DateTimeImmutable($row['date']),
                (float)$row['amount'],
                (int)$row['category_id'],
                (int)$row['account_id'],
                (int)$row['transaction_type'],
                $row['note'] ?? null,
                isset($row['transfer_group_id']) ? (int)$row['transfer_group_id'] : null
            );
        }
        return $result;
    }


    /**
     * IDで取引を取得する
     * 
     * @param int $id 取引ID
     * @return TransactionEntry|null 取引が見つかった場合は TransactionEntry インスタンス、見つからなかった場合は null
     */ 
    public function fetchTransactionById(int $id): ?TransactionEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM transactions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new TransactionEntry(
                (int)$row['id'],
                new \DateTimeImmutable($row['date']),
                (float)$row['amount'],
                (int)$row['category_id'],
                (int)$row['account_id'],
                (int)$row['transaction_type'],
                $row['note'] ?? null,
                isset($row['transfer_group_id']) ? (int)$row['transfer_group_id'] : null
            );
        }
        return null;
    }


    public function insertTransferGroup(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO transfer_groups DEFAULT VALUES');
        $stmt->execute();
        return (int)$this->pdo->lastInsertId();
    }


    /**Categories */


    /**
     * カテゴリーを追加する
     *
     * @param CategoryEntry $entry
     */
    public function insertCategory(CategoryEntry $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO categories (name, category_type) VALUES (?, ?)'
        );
        $stmt->execute([
            $entry->name,
            $entry->categoryType
        ]);
    }


    /**
     * カテゴリーを更新する
     *
     * @param CategoryEntry $entry
     * @throws \InvalidArgumentException カテゴリーIDがnullの場合
     */
    public function updateCategory(CategoryEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Category ID is required for update.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE categories SET name = ?, category_type = ? WHERE id = ?'
        );

        $stmt->execute([
            $entry->name,
            $entry->categoryType,
            $entry->id
        ]);
    }


    /**
     * IDでカテゴリーを削除する
     *
     * @param CategoryEntry $entry
     * @throws \InvalidArgumentException カテゴリーIDがnullの場合
     */
    public function deleteCategory(CategoryEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Category ID is required for update.');
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM categories WHERE id = ?'
        );

        $stmt->execute([
            $entry->id
        ]);
    }


    /**
     * カテゴリー一覧を取得する
     */
    public function fetchAllCategories(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM categories');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new CategoryEntry(
                (int)$row['id'],
                $row['name'],
                (int)$row['category_type']
            );
        }
        return $result;
    }

    /**
     * IDでカテゴリーを取得する
     *
     * @param integer $id ID
     * @return CategoryEntry|null 取引が見つかった場合は CategoryEntry インスタンス、見つからなかった場合は null
     */
    public function fetchCategoryById(int $id): ?CategoryEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new CategoryEntry(
                (int)$row['id'],
                (string)$row['name'],
                (int)$row['category_type']
            );
        }
        return null;
    }


    /**
     * 名前でカテゴリーを取得する
     *
     * @param string $name 名前
     * @return CategoryEntry|null 取引が見つかった場合は CategoryEntry インスタンス、見つからなかった場合は null
     */
    public function fetchCategoryByName(string $name): ?CategoryEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new CategoryEntry(
                (int)$row['id'],
                (string)$row['name'],
                (int)$row['category_type']
            );
        }
        return null;
    }


    /**Accounts */


    /**
     * アカウントを追加する
     *
     * @param AccountEntry $entry
     */
    public function insertAccount(AccountEntry $entry): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO accounts (name, account_type, balance) VALUES (?, ?, ?)'
        );
        $stmt->execute([
            $entry->name,
            $entry->accountType,
            $entry->balance
        ]);
    }


    /**
     * アカウント情報を更新する
     *
     * @param AccountEntry $entry
     * @throws \InvalidArgumentException アカウントIDがnullの場合
     */
    public function updateAccount(AccountEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Account ID is required for update.');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE accounts SET name = ?, account_type = ?, balance = ? WHERE id = ?'
        );

        $stmt->execute([
            $entry->name,
            $entry->accountType,
            $entry->balance,
            $entry->id
        ]);
    }


    /**
     * IDでアカウントを削除する
     *
     * @param AccountEntry $entry
     * @throws \InvalidArgumentException アカウントIDがnullの場合
     */
    public function deleteAccount(AccountEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Account ID is required for update.');
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM accounts WHERE id = ?'
        );

        $stmt->execute([
            $entry->id
        ]);
    }


    /**
     * アカウント一覧を取得する
     * 
     * @return AccountEntry[] アカウントエントリの配列
     */
    public function fetchAllAccounts(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM accounts');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new AccountEntry(
                (int)$row['id'],
                $row['name'],
                (int)$row['account_type'],
                (float)$row['balance']
            );
        }
        return $result;
    }


    /**
     * ID でアカウントを取得する
     *
     * @param integer $id
     * @return AccountEntry|null
     */
    public function fetchAccountById(int $id): ?AccountEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new AccountEntry(
                (int)$row['id'],
                (string)$row['name'],
                (int)$row['account_type'],
                (float)$row['balance']
            );
        }
        return null;
    }


    /**
     * 名前でアカウントを取得する
     *
     * @param string $name
     * @return AccountEntry|null
     */
    public function fetchAccountByName(string $name): ?AccountEntry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM accounts WHERE name = ?');
        $stmt->execute([$name]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return new AccountEntry(
                (int)$row['id'],
                (string)$row['name'],
                (int)$row['account_type'],
                (float)$row['balance']
            );
        }
        return null;
    }


    /**Ledgers */


    /**
     * 新しい台帳を追加する
     * 
     * このメソッドは単独では使用しない。上位ブロックでトランザクション処理を行う必要すること。
     * 
     * @todo periodカラムの重複チェックで例外発生前に阻止
     *
     * @param LedgerEnrtry $entry
     */
    public function insertLedger(LedgerEnrtry $entry): void
    {
        try {
            if (!$this->inTransaction()) {
                throw new \LogicException('トランザクション内で実行する必要があります');
            }

            $stmt = $this->pdo->prepare(
                'INSERT INTO ledgers (period) VALUES (?)'
            );
            $stmt->execute([
                $entry->period
            ]);
            $ledgerId = (int)$this->pdo->lastInsertId();

            foreach ($entry->transactions as $transaction) {
                // 既存トランザクションIDを使う前提
                $stmt2 = $this->pdo->prepare(
                    'INSERT OR IGNORE INTO ledger_transactions (ledger_id, transaction_id) VALUES (?, ?)'
                );
                $stmt2->execute([
                    $ledgerId,
                    $transaction->id
                ]);
            }

        }catch (\PDOException $e) {
            throw $e;
        }
        
    }


    /**
     * 台帳を条件でフィルタリングして取得する (互換性維持のため残す。後に削除予定のため非推奨)
     *
     * @param array $filter フィルタリング条件の配列
     * @return LedgerEnrtry[] 条件に一致する台帳の配列
     * 
     * @deprecated このメソッドは将来的に削除される予定です。代わりに fetchLedgerByPeriod() を使用してください。
     */
    public function fetchLedgers(array $filter = []): array
    {
        $sql = 'SELECT * FROM ledgers';
        $params = [];
        $where = [];

        if (isset($filter['period'])) {
            $where[] = 'period = ?';
            $params[] = $filter['period'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            // 取引取得
            $stmt2 = $this->pdo->prepare(
                'SELECT t.* FROM transactions t
                INNER JOIN ledger_transactions lt ON t.id = lt.transaction_id
                WHERE lt.ledger_id = ?'
            );
            $stmt2->execute([(int)$row['id']]);
            $txRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
            $transactions = [];
            foreach ($txRows as $tx) {
                $transactions[] = new TransactionEntry(
                    (int)$tx['id'],
                    new \DateTimeImmutable($tx['date']),
                    (float)$tx['amount'],
                    (int)$tx['category_id'],
                    (int)$tx['account_id'],
                    (int)$tx['transaction_type'],
                    $tx['note'] ?? null
                );
            }
            $result[] = new LedgerEnrtry(
                (int)$row['id'],
                $row['period'],
                $transactions
            );
        }
        return $result;
    }


    /**
     * 期間で台帳を取得する
     *
     * @param string $period 期間（例: '2024-06'）
     * @return LedgerEnrtry|null 取引が見つかった場合は LedgerEnrtry インスタンス、見つからなかった場合は null
     */
    public function fetchLedgerByPeriod(string $period): ?LedgerEnrtry
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ledgers WHERE period = ?');
        $stmt->execute([$period]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        // 取引取得（既存と同様）
        $stmt2 = $this->pdo->prepare(
            'SELECT t.* FROM transactions t
            INNER JOIN ledger_transactions lt ON t.id = lt.transaction_id
            WHERE lt.ledger_id = ?'
        );
        $stmt2->execute([(int)$row['id']]);
        $txRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        $transactions = [];
        foreach ($txRows as $tx) {
            $transactions[] = new TransactionEntry(
                (int)$tx['id'],
                new \DateTimeImmutable($tx['date']),
                (float)$tx['amount'],
                (int)$tx['category_id'],
                (int)$tx['account_id'],
                (int)$tx['transaction_type'],
                $tx['note'] ?? null
            );
        }
        return new LedgerEnrtry(
            (int)$row['id'],
            $row['period'],
            $transactions
        );
    }


    /**ledger_transactions */


    /**
     * 台帳と取引の関連を追加する
     *
     * @param int $ledgerId 台帳ID
     * @param int $transactionId 取引ID
     */
    public function insertLedgerTransaction(int $ledgerId, int $transactionId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT OR IGNORE INTO ledger_transactions (ledger_id, transaction_id) VALUES (?, ?)'
        );
        $stmt->execute([
            $ledgerId,
            $transactionId
        ]);
    }


    /**
     * 台帳と取引の関連を更新する
     *
     * @param int $ledgerId 台帳ID
     * @param int[] $transactionIds 関連付ける取引IDの配列
     */
    public function updateLedgerTransactions(int $ledgerId, array $transactionIds): void
    {
        try {
            $this->beginTransaction();

            // 既存の関連を削除
            $stmt = $this->pdo->prepare('DELETE FROM ledger_transactions WHERE ledger_id = ?');
            $stmt->execute([$ledgerId]);

            // 新しい関連を挿入
            $stmt2 = $this->pdo->prepare(
                'INSERT INTO ledger_transactions (ledger_id, transaction_id) VALUES (?, ?)'
            );
            foreach ($transactionIds as $txId) {
                $stmt2->execute([$ledgerId, $txId]);
            }

            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }


    /**
     * 指定した取引IDに紐づく ledger_transactions のエントリを削除する
     *
     * @param int $transactionId
     */
    public function deleteLedgerTxByTxId(int $transactionId): void
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM ledger_transactions WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);
        $count = (int)$stmt->fetchColumn();
        if ($count === 0) {
            throw new \InvalidArgumentException('指定された取引IDに紐づく ledger_transactions エントリが存在しません。');
        }

        $stmt = $this->pdo->prepare('DELETE FROM ledger_transactions WHERE transaction_id = ?');
        $stmt->execute([$transactionId]);
    }


    /**
     * すべての ledger_transactions エントリを取得する
     *
     * @return array 連想配列の配列
     */
    public function fetchAllLedgerTxs(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM ledger_transactions');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[] = new LedgerTxEntry(
                (int)$row['ledger_id'],
                (int)$row['transaction_id']
            );
        }
        return $result;
    }


    /**
     * 指定した台帳IDに紐づく ledger_transactions のエントリを取得する
     *
     * @param int $ledgerId
     * @return array 連想配列の配列
     */
    public function fetchLedgerTxByLedgerId(int $ledgerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM ledger_transactions WHERE ledger_id = ?');
        $stmt->execute([$ledgerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /** transaction_audit */


    /**
     * 監査ログを挿入する
     *
     * @param int|null $txId
     * @param string $operate
     * @param string|null $info
     * @return int
     */
    public function insertAudit(?int $txId, string $operate, ?string $info = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO transaction_audit (tx_id, operate, info) VALUES (?, ?, ?)');
        $stmt->execute([$txId, $operate, $info]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * 監査ログを検索する（txId / operate の簡易フィルタ）
     *
     * @param array $filter ['txId' => int, 'operate' => string]
     * @return array 連想配列の配列
     */
    public function fetchAudits(array $filter = []): array
    {
        $sql = 'SELECT * FROM transaction_audit';
        $where = [];
        $params = [];
        if (isset($filter['txId'])) {
            $where[] = 'tx_id = ?';
            $params[] = $filter['txId'];
        }
        if (isset($filter['operate'])) {
            $where[] = 'operate = ?';
            $params[] = $filter['operate'];
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        //$sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }


    /**PDO */


    /**
     * PDO::inTransaction
     */
    public function inTransaction(): bool
    {
        if ($this->pdo->inTransaction()) return true;
        return false;
    }

    /**
     * PDO::beginTransaction (PDO::inTransaction が false の場合に実行)
     */
    public function beginTransaction(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }


    /**
     * PDO::commit
     */
    public function commit(): void
    {
        $this->pdo->commit();
    }


    /**
     * PDO::rollBack
     */
    public function rollBack(): void
    {
        $this->pdo->rollBack();
    }
}
