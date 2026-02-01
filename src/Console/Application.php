<?php
namespace App\Console;

use App\Entity\LedgerEnrtry;
use App\Entity\LedgerTxEntry;
use App\Entity\TransactionEntry;
use App\Service\LedgerManager;
use App\Service\TransactionManager;
use App\Service\AccountManager;
use App\Service\CategoryManager;
use App\Repository\SqliteRepository;
use App\Service\LedgerTxManager;
use App\Service\TxAuditManager;
use App\Validation\Validator;

/**
 * コンソールアプリケーションクラス
 */
class Application
{
    private LedgerManager $ledgerManager;
    private TransactionManager $transactionManager;
    private AccountManager $accountManager;
    private CategoryManager $categoryManager;
    private LedgerTxManager $ledgerTxManager;
    private TxAuditManager $txAuditManager;
    
    public function __construct(private string $dbPath, private array $userPrefs)
    {
        $repo = new SqliteRepository($this->dbPath);
        $this->ledgerManager = new LedgerManager($repo);
        $this->transactionManager = new TransactionManager($repo);
        $this->accountManager = new AccountManager($repo);
        $this->categoryManager = new CategoryManager($repo);
        $this->ledgerTxManager = new LedgerTxManager($repo);
        $this->txAuditManager = new TxAuditManager($repo);
    }

    /**
     * コマンドライン引数を解析して対応するアクションを実行する
     *
     * @param array $argv コマンドライン引数の配列
     */
    public function run(array $argv): void
    {
        $command = $argv[1] ?? null;

        switch ($command) {
            case 'init-db':
                $this->initDb();
                break;
            case 'add-tx':
                $this->executeAddTransaction($argv, $this->transactionManager);
                break;
            case 'transfer':
                $this->executeTransfer($argv, $this->transactionManager);
                break;
            case 'update-tx':
                $this->executeUpdateTransaction($argv, $this->transactionManager);
                break;
            case 'delete-tx':
                $this->executeDeleteTransaction($argv, $this->transactionManager);
                break;
            case 'list-txs':
                $this->listTransactions($argv, $this->transactionManager, $this->categoryManager, $this->accountManager);
                break;
            case 'download-txs-csv':
                $this->executeTxListToCsv($argv, $this->transactionManager, $this->categoryManager, $this->accountManager);
                break;
            case 'add-ledger':
                $this->executeAddLedger($argv, $this->ledgerManager, $this->transactionManager);
                break;
            case 'summary':
                $this->summary($argv, $this->ledgerManager, $this->categoryManager);
                break;
            case 'add-account':
                $this->executeAddAccount($argv, $this->accountManager);
                break;
            case 'update-account':
                $this->executeUpdateAccount($argv, $this->accountManager);
                break;
            case 'list-accounts':
                $this->listAccounts($this->accountManager);
                break;
            case 'add-category':
                $this->executeAddCategory($argv, $this->categoryManager);
                break;
            case 'update-category':
                $this->executeUpdateCategory($argv, $this->categoryManager);
                break;
            case 'delete-category':
                $this->executeDeleteCategory($argv, $this->categoryManager);
                break;
            case 'list-categories':
                $this->listCategories($this->categoryManager);
                break;
            case 'list-ledgerTxs':
                $this->listLedgerTxs($this->ledgerTxManager);
                break;
            case 'list-audit':
                $this->listAudit($argv, $this->txAuditManager);
                break;
            default:
                $this->printUsage();
                break;
        }
    }

    /**
     * データベースとテーブルを初期化する
     */
    private function initDb(): void
    {
        $repo = new SqliteRepository($this->dbPath);
        $repo->init();
        echo "Database initialized at {$this->dbPath}\n";
    }

    /**
     * 新しい取引を追加する
     * 
     * Usage:
     *  bin/ledger add-transaction [date] [amount] [categoryId] [accountId] [transactionType] [note?]
     * 
     * @param array $argv コマンドライン引数の配列
     * @param TransactionManager $manager 取引管理サービス
     */
    private function executeAddTransaction(array $argv, TransactionManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (count($args) < 4) {
            throw new \InvalidArgumentException('Not enough arguments. Usage: add-transaction [date] [amount] [categoryId] [accountId] [transactionType] [note?]');
        }

        $manager->validateTransactionFields($args);

        $date = new \DateTimeImmutable($args[0]);
        $amount = (float)$args[1];
        $categoryId = (int)$args[2];
        $accountId = (int)$args[3];
        $transactionType = (int)$args[4];
        $note = $args[5] ?? null;

        $entry = new \App\Entity\TransactionEntry(
            null,
            $date,
            $amount,
            $categoryId,
            $accountId,
            $transactionType,
            $note
        );

        if (!$this->confirmTxData($entry)) {
            echo "Transaction registration cancelled.\n";
            return;
        }

        $manager->registerTxWithAccount($entry);
        echo "Transaction added.\n";
    }


    /**
     * 新しい振替取引を追加する
     *
     * Usage:
     *   bin/ledger transfer [date] [amount] [fromAccountId] [toAccountId] [note?] [categoryId?]
     *
     *  - date: YYYY-MM-DD (省略時は今日)
     *  - amount: 数値
     *  - fromAccountId: 振替元アカウントID
     *  - toAccountId: 振替先アカウントID
     *  - note: (任意) メモ文字列
     *  - categoryId: (任意) transfer タイプのカテゴリID。省略時は登録済みの transfer カテゴリを自動検出。
     *
     * @param array $argv
     * @param TransactionManager $manager
     */
    private function executeTransfer(array $argv, TransactionManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (count($args) < 4) {
            throw new \InvalidArgumentException('Not enough arguments. Usage: add-transaction [date] [amount] [categoryId] [accountId] [transactionType] [note?]');
        }

        $manager->validateTransfer($args);

        $datestr = $args[0];
        $date = new \DateTimeImmutable($datestr);
        $amount = (float)$args[1];
        $from = (int)$args[2];
        $to = (int)$args[3];
        $note = $args[4] ?? null;
        $categoryId = isset($args[5]) ? (int)$args[5] : null;

        // categoryId が指定されなかった場合、transfer タイプのカテゴリを user_prefs.php から取得
        if ($categoryId === null) {
            $pref = $this->userPrefs['transfer_category_id'] ?? null;
            if ($pref !== null) {
                $categoryId = (int)$pref;
                $cat = $this->categoryManager->findCategoryById($categoryId);
                if ($cat === null) {
                    throw new \InvalidArgumentException("Preferred transfer category id={$categoryId} not found. Please update your config/user_prefs.php or pass categoryId explicitly.");
                }
                echo "Using preferred transfer category id={$categoryId}\n";
            }
        }

        if ($amount === null || $from === null || $to === null) {
            throw new \InvalidArgumentException('Usage: transfer [date] [amount] [fromAccountId] [toAccountId] [note?] [categoryId?]');
        }

        // 登録内容の確認
        $accMap = $this->accountManager->getAccountMap();
        $fromName = $accMap[$from] ?? (string)$from;
        $toName = $accMap[$to] ?? (string)$to;

        echo "登録内容を確認してください:\n";
        echo "  日付: {$date->format('Y-m-d')}\n";
        echo "  金額: ¥{$amount}\n";
        echo "  振替元: {$fromName} ({$from})\n";
        echo "  振替先: {$toName} ({$to})\n";
        if ($categoryId !== null) {
            $catMap = $this->categoryManager->getCategoryMap();
            $catName = $catMap[$categoryId] ?? (string)$categoryId;
            echo "  カテゴリ: {$catName} ({$categoryId})\n";
        } else {
            echo "  カテゴリ: (未指定)\n";
        }
        echo "  メモ: " . ($note !== null ? $note : '(なし)') . "\n";
        echo "登録しますか？ (y/n): ";

        $handle = fopen("php://stdin", "r");
        $line = $handle === false ? '' : fgets($handle);
        $answer = strtolower(trim((string)$line));
        if ($answer !== 'y' && $answer !== 'yes') {
            echo "Transfer cancelled.\n";
            return;
        }

        [$fromTxId, $toTxId] = $manager->registerTransfer($date, $amount, $from, $to, $categoryId, $note);
        echo "Transfer completed. fromTxId={$fromTxId} toTxId={$toTxId}\n";
    }


    /**
     * 取引の指定したフィールドを更新する
     *
     * @param array $argv
     * @param TransactionManager $manager
     * @throws \InvalidArgumentException 不正な引数が指定された場合
     */
    private function executeUpdateTransaction(array $argv, TransactionManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('Please specify the option and ID.');
        }

        $allowed = ['date', 'amount', 'category', 'account', 'type', 'note'];
        $updates = [];   // ['name' => '...', 'amount' => 200, ...]
        $flagsWithoutValue = []; // フラグで値を指定しなかったキー（IDの次の位置引数から値を割当てる）
        $i = 0;
        $n = count($args);

        // 先頭からフラグ (--...) を収集する（フラグは先頭にまとめて指定するルール）
        while ($i < $n && str_starts_with($args[$i], '--')) {
            $pair = substr($args[$i], 2);
            if ($pair === '') {
                throw new \InvalidArgumentException('An invalid option has been specified.');
            }
            if (!in_array($pair, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown option --{$pair}");
            }
            $flagsWithoutValue[] = $pair;
            $i++;
        }

        // フラグ群の直後に ID が必要
        if ($i >= $n) {
            throw new \InvalidArgumentException('Please specify the ID.（ex: --date [ID] ["new_date"]）。');
        }

        $idToken = $args[$i++];
        if (!is_numeric($idToken) || (int)$idToken <= 0) {
            throw new \InvalidArgumentException('Please specify the ID as a positive integer.');
        }
        $id = (int)$idToken;

        // 残りの位置引数は、flagsWithoutValue の順に割り当てる
        $posValues = array_slice($args, $i);
        if (count($posValues) !== count($flagsWithoutValue)) {
            throw new \InvalidArgumentException('The number of flags requiring values does not match the number of values.');
        }

        // flagsWithoutValue の出現順に対応する値を割当て
        foreach ($flagsWithoutValue as $idx => $key) {
            $updates[$key] = $posValues[$idx];
        }

        $date = isset($updates['date']) ? (string)$updates['date'] : null;
        $amount = isset($updates['amount']) ? (float)$updates['amount'] : null;
        $categoryId = isset($updates['category']) ? (int)$updates['category'] : null;
        $accountId = isset($updates['account']) ? (int)$updates['account'] : null;
        $transactionType = isset($updates['type']) ? (int)$updates['type'] : null;
        $note = isset($updates['note']) ? (string)$updates['note'] : null;

        if ($date !== null) {
            if (!Validator::validateDate($date)) {
                throw new \InvalidArgumentException('An invalid date.');
            }
            $date = new \DateTimeImmutable($date);
        }

        // 実行
        $manager->updateTransactionFields($id, $date, $amount, $categoryId, $accountId, $transactionType, $note);
        echo "Transaction id={$id} updated.\n";
    }


    /**
     * 指定した取引を削除する
     *
     * @param array $argv
     * @param TransactionManager $manager
     * @throws \InvalidArgumentException
     */
    private function executeDeleteTransaction(array $argv, TransactionManager $manager): void
    {
        $id = isset($argv[2]) ? (int)$argv[2] : null;
        if ($id === null || $id <= 0) {
            throw new \InvalidArgumentException('Please specify the ID of the transaction to be deleted as a positive integer.');
        }

        $manager->deleteTransaction($id);
        echo "Transaction id={$id} deleted.\n";
    }


    /**
     * 取引の一覧を表示する
     * 
     * @param array $argv コマンドライン引数の配列
     * @param TransactionManager $txManager 台帳管理サービス
     */
    private function listTransactions(array $argv, TransactionManager $txManager, CategoryManager $catManager, AccountManager $accManager): void
    {
        $options = $this->parseListOptions(array_slice($argv, 2));

        $filter = [];
        if (!empty($options['period'])) {
            $filter['period'] = $options['period'];
        }
        if (!empty($options['category'])) {
            $filter['categoryId'] = (int)$options['category'];
        }
        if (!empty($options['account'])) {
            $filter['accountId'] = (int)$options['account'];
        }
        if (!empty($options['type'])) {
            $filter['transactionType'] = (int)$options['type'];
        }
        if (!empty($options['transfer'])) {
            $filter['transfer_group_id'] = (int)$options['transfer'];
        }

        $transactions = $txManager->filterTransactions($filter);
        if (empty($transactions)) {
            echo "No transaction.\n";
            exit();
        }

        foreach ($transactions as $t) {
            $categoryMap = $catManager->getCategoryMap();
            $categoryName = $categoryMap[$t->categoryId] ?? (string)$t->categoryId;

            $accountMap = $accManager->getAccountMap();
            $accountName = $accountMap[$t->accountId] ?? (string)$t->accountId;

            $txType = $txManager->getTxType($t);
            
            echo "{$t->id} {$t->date->format('Y-m-d')} ¥{$t->amount} {$categoryName}({$t->categoryId}) {$accountName}({$t->accountId}) {$txType} [{$t->note}] tran_group:{$t->transferGroupId}\n";
        }
    }


    /**
     * list-transactions 用のオプション解析（非常に簡易）
     * 
     * @param string[] $args argv の 2 以降（配列）
     * @return array ['period'=>..., 'category'=>..., 'account'=>...]
     */
    private function parseListOptions(array $args): array
    {
        $opts = [
            'period' => null,
            'category' => null,
            'account' => null,
            'type' => null,
            'transfer' => null,
        ];

        foreach ($args as $arg) {
            // --key=value の形式
            if (str_starts_with($arg, '--')) {
                $pair = substr($arg, 2);
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
                if ($v === null) continue;
                if (in_array($k, ['period','category','account', 'type', 'transfer'], true)) {
                    $opts[$k] = $v;
                }
            }
        }
        return $opts;
    }


    /**
     * 指定期間の取引を CSV に保存する
     *
     * Usage:
     *   bin/ledger txListToCsv [period] [outputPath?]
     *   period: YYYY-MM（省略時は当月）
    */
    private function executeTxListToCsv(array $argv, TransactionManager $manager, CategoryManager $catManager, AccountManager $accManager): void
    {
        $period = $argv[2] ?? date('Y-m');
        $date = \DateTimeImmutable::createFromFormat('Y-m', $period);
        if (!$date) {
            throw new \InvalidArgumentException('Please specify the period in YYYY-MM format.');
        }

        $output = $argv[3] ?? __DIR__ . "/../../data/download/txlist_{$period}.csv";

        $transactions = $manager->filterTransactions(['period' => $period]);
        if (empty($transactions)) {
            echo "No transaction.\n";
            return;
        }

        $catMap = $catManager->getCategoryMap();
        $accMap = $accManager->getAccountMap();

        $fp = fopen($output, 'w');
        if ($fp === false) {
            throw new \RuntimeException("Unable to open file for writing: {$output}");
        }

        // ヘッダ
        fputcsv($fp, ['id', 'date', 'amount', 'category', 'category_id', 'account', 'account_id', 'type', 'type_id', 'note', 'transfer_group_id']);

        foreach ($transactions as $t) {
            // $t は App\Entity\TransactionEntry を想定
            $row = [
                $t->id,
                $t->date->format('Y-m-d'),
                $t->amount,
                $catMap[$t->categoryId] ?? (string)$t->categoryId,
                $t->categoryId,
                $accMap[$t->accountId] ?? (string)$t->accountId,
                $t->accountId,
                $manager->getTxType($t),
                $t->transactionType,
                $t->note ?? '',
                $t->transferGroupId ?? ''
            ];
            fputcsv($fp, $row);
        }
        fclose($fp);

        echo "CSV saved to {$output}\n";
    }


    /**
     * 台帳を登録する
     *
     * @param array $argv
     * @param LedgerManager $ledgerManager
     * @param TransactionManager $txManager
     */
    private function executeAddLedger(array $argv, LedgerManager $ledgerManager, TransactionManager $txManager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('No arguments have been specified.');
        }
        
        $period = $args[0];
        $date = \DateTimeImmutable::createFromFormat('Y-m', $period);
        if (!$date) {
            throw new \InvalidArgumentException('Please specify the period in YYYY-MM format.');
        }
        
        $transactions = $txManager->filterTransactions(['period' => $period]);

        $ledgerEntry = new LedgerEnrtry(null, $period, $transactions);
        $ledgerManager->registerLedger($ledgerEntry);
        echo "Ledger added.\n";
    }


    /**
     * 指定された期間の収支概要を表示する
     *
     * @param array $argv コマンドライン引数の配列
     * @param LedgerManager $ledManager 台帳管理サービス
     */
    private function summary(array $argv, LedgerManager $ledManager, CategoryManager $catManager): void
    {
        $period = $argv[2] ?? date('Y-m');
        isset($argv[3]) ? $toPeriod = $argv[3] : $toPeriod = null;

        $summary = $ledManager->summary($period, $toPeriod);

        $categoryMap = $catManager->getCategoryMap();

        arsort($summary['incomeByCategories']);
        arsort($summary['expenseByCategories']);

        $toPeriodDisplay = $toPeriod ?? $period;
        echo "Summary for {$period} ~ {$toPeriodDisplay}:\n\n";
        echo "Income: {$summary['income']}\n";
        echo "Expense: {$summary['expense']}\n";
        echo "Balance: {$summary['balance']}\n\n";
        echo "By Categories\n\n";
        echo "--Income--\n";
        foreach ($summary['incomeByCategories'] as $catId => $value) {
            $name = $categoryMap[$catId] ?? (string)$catId;
            echo "{$name}: {$value}\n";
        }
        echo "\n--Expense--\n";
        foreach ($summary['expenseByCategories'] as $catId => $value) {
            $name = $categoryMap[$catId] ?? (string)$catId;
            echo "{$name}: {$value}\n";
        }
    }

    /**
     * 新しいカテゴリを追加する
     * 
     * @param array $argv コマンドライン引数の配列
     * @param CategoryManager $manager カテゴリ管理サービス
     */
    private function executeAddCategory(array $argv, CategoryManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args) || count($args) <= 1) {
            throw new \InvalidArgumentException('No arguments were specified or the number of arguments is insufficient.');
        }
        $name = $args[0];
        $categoryType = $args[1];
        $manager->validateCategory($name, $categoryType);

        $entry = new \App\Entity\CategoryEntry(
            null,
            $name,
            $categoryType
        );
        $manager->registerCategory($entry);
        echo "Category '{$name}' added.\n";
    }


    /**
     * カテゴリーの指定したフィールドを更新する
     * 
     * @param array $argv コマンドライン引数の配列
     * @param CategoryManager $manager カテゴリ管理サービス
     * @throws \InvalidArgumentException 不正な引数が指定された場合
     */
    private function executeUpdateCategory(array $argv, CategoryManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('Please specify the option and ID.');
        }

        $allowed = ['name', 'type'];
        $updates = [];   // ['name' => '...', 'type' => 2]
        $flagsWithoutValue = []; // フラグで値を指定しなかったキー（IDの次の位置引数から値を割当てる）
        $i = 0;
        $n = count($args);

        // 先頭からフラグ (--...) を収集する（フラグは先頭にまとめて指定するルール）
        while ($i < $n && str_starts_with($args[$i], '--')) {
            $pair = substr($args[$i], 2);
            if ($pair === '') {
                throw new \InvalidArgumentException('An invalid option has been specified.');
            }
            if (!in_array($pair, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown option --{$pair}");
            }
            $flagsWithoutValue[] = $pair;
            $i++;
        }

        // フラグ群の直後に ID が必要
        if ($i >= $n) {
            throw new \InvalidArgumentException('Please specify the ID.（ex: --name [ID] ["new_name"]）。');
        }

        // ID を取得
        $idToken = $args[$i++];
        if (!is_numeric($idToken) || (int)$idToken <= 0) {
            throw new \InvalidArgumentException('Please specify the ID as a positive integer.');
        }
        $id = (int)$idToken;

        $currCategory = $manager->findCategoryById($id);
        if ($currCategory === null) {
            throw new \InvalidArgumentException("Category id={$id} not found.");
        }

        // 残りの位置引数は、flagsWithoutValue の順に割り当てる
        $posValues = array_slice($args, $i);
        if (count($posValues) !== count($flagsWithoutValue)) {
            throw new \InvalidArgumentException('The number of flags requiring values does not match the number of values.');
        }

        // flagsWithoutValue の出現順に対応する値を割当て
        foreach ($flagsWithoutValue as $idx => $key) {
            $updates[$key] = $posValues[$idx];
        }

        $newName = isset($updates['name']) ? (string)$updates['name'] : $currCategory->name;
        $newType = isset($updates['type']) ? (int)$updates['type'] : $currCategory->categoryType;

        $manager->validateCategoryForUpdate($currCategory->id, $newName, $newType);

        $manager->updateCategoryFields($id, $newName, $newType);
        echo "Category id={$id} updated.\n";
    }


    /**
     * 指定したカテゴリーを削除する
     *
     * @param array $argv
     * @param CategoryManager $manager
     * @throws \InvalidArgumentException
     */
    private function executeDeleteCategory(array $argv, CategoryManager $manager): void
    {
        // args: [--reassign] [--force] ID [reassignID]
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('Please specify the option and ID.');
        }

        $reassignFlag = false;
        $force = false;
        $positional = [];

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--')) {
                $flag = substr($arg, 2);
                if ($flag === 'reassign') {
                    $reassignFlag = true;
                    continue;
                }
                if ($flag === 'force') {
                    $force = true;
                    continue;
                }
                throw new \InvalidArgumentException("Unknown flag --{$flag}");
            }
            $positional[] = $arg;
        }

        if (count($positional) < 1) {
            throw new \InvalidArgumentException('Please specify the ID.');
        }

        $idToken = $positional[0];
        if (!is_numeric($idToken) || (int)$idToken <= 0) {
            throw new \InvalidArgumentException('Please specify the ID as a positive integer.');
        }
        $id = (int)$idToken;

        $reassignId = null;
        if ($reassignFlag) {
            $reassignToken = $positional[1] ?? null;
            if ($reassignToken === null) {
                throw new \InvalidArgumentException('Please specify the reassignment ID.');
            }
            if (!is_numeric($reassignToken) || (int)$reassignToken <= 0) {
                throw new \InvalidArgumentException('Specify the reassignment ID as a positive integer.');
            }
            $reassignId = (int)$reassignToken;
        }

        $manager->deleteCategory($id, $reassignId, $force);
        echo "Category id={$id} deleted.\n";
    }


    /**
     * 全てのカテゴリを表示する
     * 
     * @param CategoryManager $manager　カテゴリ管理サービス
     */
    private function listCategories(CategoryManager $manager): void
    {
        $categories = $manager->findCategories();

        if (empty($categories)) {
            echo "No categories.";
            return;
        }

        foreach ($categories as $category) {
            $type = $category->isIncomeCategory() ? 'Income' : ($category->isExpenseCategory() ? 'Expense' : 'Transfer');
            echo "{$category->id} {$category->name} ({$type})\n";
        }
    }


    /**
     * 新しいアカウントを追加する
     * 
     * @param array $argv コマンドライン引数の配列
     * @param AccountManager $manager アカウント管理サービス
     */
    private function executeAddAccount(array $argv, AccountManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args) || count($args) <= 1) {
            throw new \InvalidArgumentException('No arguments were specified or the number of arguments is insufficient.');
        }

        $name = $args[0];
        $accountType = (int)$args[1];
        $balance = (float)$args[2];
        $manager->validateAccount($name, $accountType, $balance);

        $entry = new \App\Entity\AccountEntry(
            null,
            $name,
            $accountType,
            $balance
        );
        $manager->registerAccount($entry);
        echo "Account '{$name}' added.\n";
    }


    /**
     * アカウントの指定したフィールドを更新する
     *
     * @param array $argv
     * @param AccountManager $manager
     */
    private function executeUpdateAccount(array $argv, AccountManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('Please specify the option and ID.');
        }

        $allowed = ['name', 'type', 'balance'];
        $updates = [];   // ['name' => '...', 'type' => 2, 'balance' => ...]
        $flagsWithoutValue = []; // フラグで値を指定しなかったキー（IDの次の位置引数から値を割当てる）
        $i = 0;
        $n = count($args);

        // 先頭からフラグ (--...) を収集する（フラグは先頭にまとめて指定するルール）
        while ($i < $n && str_starts_with($args[$i], '--')) {
            $pair = substr($args[$i], 2);
            if ($pair === '') {
                throw new \InvalidArgumentException('An invalid option has been specified.');
            }
            if (!in_array($pair, $allowed, true)) {
                throw new \InvalidArgumentException("Unknown option --{$pair}");
            }
            $flagsWithoutValue[] = $pair;
            $i++;
        }

        // フラグ群の直後に ID が必要
        if ($i >= $n) {
            throw new \InvalidArgumentException('Please specify the ID.（ex: --name [ID] ["new_name"]）。');
        }

        // ID を取得
        $idToken = $args[$i++];
        if (!is_numeric($idToken) || (int)$idToken <= 0) {
            throw new \InvalidArgumentException('Please specify the ID as a positive integer.');
        }
        $id = (int)$idToken;

        $currAccount = $manager->findAccountById($id);
        if ($currAccount === null) {
            throw new \InvalidArgumentException("Account id={$id} not found.");
        }

        // 残りの位置引数は、flagsWithoutValue の順に割り当てる
        $posValues = array_slice($args, $i);
        if (count($posValues) !== count($flagsWithoutValue)) {
            throw new \InvalidArgumentException('The number of flags requiring values does not match the number of values.');
        }

        // flagsWithoutValue の出現順に対応する値を割当て
        foreach ($flagsWithoutValue as $idx => $key) {
            $updates[$key] = $posValues[$idx];
        }

        $newName = isset($updates['name']) ? (string)$updates['name'] : $currAccount->name;
        $newType = isset($updates['type']) ? (int)$updates['type'] : $currAccount->accountType;
        $newBalance = isset($updates['balance']) ? (float)$updates['balance'] : $currAccount->balance;

        $manager->validateAccountForUpdate($currAccount->id, $newName, $newType, $newBalance);

        $manager->updateAccountFields($id, $newName, $newType, $newBalance);
        echo "Account id={$id} updated.\n";
    }


    /**
     * 全てのアカウントを表示する
     * 
     * @param AccountManager $manager アカウント管理サービス
     */
    private function listAccounts(AccountManager $manager): void
    {
        $accounts = $manager->findAccounts();

        if (empty($accounts)) {
            echo "No accounts.";
            return;
        }

        foreach ($accounts as $account) {
            $typeName = $account->getAccountTypeName($account->accountType);
            echo "{$account->id} {$account->name}  {$typeName}({$account->accountType}) ¥{$account->balance}\n";
        }
    }


    /**
     * 全ての取引と台帳の紐づけ情報を表示する
     *
     * @param LedgerTxManager $manager
     */
    private function listLedgerTxs(LedgerTxManager $manager): void
    {
        $ledgerTxs = $manager->findAllLedgerTxs();
        if (empty($ledgerTxs)) echo "No data\n";
        foreach ($ledgerTxs as $ledgerTx) {
            echo "ledgerId: {$ledgerTx->ledgerId} txId: {$ledgerTx->transactionId}\n";
        }
    }


    /**
     * 監査ログを表示する
     *
     * @param array $argv
     * @param TxAuditManager $manager
     */
    private function listAudit(array $argv, TxAuditManager $manager): void
    {
        $args = array_slice($argv, 2);

        $opts = [
            'txId' => null,
            'operate' => null
        ];

        $allowed = array_keys($opts);

        foreach ($args as $arg) {
            // --key=value の形式
            if (str_starts_with($arg, '--')) {
                $pair = substr($arg, 2);
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
                if ($value === null) continue;
                if (in_array($key, $allowed, true)) {
                    $opts[$key] = $value;
                }
            }
        }

        $audits = $manager->findAllAudit($opts);
        if (empty($audits)) {
            echo "No audit.\n";
            return;
        }

        foreach ($audits as $audit) {
            // $audit は App\Entity\TxAuditEntry であることを想定
            $txId = $audit->txId === null ? 'NULL' : $audit->txId;
            $info = $audit->info === null ? '' : json_encode($audit->info, JSON_UNESCAPED_UNICODE);
            $created = $audit->createdAt->format('Y-m-d H:i:s');
            echo sprintf("%d txId:%s operate:%s info:%s created_at:%s\n", $audit->id, $txId, $audit->operate, $info, $created);
        }
    }


    private function printUsage(): void
    {
        echo "Usage: php app.php [command] [options]\n";
        echo "Commands:\n";
        echo "  init-db\n\tInitialize the database\n";
        echo "  add-tx [date] [amount] [categoryId] [accountId] [transactionType] [note]\n\tAdd a new transaction\n";
        echo "  update-tx [--field ...] [ID] [values ...]\n\tUpdate fields of a transaction\n";
        echo "  delete-tx [ID]\n\tDelete a transaction\n";
        echo "  list-txs\n\tList all transactions\n";
        echo "  download-txs-csv [period] [outputPath?]\n\tDownload transactions as CSV for the given period\n";
        echo "  transfer [date] [amount] [fromAccountId] [toAccountId] [categoryId?] [note?]\n\tAdd a transfer transaction\n";
        echo "  add-ledger [period]\n\tAdd a new ledger for the given period (e.g., '2023-09')\n";
        echo "  summary [fromPeriod] [toPeriod?]\n\tShow summary for a given period (e.g., '2023-09')\n";
        echo "  add-account [name] [type] [balance]\n\tAdd a new account\n";
        echo "  update-account [--field ...] [ID] [values ...]\n\tUpdate fields of an account\n";
        echo "  list-accounts\n\tList all accounts\n";
        echo "  add-category [name] [type]\n\tAdd a new category (type: 1 for INCOME, 2 for EXPENSE)\n";
        echo "  update-category [--field ...] [ID] [values ...]\n\tUpdate fields of a category\n";
        echo "  delete-category [--reassign] [--force] [ID] [reassignID]\n\tDelete a category\n";
        echo "  list-categories\n\tList all categories\n";
        echo "  list-ledgerTxs\n\tList all ledger-transaction associations\n";
        echo "  list-audit [--txId=] [--operate=]\n\tList audit logs\n";
    }

    /**
     * 確認プロンプト
     *
     * - 単一取引（accountId, transactionType != 3）に対応
     *
     * @param TransactionEntry $entry 登録予定の取引データ。以下のプロパティを持つ
     *    - date: \DateTimeImmutable
     *    - amount: float
     *    - categoryId: int|null
     *    - accountId: int|null
     *    - transactionType: int
     *    - note: ?string
     * @return bool
     */
    private function confirmTxData(TransactionEntry $entry): bool
    {
        $catMap = $this->categoryManager->getCategoryMap();
        $accMap = $this->accountManager->getAccountMap();
        $categoryName = $catMap[$entry->categoryId] ?? (string)$entry->categoryId;
        $accountName = $accMap[$entry->accountId] ?? (string)$entry->accountId;
        $txTypeName = $this->transactionManager->getTxType($entry);

        $date = $entry->date->format('Y-m-d');
        $amount = $entry->amount;
        echo "登録内容を確認してください:\n";
        echo "  日付: {$date}\n";
        echo "  金額: ¥{$amount}\n";
        echo "  カテゴリ: {$categoryName} ({$entry->categoryId})\n";
        echo "  アカウント: {$accountName} ({$entry->accountId})\n";
        echo "  取引タイプ: {$txTypeName} ({$entry->transactionType})\n";
        echo "  メモ: " . ($entry->note ?? '') . "\n";
        echo "登録しますか？ (y/n): ";

        $handle = fopen("php://stdin", "r");
        $line = $handle === false ? '' : fgets($handle);
        $answer = trim((string)$line);

        return in_array(strtolower($answer), ['y','yes'], true);
    }
}
