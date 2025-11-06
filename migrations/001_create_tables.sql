-- categories（科目）
CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    category_type INTEGER NOT NULL
);

-- accounts（口座）
CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    account_type INTEGER NOT NULL,
    balance REAL NOT NULL
);

-- transactions（取引）
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    date TEXT NOT NULL,
    amount REAL NOT NULL,
    category_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    transaction_type INTEGER NOT NULL,
    note TEXT,
    transfer_group_id INTEGER,
    FOREIGN KEY (category_id) REFERENCES categories(id),
    FOREIGN KEY (account_id) REFERENCES accounts(id)
    FOREIGN KEY (transfer_group_id) REFERENCES transfer_groups(id)
);

-- transfer_groups（振替グループ）
CREATE TABLE IF NOT EXISTS transfer_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT
);

-- ledgers（家計簿集計）
CREATE TABLE IF NOT EXISTS ledgers (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    period TEXT NOT NULL UNIQUE
);

-- ledger_transactions（家計簿と取引の紐付け）
CREATE TABLE IF NOT EXISTS ledger_transactions (
    ledger_id INTEGER NOT NULL,
    transaction_id INTEGER NOT NULL,
    PRIMARY KEY (ledger_id, transaction_id),
    FOREIGN KEY (ledger_id) REFERENCES ledgers(id),
    FOREIGN KEY (transaction_id) REFERENCES transactions(id)
);

-- transaction_audit（取引監査ログ）
CREATE TABLE IF NOT EXISTS transaction_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tx_id INTEGER,
    operate TEXT NOT NULL, -- 'insert'|'update'|'delete'
    info TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_tx_id ON transaction_audit(tx_id);