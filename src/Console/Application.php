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
use App\Service\TxTemplateManager;
use App\Validation\Validator;
use CLI\Display\Display;
use CLI\Display\Style;
use CLI\Display\EncodingHelper;

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
    private TxTemplateManager $txTemplateManager;
    private Display $display;
    
    public function __construct(private string $dbPath, private array $userPrefs)
    {
        $repo = new SqliteRepository($this->dbPath);
        $this->ledgerManager = new LedgerManager($repo);
        $this->transactionManager = new TransactionManager($repo);
        $this->accountManager = new AccountManager($repo);
        $this->categoryManager = new CategoryManager($repo);
        $this->ledgerTxManager = new LedgerTxManager($repo);
        $this->txAuditManager = new TxAuditManager($repo);
        $this->txTemplateManager = new TxTemplateManager($repo);
        $this->display = new Display();
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
                $this->executeAddTransaction($argv, $this->transactionManager, $this->txTemplateManager);
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
            case 'add-tx-tmp':
                $this->executeAddTxTemplate($argv, $this->txTemplateManager);
                break;
            case 'update-tx-tmp':
                $this->executeUpdateTxTemplate($argv, $this->txTemplateManager);
                break;
            case 'delete-tx-tmp':
                $this->executeDeleteTxTemplate($argv, $this->txTemplateManager);
                break;
            case 'list-tx-tmp':
                $this->listTxTemplates($this->txTemplateManager);
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
        // 警告と確認を表示して誤実行を防止
        $this->display->box("警告: データベースを初期化します。\n既存データはすべて失われます。\n本当に初期化しても良いですか？", 0, new Style('red', null, true));
        echo $this->display->colorText('続行しますか？ (y/n): ', 'yellow', null, true);

        $handle = fopen("php://stdin", "r");
        $line = $handle === false ? '' : fgets($handle);
        $answer = strtolower(trim((string)$line));
        if ($answer !== 'y' && $answer !== 'yes') {
            $this->display->text('初期化をキャンセルしました。', new Style('yellow'));
            return;
        }

        $repo = new SqliteRepository($this->dbPath);
        $repo->init();
        $this->display->box("Database initialized at {$this->dbPath}", 0, new Style('green', null, true));
    }

    /**
     * 新しい取引を追加する
     * 
     * Usage:
     *  bin/ledger add-transaction [date] [amount] [categoryId] [accountId] [transactionType] [note?]
     * 
     * @param array $argv コマンドライン引数の配列
     * @param TransactionManager $manager 取引管理サービス
     * @param TxTemplateManager $tmpManager テンプレート管理サービス
     */
    private function executeAddTransaction(array $argv, TransactionManager $manager, TxTemplateManager $tmpManager): void
    {
        $args = array_slice($argv, 2);
        if (count($args) < 1) {
            throw new \InvalidArgumentException('Not enough arguments. Usage: add-transaction [date] [amount] [categoryId] [accountId] [transactionType] [note?] or add-tx [date] --tmp templateName');
        }

        // テンプレートから取引を追加する場合の処理
        if (isset($args[1]) && $args[1] === '--tmp') {
            $templateName = $args[2] ?? null;
            if ($templateName === null) {
                throw new \InvalidArgumentException('Please specify the template name.');
            }
            $date = new \DateTimeImmutable($args[0]);
            $entry = $tmpManager->buildTxFromTemplate($templateName, $date);

            if (!$this->confirmTxData($entry)) {
                $this->display->text('Transaction registration cancelled.', new Style('yellow'));
                return;
            }
            
            $manager->registerTxWithAccount($entry);
            $this->display->text("Transaction added from template '{$templateName}'.", new Style('green', null, true));
            return;
        }

        // 通常の取引追加の処理
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
            $this->display->text('Transaction registration cancelled.', new Style('yellow'));
            return;
        }

        $manager->registerTxWithAccount($entry);
        $this->display->text('Transaction added.', new Style('green', null, true));
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
                $this->display->text("Using preferred transfer category id={$categoryId}", new Style('cyan'));
            }
        }

        if ($amount === null || $from === null || $to === null) {
            throw new \InvalidArgumentException('Usage: transfer [date] [amount] [fromAccountId] [toAccountId] [note?] [categoryId?]');
        }

        // 登録内容の確認
        $accMap = $this->accountManager->getAccountMap();
        $fromName = $accMap[$from] ?? (string)$from;
        $toName = $accMap[$to] ?? (string)$to;

        // Build single-line field entries (strip internal newlines)
        $fields = [];
        $fields[] = '登録内容を確認してください:';
        $fields[] = '  日付: ' . str_replace(["\r", "\n"], ' ', $date->format('Y-m-d'));
        $fields[] = '  金額: ¥' . str_replace(["\r", "\n"], ' ', (string)$amount);
        $fields[] = '  振替元: ' . str_replace(["\r", "\n"], ' ', "{$fromName} ({$from})");
        $fields[] = '  振替先: ' . str_replace(["\r", "\n"], ' ', "{$toName} ({$to})");
        if ($categoryId !== null) {
            $catMap = $this->categoryManager->getCategoryMap();
            $catName = $catMap[$categoryId] ?? (string)$categoryId;
            $fields[] = '  カテゴリ: ' . str_replace(["\r", "\n"], ' ', "{$catName} ({$categoryId})");
        } else {
            $fields[] = '  カテゴリ: (未指定)';
        }
        $fields[] = '  メモ: ' . str_replace(["\r", "\n"], ' ', ($note !== null ? $note : '(なし)'));

        // determine box width based on all field lines (include header)
        $max = 0;
        foreach ($fields as $f) {
            $w = EncodingHelper::getDisplayWidth($f);
            if ($w > $max) $max = $w;
        }
        // add extra margin so wrap() won't split lines whose width == max
        // larger margin avoids edge-case splits for multibyte chars
        $boxWidth = $max + 8;
        $this->printBoxNoWrap($fields, new Style(null, null, false));
        // prompt
        echo $this->display->colorText('登録しますか？ (y/n): ', 'yellow', null, true);

        $handle = fopen("php://stdin", "r");
        $line = $handle === false ? '' : fgets($handle);
        $answer = strtolower(trim((string)$line));
        if ($answer !== 'y' && $answer !== 'yes') {
            $this->display->text('Transfer cancelled.', new Style('yellow'));
            return;
        }

        [$fromTxId, $toTxId] = $manager->registerTransfer($date, $amount, $from, $to, $categoryId, $note);
        $this->display->text("Transfer completed. fromTxId={$fromTxId} toTxId={$toTxId}", new Style('green', null, true));
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
        $this->display->text("Transaction id={$id} updated.", new Style('green'));
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
        $this->display->text("Transaction id={$id} deleted.", new Style('green'));
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
            $filter['transferGroupId'] = (int)$options['transfer'];
        }

        $transactions = $txManager->filterTransactions($filter);
        if (empty($transactions)) {
            $this->display->text('No transaction.', new Style('yellow'));
            return;
        }

        $rows = [];
        $rows[] = ['id', 'date', 'amount', 'category', 'category_id', 'account', 'account_id', 'type', 'note', 'transfer_group_id'];
        $categoryMap = $catManager->getCategoryMap();
        $accountMap = $accManager->getAccountMap();
        foreach ($transactions as $t) {
            $rows[] = [
                $t->id,
                $t->date->format('Y-m-d'),
                '¥' . $t->amount,
                $categoryMap[$t->categoryId] ?? (string)$t->categoryId,
                $t->categoryId,
                $accountMap[$t->accountId] ?? (string)$t->accountId,
                $t->accountId,
                $txManager->getTxType($t),
                $t->note ?? '',
                $t->transferGroupId ?? ''
            ];
        }
        $this->display->table($rows, null, true);
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
     *   bin/ledger txListToCsv [period] [fileName?]
     *   period: YYYY-MM（省略時は当月）
    */
    private function executeTxListToCsv(array $argv, TransactionManager $manager, CategoryManager $catManager, AccountManager $accManager): void
    {
        $period = $argv[2] ?? date('Y-m');
        $date = \DateTimeImmutable::createFromFormat('Y-m', $period);
        if (!$date) {
            throw new \InvalidArgumentException('Please specify the period in YYYY-MM format.');
        }

        $fileName =  $argv[3] ?? "txlist_{$period}";
        $output = __DIR__ . "/../../data/download/{$fileName}.csv";

        // 存在しない場合作成
        $outputDir = dirname($output);
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $transactions = $manager->filterTransactions(['period' => $period]);
        if (empty($transactions)) {
            $this->display->text('No transaction.', new Style('yellow'));
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

        $this->display->text("CSV saved to {$output}", new Style('green'));
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
        $this->display->text('Ledger added.', new Style('green'));
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
        $this->display->header("Summary for {$period} ~ {$toPeriodDisplay}", 'standard', new Style('blue', null, true));
        $this->display->text("Income: {$summary['income']}");
        $this->display->text("Expense: {$summary['expense']}");
        $this->display->text("Balance: {$summary['balance']}");

        // Income by categories
        $incomeRows = [['Category', 'Amount']];
        foreach ($summary['incomeByCategories'] as $catId => $value) {
            $name = $categoryMap[$catId] ?? (string)$catId;
            $incomeRows[] = [$name, (string)$value];
        }
        if (count($incomeRows) > 1) {
            $this->display->text('');
            $this->display->text('--Income--');
            $this->display->table($incomeRows, null, true);
        }

        // Expense by categories
        $expenseRows = [['Category', 'Amount']];
        foreach ($summary['expenseByCategories'] as $catId => $value) {
            $name = $categoryMap[$catId] ?? (string)$catId;
            $expenseRows[] = [$name, (string)$value];
        }
        if (count($expenseRows) > 1) {
            $this->display->text('');
            $this->display->text('--Expense--');
            $this->display->table($expenseRows, null, true);
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
        $this->display->text("Category '{$name}' added.", new Style('green'));
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
        $this->display->text("Category id={$id} updated.", new Style('green'));
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
        $this->display->text("Category id={$id} deleted.", new Style('green'));
    }


    /**
     * 全てのカテゴリを表示する
     * 
     * @param CategoryManager $manager カテゴリ管理サービス
     */
    private function listCategories(CategoryManager $manager): void
    {
        $categories = $manager->findCategories();

        if (empty($categories)) {
            $this->display->text('No categories.', new Style('yellow'));
            return;
        }
        $rows = [];
        $rows[] = ['id', 'name', 'type'];
        foreach ($categories as $category) {
            $type = $category->isIncomeCategory() ? 'Income' : ($category->isExpenseCategory() ? 'Expense' : 'Transfer');
            $rows[] = [$category->id, $category->name, $type];
        }
        $this->display->table($rows, null, true);
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
        $this->display->text("Account '{$name}' added.", new Style('green'));
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
        $this->display->text("Account id={$id} updated.", new Style('green'));
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
            $this->display->text('No accounts.', new Style('yellow'));
            return;
        }
        $rows = [];
        $rows[] = ['id', 'name', 'type', 'type_id', 'balance'];
        foreach ($accounts as $account) {
            $typeName = $account->getAccountTypeName();
            $rows[] = [$account->id, $account->name, $typeName, $account->accountType, '¥' . $account->balance];
        }
        $this->display->table($rows, null, true);
    }


    /**
     * 全ての取引と台帳の紐づけ情報を表示する
     *
     * @param LedgerTxManager $manager
     */
    private function listLedgerTxs(LedgerTxManager $manager): void
    {
        $ledgerTxs = $manager->findAllLedgerTxs();
        if (empty($ledgerTxs)) {
            $this->display->text('No data', new Style('yellow'));
            return;
        }
        $rows = [];
        $rows[] = ['ledgerId', 'transactionId'];
        foreach ($ledgerTxs as $ledgerTx) {
            $rows[] = [$ledgerTx->ledgerId, $ledgerTx->transactionId];
        }
        $this->display->table($rows, null, true);
    }


    /**
     * 新しい取引テンプレートを追加する
     *
     * Usage:
     *   bin/ledger add-tx-tmp [name] [amount] [categoryId] [accountId] [transactionType] [note?]
     *
     *  - name: テンプレート名
     *  - amount: 金額
     *  - categoryId: カテゴリID
     *  - accountId: アカウントID
     *  - transactionType: 取引タイプ（1=INCOME, 2=EXPENSE, 3=TRANSFER）
     *  - note: (任意) メモ文字列
     *
     * @param array $argv
     * @param TxTemplateManager $manager
     */
    private function executeAddTxTemplate(array $argv, TxTemplateManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (count($args) < 5) {
            throw new \InvalidArgumentException('Not enough arguments. Usage: add-tx-tmp [name] [amount] [categoryId] [accountId] [transactionType] [note?]');
        }

        $name = $args[0];
        $amount = (float)$args[1];
        $categoryId = (int)$args[2];
        $accountId = (int)$args[3];
        $transactionType = (int)$args[4];
        $note = $args[5] ?? null;

        $entry = new \App\Entity\TxTemplateEntry(
            null,
            $name,
            $amount,
            $categoryId,
            $accountId,
            $transactionType,
            $note
        );

        $manager->validateTxTemplate($entry);
        $manager->registerTxTemplate($entry);
        $this->display->text("Transaction template '{$name}' added.", new Style('green'));
    }


    /**
     * テンプレートを更新する
     *
     * Usage:
     *   bin/ledger update-tx-tmp [ID] [--name=...] [--amount=...] [--category=...] [--account=...] [--type=...] [--note=...]
     *
     * @param array $argv
     * @param TxTemplateManager $manager
     */
    private function executeUpdateTxTemplate(array $argv, TxTemplateManager $manager): void
    {
        $args = array_slice($argv, 2);
        if (empty($args)) {
            throw new \InvalidArgumentException('Please specify ID and options. Usage: update-tx-tmp [ID] [--name=...] [--amount=...] [--category=...] [--account=...] [--type=...] [--note=...]');
        }

        // ID を先頭で指定
        $idToken = $args[0];
        if (!is_numeric($idToken) || (int)$idToken <= 0) {
            throw new \InvalidArgumentException('Please specify the template id as a positive integer.');
        }
        $id = (int)$idToken;

        // 残りの引数をフラグとして解析 (--key=value または --key value)
        $rest = array_slice($args, 1);
        $updates = [];
        $flagsWithoutValue = [];
        $i = 0;
        $n = count($rest);

        while ($i < $n && str_starts_with($rest[$i], '--')) {
            $arg = $rest[$i++];
            $pair = substr($arg, 2);
            if ($pair === '') continue;
            if (str_contains($pair, '=')) {
                [$k, $v] = array_pad(explode('=', $pair, 2), 2, null);
                if (!in_array($k, ['name', 'amount', 'category', 'account', 'type', 'note'], true)) {
                    throw new \InvalidArgumentException("Unknown option --{$k}");
                }
                $updates[$k] = $v;
            } else {
                $flagsWithoutValue[] = $pair;
            }
        }

        // 位置引数から flagsWithoutValue に対応する値を取得
        $posValues = array_slice($rest, $i);
        if (count($posValues) !== count($flagsWithoutValue)) {
            throw new \InvalidArgumentException('The number of flags without "=" does not match the number of supplied values.');
        }
        foreach ($flagsWithoutValue as $idx => $key) {
            $updates[$key] = $posValues[$idx];
        }

        // 現行テンプレート取得
        $curr = $manager->findTemplateById($id);
        if ($curr === null) {
            throw new \InvalidArgumentException("Template id={$id} not found.");
        }

        // マージ（許可キー: name, amount, category, account, type, note）
        $name = $updates['name'] ?? $curr->name;
        $amount = isset($updates['amount']) ? (float)$updates['amount'] : $curr->amount;
        $categoryId = isset($updates['category']) ? (int)$updates['category'] : $curr->categoryId;
        $accountId = isset($updates['account']) ? (int)$updates['account'] : $curr->accountId;
        $transactionType = isset($updates['type']) ? (int)$updates['type'] : $curr->transactionType;
        $note = array_key_exists('note', $updates) ? $updates['note'] : $curr->note;

        $entry = new \App\Entity\TxTemplateEntry($id, $name, $amount, $categoryId, $accountId, $transactionType, $note);

        $manager->validateTxTemplate($entry);
        $manager->updateTxTemplate($entry);
        $this->display->text("Transaction template id={$id} updated.", new Style('green'));
    }


    /**
     * テンプレートを削除する
     *
     * Usage:
     *   bin/ledger delete-tx-tmp [id]
     *
     * @param array $argv
     * @param TxTemplateManager $manager
     */
    private function executeDeleteTxTemplate(array $argv, TxTemplateManager $manager): void
    {
        $id = isset($argv[2]) ? (int)$argv[2] : null;
        if ($id === null || $id <= 0) {
            throw new \InvalidArgumentException('Please specify the template id as a positive integer.');
        }

        $manager->deleteTxTemplate($id);
        $this->display->text("Transaction template id={$id} deleted.", new Style('green'));
    }


    /**
     * 全ての取引テンプレートを表示する
     *
     * @param TxTemplateManager $manager
     */
    private function listTxTemplates(TxTemplateManager $manager): void
    {
        $templates = $manager->findAllTemplates();
        if (empty($templates)) {
            $this->display->text('No transaction template.', new Style('yellow'));
            return;
        }
        $rows = [];
        $rows[] = ['id', 'name', 'categoryId', 'accountId', 'type', 'note'];
        foreach ($templates as $tmp) {
            $rows[] = [$tmp->id, $tmp->name, $tmp->categoryId, $tmp->accountId, $tmp->transactionType, $tmp->note ?? ''];
        }
        $this->display->table($rows, null, true);
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
            $this->display->text('No audit.', new Style('yellow'));
            return;
        }
        $rows = [];
        $rows[] = ['id', 'txId', 'operate', 'info', 'created_at'];
        foreach ($audits as $audit) {
            $txId = $audit->txId === null ? 'NULL' : $audit->txId;
            $info = $audit->info === null ? '' : json_encode($audit->info, JSON_UNESCAPED_UNICODE);
            $created = $audit->createdAt->format('Y-m-d H:i:s');
            $rows[] = [$audit->id, $txId, $audit->operate, $info, $created];
        }
        $this->display->table($rows, null, true);
    }


    private function printUsage(): void
    {
        $this->display->text("Usage: php app.php [command] [options]\n");
        $rows = [];
        $rows[] = ['Command', 'Description', 'Guide'];
        $rows[] = ['init-db', 'Init DB', 'init-db'];
        $rows[] = ['add-tx', "Add transaction (supports --tmp)", 'add-tx [date] [amount] [categoryId] [accountId] [transactionType] [note]'];
        $rows[] = ['update-tx', 'Update transaction', 'update-tx [--date|--amount|--category|--account|--type|--note] [ID] [values...]'];
        $rows[] = ['delete-tx', 'Delete transaction', 'delete-tx [ID]'];
        $rows[] = ['list-txs', 'List transactions', 'list-txs [--period=YYYY-MM] [--category=ID] [--account=ID] [--type=1|2|3] [--transfer=groupId]'];
        $rows[] = ['download-txs-csv', 'Export CSV', 'download-txs-csv [period] [fileName?]'];
        $rows[] = ['transfer', 'Add transfer', 'transfer [date] [amount] [fromAccountId] [toAccountId] [note?] [categoryId?]'];
        $rows[] = ['add-ledger', 'Create ledger', 'add-ledger [period]'];
        $rows[] = ['summary', 'Show summary', 'summary [fromPeriod] [toPeriod?]'];
        $rows[] = ['add-account', 'Add account', 'add-account [name] [type] [balance]'];
        $rows[] = ['update-account', 'Update account', 'update-account [--name|--type|--balance] [ID] [values...]'];
        $rows[] = ['list-accounts', 'List accounts', 'list-accounts'];
        $rows[] = ['add-category', 'Add category', 'add-category [name] [type]'];
        $rows[] = ['update-category', 'Update category', 'update-category [--name|--type] [ID] [values...]'];
        $rows[] = ['delete-category', 'Delete category', 'delete-category [--reassign] [--force] ID [reassignID]'];
        $rows[] = ['list-categories', 'List categories', 'list-categories'];
        $rows[] = ['list-ledgerTxs', 'List ledger entries', 'list-ledgerTxs'];
        $rows[] = ['add-tx-tmp', 'Add tx template', 'add-tx-tmp [name] [amount] [categoryId] [accountId] [transactionType] [note?]'];
        $rows[] = ['update-tx-tmp', 'Update tx template', 'update-tx-tmp [ID] [--name=...] [--amount=...] [--category=...] [--account=...] [--type=...] [--note=...]'];
        $rows[] = ['delete-tx-tmp', 'Delete tx template', 'delete-tx-tmp [ID]'];
        $rows[] = ['list-tx-tmp', 'List tx templates', 'list-tx-tmp'];
        $rows[] = ['list-audit', 'List audit logs', 'list-audit [--txId=ID] [--operate=op]'];

        $this->display->table($rows, null, true);
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
        // Build single-line field entries (strip internal newlines)
        $fields = [];
        $fields[] = '登録内容を確認してください:';
        $fields[] = '  日付: ' . str_replace(["\r", "\n"], ' ', $date);
        $fields[] = '  金額: ¥' . str_replace(["\r", "\n"], ' ', (string)$amount);
        $fields[] = '  カテゴリ: ' . str_replace(["\r", "\n"], ' ', "{$categoryName} ({$entry->categoryId})");
        $fields[] = '  アカウント: ' . str_replace(["\r", "\n"], ' ', "{$accountName} ({$entry->accountId})");
        $fields[] = '  取引タイプ: ' . str_replace(["\r", "\n"], ' ', "{$txTypeName} ({$entry->transactionType})");
        $fields[] = '  メモ: ' . str_replace(["\r", "\n"], ' ', ($entry->note ?? ''));

        // determine box width based on all field lines (include header)
        $max = 0;
        foreach ($fields as $f) {
            $w = EncodingHelper::getDisplayWidth($f);
            if ($w > $max) $max = $w;
        }
        // add extra margin so wrap() won't split lines whose width == max
        // larger margin avoids edge-case splits for multibyte chars
        $boxWidth = $max + 8;
        $this->printBoxNoWrap($fields, new Style(null, null, false));
        echo $this->display->colorText('登録しますか？ (y/n): ', 'yellow', null, true);

        $handle = fopen("php://stdin", "r");
        $line = $handle === false ? '' : fgets($handle);
        $answer = trim((string)$line);

        return in_array(strtolower($answer), ['y','yes'], true);
    }


    /**
     * Print a box around given lines without applying wrap().
     * Ensures each provided line is printed on a single line.
     * @param string[] $lines
     * @param Style|null $style
     */
    private function printBoxNoWrap(array $lines, ?Style $style = null): void
    {
        $max = 0;
        foreach ($lines as $l) {
            $w = EncodingHelper::getDisplayWidth($l);
            if ($w > $max) $max = $w;
        }
        $width = $max + 4;
        $top = '┌' . str_repeat('─', $width - 2) . '┐';
        $bottom = '└' . str_repeat('─', $width - 2) . '┘';
        $this->display->text($top, $style);
        $contentWidth = $width - 4;
        foreach ($lines as $l) {
            $w = EncodingHelper::getDisplayWidth($l);
            $pad = $contentWidth - $w;
            if ($pad < 0) $pad = 0;
            $line = '│ ' . $l . str_repeat(' ', $pad) . ' │';
            $this->display->text($line, $style);
        }
        $this->display->text($bottom, $style);
    }
}
