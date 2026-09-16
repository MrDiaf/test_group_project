PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS stores (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    ica_store_id TEXT UNIQUE,
    account_id TEXT,
    name TEXT NOT NULL,
    store_format TEXT NOT NULL DEFAULT 'other',
    street TEXT NOT NULL DEFAULT '',
    postcode TEXT NOT NULL DEFAULT '',
    city TEXT NOT NULL DEFAULT '',
    latitude REAL,
    longitude REAL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS deals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    store_id INTEGER NOT NULL,
    external_id TEXT,
    product_name TEXT NOT NULL,
    brand TEXT NOT NULL DEFAULT '',
    description TEXT NOT NULL DEFAULT '',
    deal_price REAL,
    regular_price REAL,
    price_text TEXT NOT NULL DEFAULT '',
    quantity_text TEXT NOT NULL DEFAULT '',
    promotion_text TEXT NOT NULL DEFAULT '',
    image_url TEXT,
    product_url TEXT,
    valid_from TEXT,
    valid_to TEXT,
    source TEXT NOT NULL DEFAULT 'manual' CHECK (source IN ('manual', 'ica_api', 'demo')),
    synced_at TEXT,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS deals_store_external_unique
    ON deals(store_id, external_id)
    WHERE external_id IS NOT NULL AND external_id <> '';

CREATE INDEX IF NOT EXISTS deals_store_id_index ON deals(store_id);
CREATE INDEX IF NOT EXISTS deals_valid_to_index ON deals(valid_to);

CREATE TABLE IF NOT EXISTS import_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    store_id INTEGER,
    status TEXT NOT NULL CHECK (status IN ('success', 'failed')),
    imported_count INTEGER NOT NULL DEFAULT 0,
    message TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (store_id) REFERENCES stores(id) ON DELETE SET NULL
);
