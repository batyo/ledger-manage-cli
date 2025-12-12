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
     * @param string|null $toPeriod 終了期間（省略可能）
     * @return array 収入、支出、収支の配列
     */
    public function summary(string $period, ?string $toPeriod = null): array
    {
        if ($toPeriod === null) {
            $toPeriod = $period;
        }

        if (!Validator::validateDateInYM($period) || !Validator::validateDateInYM($toPeriod)) {
            throw new \InvalidArgumentException('Invalid period format.');
        }

        $dateTimePeriod = new \DateTimeImmutable($period);
        $dateTimeToPeriod = new \DateTimeImmutable($toPeriod);

        if ($dateTimePeriod > $dateTimeToPeriod) {
            throw new \InvalidArgumentException('Invalid period range.');
        }

        $interval = $dateTimePeriod->diff($dateTimeToPeriod);
        $monthlyTotals = ($interval->y * 12) + $interval->m + 1;

        $ledgerData = [
            'income' => 0,
            'expense' => 0,
            'balance' => 0,
            'incomeByCategories' => [],
            'expenseByCategories' => []
        ];

        while ($monthlyTotals > 0) {
            $ledger = $this->repo->fetchLedgerByPeriod($period);

            if (!empty($ledger) && $ledger !== null) {
                $ledgerData['income'] += $ledger->getTotalIncome();
                $ledgerData['expense'] += $ledger->getTotalExpense();
                $ledgerData['balance'] += $ledger->getBalance();

                foreach ($ledger->getIncomeByCategories() as $category => $amount) {
                    if (!isset($ledgerData['incomeByCategories'][$category])) {
                        $ledgerData['incomeByCategories'][$category] = 0;
                    }
                    $ledgerData['incomeByCategories'][$category] += $amount;
                }

                foreach ($ledger->getExpenseByCategories() as $category => $amount) {
                    if (!isset($ledgerData['expenseByCategories'][$category])) {
                        $ledgerData['expenseByCategories'][$category] = 0;
                    }
                    $ledgerData['expenseByCategories'][$category] += $amount;
                }
            }

            $nextMonth = (new \DateTimeImmutable($period))->modify('+1 month');
            $nextPeriod = $nextMonth->format('Y-m');
            $period = $nextPeriod;
            $monthlyTotals--;
        }

        return $ledgerData;
    }
}
