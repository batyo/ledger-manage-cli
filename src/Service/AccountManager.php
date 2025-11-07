<?php

namespace App\Service;

use App\Repository\RepositoryInterface;
use App\Entity\AccountEntry;
use App\Validation\Validator;

class AccountManager
{
    private RepositoryInterface $repo;

    public function __construct(RepositoryInterface $repo)
    {
        $this->repo = $repo;
    }

    public function registerAccount(AccountEntry $entry): void
    {
        $this->repo->insertAccount($entry);
    }


    /**
     * アカウントフィールドの妥当性を検証する
     *
     * @param mixed $name
     * @param mixed $type
     * @param mixed $balance
     */
    public function validateAccount(mixed $name, mixed $type, mixed $balance): void
    {
        if (!Validator::validateAccountName($name)) {
            throw new \InvalidArgumentException('Invalid account name.');
        }

        $entry = $this->repo->fetchAccountByName($name);
        if ($entry !== null) {
            throw new \InvalidArgumentException('Account name already exists.');
        }

        if (!Validator::validateAccountType($type)) {
            throw new \InvalidArgumentException('Invalid account type.');
        }

        if (!Validator::validateAccountBalance($balance)) {
            throw new \InvalidArgumentException('Invalid account balance.');
        }
    }


    /**
     * 複数カラムをまとめて更新する
     *
     * @param int $id
     * @param string|null $newName
     * @param int|null $newAccountType
     * @param float|null $newBalance
     * @throws \InvalidArgumentException
     */
    public function updateAccountFields(int $id, ?string $newName = null, ?int $newAccountType = null, ?float $newBalance = null): void
    {
        $entry = $this->repo->fetchAccountById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('アカウントが見つかりません');
        }

        $name = $newName ?? $entry->name;
        $type = $newAccountType ?? $entry->accountType;
        $balance = $newBalance ?? $entry->balance;

        $newEntry = new \App\Entity\AccountEntry($id, $name, $type, $balance);
        $this->repo->updateAccount($newEntry);
    }


    /**
     * 安全にアカウントを削除する
     *
     * - 既存の取引がある場合、--reassign で再割当先アカウントを指定することを要求する。
     * - 再割当が指定された場合、対象アカウントの取引をすべて再割当してからアカウントを削除する。
     *
     * @param int $id 削除対象アカウントID
     * @param int|null $reassignTo 取引を移す先のアカウントID（未指定なら null）
     * @param bool $force 取引が無い場合のみ強制削除を許可（取引があるときの強制削除は不可）
     * @throws \InvalidArgumentException
     */
    public function deleteAccount(int $id, ?int $reassignTo = null, bool $force = false): void
    {
        if (!Validator::validateAccountId($id)) {
            throw new \InvalidArgumentException('This is an invalid ID.');
        }

        $entry = $this->repo->fetchAccountById($id);
        if ($entry === null) {
            throw new \InvalidArgumentException('Account not found.');
        }

        // そのカテゴリを参照する取引を取得
        $transactions = $this->repo->fetchTransactions(['accountId' => $id]);

        if (!empty($transactions)) {
            // 取引がある場合
            if ($reassignTo === null) {
                throw new \InvalidArgumentException(
                    'This account is currently in use for transactions. To delete it, specify the reassignment destination using --reassign [ID] [reassignID].'
                );
            }

            if (!Validator::validateAccountId($reassignTo)) {
                throw new \InvalidArgumentException('This is an invalid reassignment ID.');
            }
            if ($reassignTo === $id) {
                throw new \InvalidArgumentException('Reassignment to the same account is not allowed.');
            }

            $target = $this->repo->fetchAccountById($reassignTo);
            if ($target === null) {
                throw new \InvalidArgumentException('Reassignment account not found.');
            }

            // 取引のカテゴリを再割当して保存
            foreach ($transactions as $tx) {
                /** @var TransactionEntry $tx */
                $updated = $tx->changeAccount($reassignTo);
                $this->repo->updateTransaction($updated);
            }

            // 再割当後に削除
            $this->repo->deleteAccount($entry);
            return;
        }

        // 取引がない場合は削除を行う（force フラグは任意）
        $this->repo->deleteAccount($entry);
    }


    /**
     * すべてのアカウントを取得する
     * 
     * @return AccountEntry[] アカウントエントリの配列
     */
    public function findAccounts(): array
    {
        return $this->repo->fetchAllAccounts();
    }


    /**
     * アカウントIDをキー、アカウント名を値とする連想配列を取得する
     *
     * @return array<int, string> アカウントIDをキー、アカウント名を値とする連想配列
     */
    public function getAccountMap(): array
    {
        $accountMap = [];
        $accounts = $this->findAccounts();
        foreach ($accounts as $account) {
            $accountMap[$account->id] = $account->name;
        }
        return $accountMap;
    }
}
