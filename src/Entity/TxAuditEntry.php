<?php

namespace App\Entity;

/**
 * transaction_audit テーブルのエントリを表す Value Object
 */
class TxAuditEntry
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $txId,
        public readonly string $operate,
        /** @var array|null */
        public readonly ?array $info,
        public readonly \DateTimeImmutable $createdAt
    ) {}

    /**
     * DBの行配列またはオブジェクトから生成する
     *
     * @param array|object $row
     * @return self
     */
    public static function fromArray(array|object $row): self
    {
        $r = is_object($row) ? (array)$row : $row;

        $id = isset($r['id']) ? (int)$r['id'] : null;
        $txId = $r['tx_id'] ?? $r['txId'] ?? null;
        $operate = $r['operate'] ?? null;
        $info = $r['info'] ?? null;
        $createdAt = $r['created_at'] ?? $r['createdAt'] ?? null;

        if ($operate === null || $createdAt === null) {
            throw new \InvalidArgumentException('Invalid row data for TxAuditEntry');
        }

        $txId = $txId !== null ? (int)$txId : null;
        $decodedInfo = null;
        if ($info !== null && $info !== '') {
            $decoded = json_decode((string)$info, true);
            $decodedInfo = json_last_error() === JSON_ERROR_NONE ? $decoded : ['raw' => $info];
        }

        // created_at を DateTimeImmutable に変換
        try {
            $dt = new \DateTimeImmutable($createdAt);
        } catch (\Exception $e) {
            throw new \InvalidArgumentException('Invalid created_at value for TxAuditEntry');
        }

        return new self($id, $txId, (string)$operate, $decodedInfo, $dt);
    }

    /**
     * 保存用に配列化する（主にデバッグ用途）
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tx_id' => $this->txId,
            'operate' => $this->operate,
            'info' => $this->info,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
        ];
    }
}
