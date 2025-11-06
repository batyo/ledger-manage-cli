<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\TransactionEntry;
use App\Entity\LedgerEnrtry;
use App\Validation\Validator;

/**
 * 取引管理サービスクラス
 */
class TransactionManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }


    /**
     * 取引エントリの各フィールドを検証する
     *
     * @param TransactionEntry $entry 検証する取引エントリ
     * @throws \InvalidArgumentException
     */
    private function validateTransactionEntry(TransactionEntry $entry): void
    {
        if (!Validator::validateDate($entry->date->format('Y-m-d'))) {
            throw new \InvalidArgumentException('An invalid date.');
        }
        if (!Validator::validateAmount($entry->amount)) {
            throw new \InvalidArgumentException('An invalid amount.');
        }
        if (!Validator::validateCategoryId($entry->categoryId)) {
            throw new \InvalidArgumentException('An invalid category ID.');
        }
        if (!Validator::validateAccountId($entry->accountId)) {
            throw new \InvalidArgumentException('An invalid account ID.');
        }
        if (!Validator::validateTransactionType($entry->transactionType)) {
            throw new \InvalidArgumentException('An invalid transaction type.');
        }
    }


    /**
     * 取引の各フィールドを検証する
     * @throws \InvalidArgumentException
     */
    public function validateTransactionFields(array $args): void
    {
        if (!Validator::validateDate($args[0])) {
            throw new \InvalidArgumentException('An invalid date.');
        }
        if (!Validator::validateAmount($args[1])) {
            throw new \InvalidArgumentException('An invalid amount.');
        }
        if (!Validator::validateCategoryId($args[2])) {
            throw new \InvalidArgumentException('The category ID must be a positive integer.');
        }

        $categoryEntry = $this->repo->fetchCategoryById($args[2]);
        if ($categoryEntry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }
        
        if (!Validator::validateAccountId($args[3])) {
            throw new \InvalidArgumentException('The account ID must be a positive integer.');
        }

        $accountEntry = $this->repo->fetchAccountById($args[3]);
        if ($accountEntry === null) {
            throw new \InvalidArgumentException('Account not found.');
        }

        if (!Validator::validateTransactionType($args[4])) {
            throw new \InvalidArgumentException('An invalid transaction type.');
        }
    }


    public function validateTransfer(array $args): void
    {
        if (!Validator::validateDate($args[0])) {
            throw new \InvalidArgumentException('An invalid date.');
        }
        if (!Validator::validateAmount($args[1])) {
            throw new \InvalidArgumentException('An invalid amount.');
        }
        
        for ($i = 0; $i < 2; $i++) {
            if (!Validator::validateAccountId($args[2+$i])) {
                throw new \InvalidArgumentException('The account ID must be a positive integer.');
            }
            $accountEntry = $this->repo->fetchAccountById($args[2+$i]);
            if ($accountEntry === null) {
                throw new \InvalidArgumentException('Account not found.');
            }
        }
        if ($args[2] === $args[3]) {
            throw new \InvalidArgumentException('The transfer origin and transfer destination cannot be the same account.');
        }

        if (!isset($args[4])) return;

        if (!Validator::validateCategoryId($args[4])) {
            throw new \InvalidArgumentException('The category ID must be a positive integer.');
        }
        $categoryEntry = $this->repo->fetchCategoryById($args[4]);
        if ($categoryEntry === null) {
            throw new \InvalidArgumentException('Category not found.');
        }
    }


    /**
     * 取引エントリを取得し、クロージャを適用して更新・保存する
     *
     * @param int $id 対象取引ID
     * @param callable $modifier (TransactionEntry): TransactionEntryクラスのクロージャ
     * @throws \InvalidArgumentException
     */
    private function applyModifierAndUpdate(int $id, callable $modifier): void
    {
        $entry = $this->repo->fetchTransactionById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Transaction not found.');
        }

        // クロージャを適用して新しい取引エントリを取得
        $newEntry = $modifier($entry);
        if ($newEntry->id === null) {
            throw new \InvalidArgumentException('Transaction ID cannot be null when updating.');
        }

        $this->validateTransactionEntry($newEntry);
        $this->repo->updateTransaction($newEntry);
    }


    /**
     * 新しい取引を登録し、対応するアカウントの残高を調整する
     *
     * @param TransactionEntry $entry 追加する取引
     * @throws \InvalidArgumentException 入力が不正な場合
     * @return int 登録された取引のID
     */
    public function registerTxWithAccount(TransactionEntry $entry): int
    {
        $this->validateTransactionEntry($entry);

        $accountId = $entry->accountId;
        if (!Validator::validateAccountId($accountId)) {
            throw new \InvalidArgumentException('An invalid account ID.');
        }

        $this->repo->beginTransaction();
        try {
            // 取引を保存
            $transactionId = $this->repo->insertTransaction($entry);

            // 取引を該当月の台帳に紐づける
            $date = $entry->date;
            $period = $date->format('Y-m');

            $ledgerEntry = $this->repo->fetchLedgerByPeriod($period);
            if ($ledgerEntry === null) {
                $ledgerEntry = new LedgerEnrtry(null, $period, []);
                $this->repo->insertLedger($ledgerEntry);
                $ledgerEntry = $this->repo->fetchLedgerByPeriod($period);
            }
            $ledgerId = $ledgerEntry->id;
            $this->repo->insertLedgerTransaction($ledgerId, $transactionId);

            // アカウントの残高を調整
            $accountEntry = $this->repo->fetchAccountById($accountId);
            if ($accountEntry === null) {
                throw new \InvalidArgumentException('Account not found.');
            }
            $transactionAmount = $entry->amount;

            if ($entry->isIncome()) $accountEntry = $accountEntry->deposit($transactionAmount);
            if ($entry->isExpense()) $accountEntry = $accountEntry->withdraw($transactionAmount);
            $this->repo->updateAccount($accountEntry);


            $this->repo->commit();

            return $transactionId;

        } catch (\Throwable $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }


    /**
     * 専用の振替実行
     *
     * - 振替は内部で「振替元の取引」「振替先の取引」をそれぞれ挿入し、
     *   両アカウントの残高を更新します。
     * - categoryId を指定しない場合、登録済みの transfer(category_type=3) カテゴリを自動で探します。
     *
     * @param \DateTimeImmutable $date
     * @param float $amount
     * @param int $fromAccountId
     * @param int $toAccountId
     * @param int|null $categoryId
     * @param string|null $note
     * @return int[] [fromTransactionId, toTransactionId]
     * @throws \InvalidArgumentException
     */
    public function registerTransfer(\DateTimeImmutable $date, float $amount, int $fromAccountId, int $toAccountId, ?int $categoryId = null, ?string $note = null): array
    {
        if (!Validator::validateDate($date->format('Y-m-d'))) {
            throw new \InvalidArgumentException('An invalid date.');
        }
        if (!Validator::validateAmount($amount)) {
            throw new \InvalidArgumentException('An invalid amount.');
        }
        if (!Validator::validateAccountId($fromAccountId) || !Validator::validateAccountId($toAccountId)) {
            throw new \InvalidArgumentException('An invalid account ID.');
        }
        if ($fromAccountId === $toAccountId) {
            throw new \InvalidArgumentException('The transfer origin and transfer destination cannot be the same account.');
        }

        // アカウント存在確認
        $fromAccount = $this->repo->fetchAccountById($fromAccountId);
        $toAccount = $this->repo->fetchAccountById($toAccountId);
        if ($fromAccount === null || $toAccount === null) {
            throw new \InvalidArgumentException('Account not found.');
        }

        // カテゴリID確認・取得
        $categoryId = null;
        $all = $this->repo->fetchAllCategories();
        foreach ($all as $category) {
            if ($category->isTransferCategory()) {
                $categoryId = $category->id;
                break;
            }
        }
        if ($categoryId === null) {
            throw new \InvalidArgumentException('No transfer category found. Please register a category with category_type=3 (transfer) first.');
        }

        $this->repo->beginTransaction();
        try {
            // 振替グループを作成して両取引に同じ group を割当てる
            $groupId = $this->repo->insertTransferGroup();

            // 振替元取引（口座: fromAccount）
            $txFrom = new TransactionEntry(
                null,
                $date,
                $amount,
                $categoryId,
                $fromAccountId,
                3, // TRANSFER
                $note,
                $groupId
            );
            $fromTxId = $this->repo->insertTransaction($txFrom);

            // 振替先取引（口座: toAccount）
            $txTo = new TransactionEntry(
                null,
                $date,
                $amount,
                $categoryId,
                $toAccountId,
                3, // TRANSFER
                $note,
                $groupId
            );
            $toTxId = $this->repo->insertTransaction($txTo);

            // 台帳への紐付け（同一期間に挿入）
            $period = $date->format('Y-m');
            $ledger = $this->repo->fetchLedgerByPeriod($period);
            if ($ledger === null) {
                $ledgerEntry = new LedgerEnrtry(null, $period, []);
                $this->repo->insertLedger($ledgerEntry);
                $ledger = $this->repo->fetchLedgerByPeriod($period);
            }
            $ledgerId = $ledger->id;
            $this->repo->insertLedgerTransaction($ledgerId, $fromTxId);
            $this->repo->insertLedgerTransaction($ledgerId, $toTxId);

            // 両口座の残高更新
            $updatedFrom = $fromAccount->withdraw($amount);
            $updatedTo = $toAccount->deposit($amount);
            $this->repo->updateAccount($updatedFrom);
            $this->repo->updateAccount($updatedTo);

            $this->repo->commit();

            return [$fromTxId, $toTxId];
        } catch (\Throwable $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }


    /**
     * 取引の金額を更新する
     * 
     * クロージャは外部の $newAmount 変数を use で取り込む。
     * 
     * @param int  $id 取引ID
     * @param float $newAmount 新しい金額
     */
    public function changeAmount(int $id, float $newAmount): void
    {
        $this->applyModifierAndUpdate($id, function (TransactionEntry $entry) use ($newAmount): TransactionEntry {
            return $entry->adjustAmount($newAmount);
        });
    }

    /**
     * 取引のカテゴリを変更する
     * 
     * クロージャは外部の $newCategoryId 変数を use で取り込む。
     * 
     * @param int $id 取引ID
     * @param int $newCategoryId 新しいカテゴリID
     */
    public function changeCategory(int $id, int $newCategoryId): void
    {
        $this->applyModifierAndUpdate($id, function (TransactionEntry $e) use ($newCategoryId): TransactionEntry {
            return $e->changeCategory($newCategoryId);
        });
    }


    /**
     * 取引のカラムをまとめて更新する
     *
     * @param integer $id
     * @param \DateTimeImmutable|null $newDate
     * @param float|null $newAmount
     * @param integer|null $newCategoryId
     * @param integer|null $newAccountId
     * @param integer|null $newTransactionType
     * @param string|null $newNote
     */
    public function updateTransactionFields(
            int $id,
            ?\DateTimeImmutable $newDate = null,
            ?float $newAmount = null,
            ?int $newCategoryId = null,
            ?int $newAccountId = null,
            ?int $newTransactionType = null,
            ?string $newNote = null
        ): void
    {
        $entry = $this->repo->fetchTransactionById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Transaction not found.');
        }

        $date = $newDate ?? $entry->date;
        $amount = $newAmount ?? $entry->amount;
        $categoryId = $newCategoryId ?? $entry->categoryId;
        $accountId = $newAccountId ?? $entry->accountId;
        $transactionType = $newTransactionType ?? $entry->transactionType;
        $note = $newNote ?? $entry->note;

        $newEntry = new TransactionEntry($id, $date, $amount, $categoryId, $accountId, $transactionType, $note);
        $this->validateTransactionEntry($newEntry);
        if (!Validator::isFoundCategoryId($entry->categoryId, $this->repo)) {
            throw new \InvalidArgumentException('Category not found.');
        }
        if (!Validator::isFoundAccountId($entry->accountId, $this->repo)) {
            throw new \InvalidArgumentException('Account not found.');
        }

        // transfer <-> non-transfer の相互変換は許可しない
        if ($entry->isTransfer() !== $newEntry->isTransfer()) {
            throw new \InvalidArgumentException('Changing between transfer and non-transfer transactions is not supported. Please delete and recreate the transaction.');
        }

        $this->repo->beginTransaction();
        try {
            // 振替取引の更新（ペアを同時に更新）
            if ($entry->isTransfer()) {
                $this->handleTransferUpdate($entry, $newEntry, $newAccountId);
                $this->repo->commit();
                return;
            }
            // 非振替（収入/支出） の更新
            $this->handleNonTransferUpdate($entry, $newEntry);

            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }


    /**
     * 振替取引ペアの更新処理
     *
     * - ペアの同時更新、残高差分適用、台帳の移動を行う。
     * - トランザクション制御は呼び出し元で行う。
     * 
     * @see self::updateTransactionFields で使用
     *
     * @param TransactionEntry $entry 現行の片側エントリ
     * @param TransactionEntry $newEntry 変更後の片側エントリ（accountは無視）
     * @param int|null $newAccountId 更新パラメータに accountId が含まれている場合の値（未指定は null）
     * @throws \InvalidArgumentException
     */
    private function handleTransferUpdate(TransactionEntry $entry, TransactionEntry $newEntry, ?int $newAccountId = null): void
    {
        if ($entry->transferGroupId === null) {
            throw new \InvalidArgumentException('An invalid transfer group ID.');
        }

        $pair = $this->repo->fetchTransactions(['transferGroupId' => $entry->transferGroupId]);
        if (count($pair) !== 2) {
            throw new \InvalidArgumentException('No replacement pair found or invalid state.');
        }

        usort($pair, fn($a, $b) => $a->id <=> $b->id); // 昇順
        $fromTx = $pair[0];
        $toTx = $pair[1];

        // アカウント変更はサポートしない（破壊的）
        if ($newAccountId !== null && $newAccountId !== $fromTx->accountId && $newAccountId !== $toTx->accountId) {
            throw new \InvalidArgumentException('Changing the account of a transfer transaction is not supported.');
        }
        if ($fromTx->accountId === $toTx->accountId) {
            throw new \InvalidArgumentException('The transfer origin and transfer destination cannot be the same account.');
        }

        // 両側とも同一の date/amount/category/note に合わせる
        $newAmountForPair = $newEntry->amount;
        $newDateForPair = $newEntry->date;
        $newCategoryForPair = $newEntry->categoryId;
        $newNoteForPair = $newEntry->note;

        // 差分を残高に反映（from は引き落とし、to は入金）
        $oldAmount = $fromTx->amount;
        $delta = $newAmountForPair - $oldAmount;

        $fromAcc = $this->repo->fetchAccountById($fromTx->accountId);
        $toAcc = $this->repo->fetchAccountById($toTx->accountId);
        if ($fromAcc === null || $toAcc === null) {
            throw new \InvalidArgumentException('No related accounts were found.');
        }

        if ($delta !== 0.0) {
            if ($delta > 0) {
                $fromAcc = $fromAcc->withdraw($delta);
                $toAcc = $toAcc->deposit($delta);
            } else {
                $fromAcc = $fromAcc->deposit(-$delta);
                $toAcc = $toAcc->withdraw(-$delta);
            }
            $this->repo->updateAccount($fromAcc);
            $this->repo->updateAccount($toAcc);
        }

        // 両トランザクションを更新（accountId と transferGroupId は維持）
        $updatedFromTx = new TransactionEntry($fromTx->id, $newDateForPair, $newAmountForPair, $newCategoryForPair, $fromTx->accountId, 3, $newNoteForPair, $fromTx->transferGroupId);
        $updatedToTx = new TransactionEntry($toTx->id, $newDateForPair, $newAmountForPair, $newCategoryForPair, $toTx->accountId, 3, $newNoteForPair, $toTx->transferGroupId);

        $this->repo->updateTransaction($updatedFromTx);
        $this->repo->updateTransaction($updatedToTx);

        // 台帳の移動（期間が変われば紐付けを移す）
        $oldPeriod = $fromTx->date->format('Y-m');
        $newPeriod = $newDateForPair->format('Y-m');
        if ($oldPeriod !== $newPeriod) {
            $this->repo->deleteLedgerTxByTxId($fromTx->id);
            $this->repo->deleteLedgerTxByTxId($toTx->id);

            $ledger = $this->repo->fetchLedgerByPeriod($newPeriod);
            if ($ledger === null) {
                $this->repo->insertLedger(new LedgerEnrtry(null, $newPeriod, []));
                $ledger = $this->repo->fetchLedgerByPeriod($newPeriod);
            }
            $this->repo->insertLedgerTransaction($ledger->id, $fromTx->id);
            $this->repo->insertLedgerTransaction($ledger->id, $toTx->id);
        }
    }


    /**
     * 非振替（収入/支出）取引の更新処理
     *
     * - 元の影響を巻き戻し、新しい影響を適用、トランザクションを更新、台帳移動を行う。
     * - トランザクション制御は呼び出し元で行う。
     *
     * @see self::updateTransactionFields で使用
     * 
     * @param TransactionEntry $entry 既存エントリ
     * @param TransactionEntry $newEntry 変更後エントリ
     * @throws \InvalidArgumentException
     */
    private function handleNonTransferUpdate(TransactionEntry $entry, TransactionEntry $newEntry): void
    {
        // 1) 元のトランザクションがアカウントに与えた影響を巻き戻す
        $origAcc = $this->repo->fetchAccountById($entry->accountId);
        if ($origAcc === null) {
            throw new \InvalidArgumentException('The original account could not be found.');
        }

        if ($entry->isIncome()) $origAcc = $origAcc->withdraw($entry->amount);
        if ($entry->isExpense()) $origAcc = $origAcc->deposit($entry->amount);
        $this->repo->updateAccount($origAcc);

        // 2) 新しいトランザクションの影響を新しいアカウントに適用
        $targetAcc = $this->repo->fetchAccountById($newEntry->accountId);
        if ($targetAcc === null) {
            throw new \InvalidArgumentException('The target account could not be found.');
        }

        if ($newEntry->isIncome()) $targetAcc = $targetAcc->deposit($newEntry->amount);
        if ($newEntry->isExpense()) $targetAcc = $targetAcc->withdraw($newEntry->amount);
        $this->repo->updateAccount($targetAcc);

        // 3) トランザクション自体を更新
        $this->repo->updateTransaction($newEntry);

        // 4) 台帳の移動（期間が変われば紐付けを移す）
        $oldPeriod = $entry->date->format('Y-m');
        $newPeriod = $newEntry->date->format('Y-m');
        if ($oldPeriod !== $newPeriod) {
            $this->repo->deleteLedgerTxByTxId($entry->id);
            $ledger = $this->repo->fetchLedgerByPeriod($newPeriod);
            if ($ledger === null) {
                $this->repo->insertLedger(new LedgerEnrtry(null, $newPeriod, []));
                $ledger = $this->repo->fetchLedgerByPeriod($newPeriod);
            }
            $this->repo->insertLedgerTransaction($ledger->id, $entry->id);
        }
    }


    /**
     * 取引を削除し、対応するアカウントの残高を調整する
     *
     * @param int $id 削除する取引のID
     * @throws \InvalidArgumentException 入力が不正な場合
     */
    public function deleteTransaction(int $id): void
    {
        $entry = $this->repo->fetchTransactionById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Transaction not found.');
        }

        $this->repo->beginTransaction();
        try {
            // 振替取引の場合は対応するペアを探して両方削除・残高を戻す
            if ($entry->isTransfer()) {
                $counterparts = [];
                if ($entry->transferGroupId === null) {
                    throw new \InvalidArgumentException('An invalid transfer group ID.');
                }

                $counterparts = $this->repo->fetchTransactions(['transferGroupId' => $entry->transferGroupId]);                

                if (count($counterparts) !== 2) {
                    throw new \InvalidArgumentException('No replacement pair was found, or multiple pairs were detected. Please verify manually.');
                }

                // どちらも同一 transfer_group に属するはずなので両方を特定
                $txA = $counterparts[0];
                $txB = $counterparts[1];

                // アカウントを取得して残高を巻き戻す
                $accA = $this->repo->fetchAccountById($txA->accountId);
                $accB = $this->repo->fetchAccountById($txB->accountId);
                if ($accA === null || $accB === null) {
                    throw new \InvalidArgumentException('Related accounts could not be found.');
                }

                // registerTransfer は from を引き落とし、to を入金している -> 削除は逆操作
                // どちらが from かは ID が小さい方で判定
                if ($txA->id < $txB->id) {
                    $fromTx = $txA;
                    $toTx = $txB;
                } else {
                    $fromTx = $txB;
                    $toTx = $txA;
                }

                $fromAcc = $this->repo->fetchAccountById($fromTx->accountId);
                $toAcc = $this->repo->fetchAccountById($toTx->accountId);

                $updatedFrom = $fromAcc->deposit($fromTx->amount);
                $updatedTo = $toAcc->withdraw($toTx->amount);

                $this->repo->updateAccount($updatedFrom);
                $this->repo->updateAccount($updatedTo);

                // 両取引を削除（リポジトリ側で ledger_transactions も削除される）
                $this->repo->deleteTransaction($fromTx);
                $this->repo->deleteTransaction($toTx);

                $this->repo->commit();
                return;
            }

            // 収入 / 支出 の逆操作
            $account = $this->repo->fetchAccountById($entry->accountId);
            if ($account === null) {
                throw new \InvalidArgumentException('Related account could not be found.');
            }

            if ($entry->isIncome()) $updated = $account->withdraw($entry->amount);
            if ($entry->isExpense()) $updated = $account->deposit($entry->amount);

            $this->repo->updateAccount($updated);
            $this->repo->deleteTransaction($entry);

            $this->repo->commit();
        } catch (\Throwable $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }


    /**
     * 取引を条件でフィルタリングして取得する
     *
     * @param array $filter フィルタリング条件の配列
     * @return TransactionEntry[] 条件に一致する取引の配列
     */
    public function filterTransactions(array $filter = []): array
    {
        return $this->repo->fetchTransactions($filter);
    }
}
