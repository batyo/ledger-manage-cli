<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\TxTemplateEntry;
use App\Entity\TransactionEntry;
use App\Validation\Validator;

class TxTemplateManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }


    /**
     * テンプレートを登録する
     *
     * @param TxTemplateEntry $entry 登録するテンプレートエントリ
     */
    public function registerTxTemplate(TxTemplateEntry $entry): void
    {
        $this->repo->insertTemplate($entry);
    }


    /**
     * 名前でテンプレートを取得する
     *
     * @param string $name
     * @return TxTemplateEntry|null
     */
    public function findTemplateByName(string $name): ?TxTemplateEntry
    {
        return $this->repo->fetchTemplateByName($name);
    }


    /**
     * 全てのテンプレートを取得する
     *
     * @return TxTemplateEntry[]
     */
    public function findAllTemplates(): array
    {
        return $this->repo->fetchAllTemplates();
    }


    /**
     * IDでテンプレートを取得する
     *
     * @param int $id
     * @return TxTemplateEntry|null
     */
    public function findTemplateById(int $id): ?TxTemplateEntry
    {
        return $this->repo->fetchTemplateById($id);
    }


    /**
     * テンプレートを更新する
     *
     * @param TxTemplateEntry $entry
     */
    public function updateTxTemplate(TxTemplateEntry $entry): void
    {
        if ($entry->id === null) {
            throw new \InvalidArgumentException('Template ID is required for update.');
        }
        $curr = $this->repo->fetchTemplateById($entry->id);
        if ($curr === null) {
            throw new \InvalidArgumentException("Template id={$entry->id} not found.");
        }

        $this->validateTxTemplate($entry);
        $this->repo->updateTemplate($entry);
    }


    /**
     * テンプレートを削除する
     *
     * @param int $id
     */
    public function deleteTxTemplate(int $id): void
    {
        $entry = $this->repo->fetchTemplateById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException("Template id={$id} not found.");
        }
        $this->repo->deleteTemplate($entry);
    }


    /**
     * テンプレートの内容を検証する
     *
     * @param TxTemplateEntry $entry 検証するテンプレートエントリ
     * @throws \InvalidArgumentException 検証に失敗した場合にスローされる例外
     */
    public function validateTxTemplate(TxTemplateEntry $entry): void
    {
        $validator = new Validator();

        if (!$validator->validateAmount($entry->amount)) {
            throw new \InvalidArgumentException("Amount must be a positive number.");
        }

        if (!$validator->isFoundCategoryId($entry->categoryId, $this->repo)) {
            throw new \InvalidArgumentException("Category id={$entry->categoryId} not found.");
        }

        if (!$validator->isFoundAccountId($entry->accountId, $this->repo)) {
            throw new \InvalidArgumentException("Account id={$entry->accountId} not found.");
        }

        if (!$validator->validateTransactionType($entry->transactionType)) {
            throw new \InvalidArgumentException("Invalid transaction type.");
        }
    }


    /**
     * テンプレート名と日付から TransactionEntry を組み立てて返す
     * 
     * @param string $name テンプレート名
     * @param \DateTimeImmutable $date 日付
     * @return TransactionEntry 組み立てられた取引エントリ
     * @throws \InvalidArgumentException カテゴリ/アカウントが見つからない場合
     */
    public function buildTxFromTemplate(string $name, \DateTimeImmutable $date): TransactionEntry
    {
        $tmp = $this->findTemplateByName($name);
        if ($tmp === null) {
            throw new \InvalidArgumentException("Template '{$name}' not found.");
        }

        return new TransactionEntry(
            null,
            $date,
            $tmp->amount,
            $tmp->categoryId,
            $tmp->accountId,
            $tmp->transactionType,
            $tmp->note
        );
    }
}
