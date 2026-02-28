BEGIN TRANSACTION;

CREATE TABLE IF NOT EXISTS transaction_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    amount REAL NOT NULL,
    category_id INTEGER NOT NULL,
    account_id INTEGER NOT NULL,
    transaction_type INTEGER NOT NULL CHECK (transaction_type IN (1,2,3)),
    note TEXT
);

COMMIT;