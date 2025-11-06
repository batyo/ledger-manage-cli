CREATE TABLE IF NOT EXISTS transaction_audit (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tx_id INTEGER,
    operate TEXT NOT NULL, -- 'insert'|'update'|'delete'
    info TEXT,
    created_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_tx_id ON transaction_audit(tx_id);