<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\LedgerTxEntry;

/**
 * ledger_transactions を扱うサービス層
 */
class LedgerTxManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    /**
     * 台帳と取引の関連を追加する
     */
    public function registerLedgerTransaction(int $ledgerId, int $transactionId): void
    {
        $this->repo->insertLedgerTransaction($ledgerId, $transactionId);
    }

    /**
     * 台帳に紐づく取引一覧を更新する（既存削除 → 再挿入）
     *
     * @param int $ledgerId
     * @param int[] $transactionIds
     */
    public function updateLedgerTransactions(int $ledgerId, array $transactionIds): void
    {
        $this->repo->updateLedgerTransactions($ledgerId, $transactionIds);
    }

    /**
     * 指定した取引IDに紐づく ledger_transactions を削除する
     */
    public function deleteByTransactionId(int $transactionId): void
    {
        $this->repo->deleteLedgerTxByTxId($transactionId);
    }

    /**
     * すべての ledger_transactions を取得し、LedgerTxEntry の配列で返す
     *
     * @return LedgerTxEntry[]
     */
    public function findAllLedgerTxs(): array
    {
        return $this->repo->fetchAllLedgerTxs();
    }

    /**
     * 指定した台帳IDに紐づく ledger_transactions を取得し、LedgerTxEntry の配列で返す
     *
     * @param int $ledgerId
     * @return LedgerTxEntry[]
     */
    public function findByLedgerId(int $ledgerId): array
    {
        $rows = $this->repo->fetchLedgerTxByLedgerId($ledgerId);
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof LedgerTxEntry) {
                $result[] = $row;
                continue;
            }
            if (is_array($row)) {
                $result[] = LedgerTxEntry::fromArray($row);
                continue;
            }
            if (is_object($row)) {
                $arr = [];
                if (isset($row->ledger_id) || isset($row->ledgerId)) {
                    $arr['ledger_id'] = $row->ledger_id ?? $row->ledgerId;
                }
                if (isset($row->transaction_id) || isset($row->transactionId)) {
                    $arr['transaction_id'] = $row->transaction_id ?? $row->transactionId;
                }
                $result[] = LedgerTxEntry::fromArray($arr);
            }
        }
        return $result;
    }
}
