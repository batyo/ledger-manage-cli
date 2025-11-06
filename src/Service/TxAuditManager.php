<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\TxAuditEntry;

/**
 * transaction_audit を扱うサービス層
 */
class TxAuditManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }


    /**
     * 監査ログを登録する
     *
     * @param int|null $txId
     * @param string $operate
     * @param array|null $info 任意の配列（内部で JSON に変換）
     * @return int 挿入された監査レコードID
     */
    public function registerAudit(?int $txId, string $operate, ?array $info = null): int
    {
        $infoJson = $info === null ? null : json_encode($info, JSON_UNESCAPED_UNICODE);
        return $this->repo->insertAudit($txId, $operate, $infoJson);
    }


    /**
     * 監査ログを検索して TxAuditEntry の配列で返す
     *
     * @param array $filter ['txId' => int, 'operate' => string] など
     * @return TxAuditEntry[]
     */
    public function findAllAudit(array $filter = []): array
    {
        $rows = $this->repo->fetchAudits($filter);
        $result = [];
        foreach ($rows as $row) {
            if ($row instanceof TxAuditEntry) {
                $result[] = $row;
                continue;
            }
            if (is_array($row) || is_object($row)) {
                $result[] = TxAuditEntry::fromArray($row);
                continue;
            }
        }
        return $result;
    }


    /**
     * txId でフィルタした監査ログを返す
     *
     * @param int $txId
     * @return TxAuditEntry[]
     */
    public function findByTxId(int $txId): array
    {
        return $this->findAllAudit(['txId' => $txId]);
    }


    /**
     * operate でフィルタした監査ログを返す
     *
     * @param string $operate
     * @return TxAuditEntry[]
     */
    public function findByOperate(string $operate): array
    {
        return $this->findAllAudit(['operate' => $operate]);
    }
}
