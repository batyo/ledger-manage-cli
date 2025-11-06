<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\TransactionEntry;
use App\Entity\LedgerEnrtry;
use App\Validation\Validator;

/**
 * 台帳管理サービスクラス
 */
class LedgerManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }


    /**
     * 新しい台帳を追加する
     *
     * @param LedgerEnrtry $ledger 追加する台帳
     */
    public function registerLedger(LedgerEnrtry $ledger): void
    {
        $this->repo->insertLedger($ledger);
    }

    /**
     * 台帳を条件でフィルタリングして取得する
     *
     * @param array $filter フィルタリング条件の配列
     * @return LedgerEnrtry[] 条件に一致する台帳の配列
     */
    public function filterLedgers(array $filter = []): array
    {
        return $this->repo->fetchLedgers($filter);
    }

    /**
     * 指定された期間の収支を集計する
     *
     * @param string $period 集計する期間（例: '2023-09'）
     * @return array 収入、支出、収支の配列
     */
    public function summary(string $period): array
    {
        $ledger = $this->repo->fetchLedgerByPeriod($period);
        if (empty($ledger)) {
            return [
                'income' => 0,
                'expense' => 0,
                'balance' => 0
            ];
        }

        return [
            'income' => $ledger->getTotalIncome(),
            'expense' => $ledger->getTotalExpense(),
            'balance' => $ledger->getBalance(),
            'incomeByCategories' => $ledger->getIncomeByCategories(),
            'expenseByCategories' => $ledger->getExpenseByCategories()
        ];
    }
}
