from __future__ import annotations

import os
import sqlite3
from datetime import date, timedelta
from pathlib import Path

from flask import current_app, g

PROJECT_ROOT = Path(__file__).resolve().parent.parent
DEFAULT_DB_PATH = PROJECT_ROOT / "data" / "deals.sqlite"
SCHEMA_PATH = PROJECT_ROOT / "database" / "schema.sql"


def get_db() -> sqlite3.Connection:
    if "db" not in g:
        path = Path(current_app.config["DATABASE"])
        path.parent.mkdir(parents=True, exist_ok=True)
        connection = sqlite3.connect(path, timeout=5)
        connection.row_factory = sqlite3.Row
        connection.execute("PRAGMA foreign_keys = ON")
        connection.execute("PRAGMA busy_timeout = 5000")
        g.db = connection
    return g.db


def close_db(_error: BaseException | None = None) -> None:
    connection = g.pop("db", None)
    if connection is not None:
        connection.close()


def init_db() -> None:
    db = get_db()
    db.executescript(SCHEMA_PATH.read_text(encoding="utf-8"))
    _add_missing_columns(db)
    if db.execute("SELECT COUNT(*) FROM stores").fetchone()[0] == 0:
        _seed(db)
    db.commit()


def _add_missing_columns(db: sqlite3.Connection) -> None:
    columns = {row[1] for row in db.execute("PRAGMA table_info(deals)")}
    additions = {
        "category": "TEXT NOT NULL DEFAULT ''",
        "country_of_origin": "TEXT NOT NULL DEFAULT ''",
    }
    for name, definition in additions.items():
        if name not in columns:
            db.execute(f"ALTER TABLE deals ADD COLUMN {name} {definition}")


def _seed(db: sqlite3.Connection) -> None:
    stores = [
        ("16628", "1004599", "ICA Kvantum Kungsholmen", "kvantum", "Fleminggatan 16", "112 26", "Stockholm", 59.33316, 18.04494),
        ("13026", "1004134", "ICA Supermarket Sabbatsberg", "supermarket", "Dalagatan 9 N", "113 61", "Stockholm", 59.33989, 18.04712),
        ("13164", "1003418", "Maxi ICA Stormarknad Lindhagen", "maxi", "Lindhagensgatan 118", "112 51", "Stockholm", 59.33714, 18.00974),
    ]
    db.executemany(
        """INSERT INTO stores
           (ica_store_id, account_id, name, store_format, street, postcode, city, latitude, longitude)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)""",
        stores,
    )
    start = (date.today() - timedelta(days=2)).isoformat()
    end = (date.today() + timedelta(days=5)).isoformat()
    deals = [
        (1, "demo-coffee", "Kaffe mellanrost", "ICA", 49.90, 64.90, "49,90 kr", "500 g", "Demoerbjudande", "Kaffe", start, end),
        (1, "demo-apples", "Svenska äpplen", "", 24.90, 34.90, "24,90 kr", "1 kg", "Veckans frukt", "Frukt & grönt", start, end),
        (2, "demo-cheese", "Hushållsost", "ICA", 89.00, 109.00, "89 kr/kg", "ca 1 kg", "Demoerbjudande", "Ost", start, end),
    ]
    db.executemany(
        """INSERT INTO deals
           (store_id, external_id, product_name, brand, deal_price, regular_price,
            price_text, quantity_text, promotion_text, category, valid_from, valid_to, source)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'demo')""",
        deals,
    )


def configure_database(app) -> None:
    app.config.setdefault("DATABASE", os.environ.get("DEALS_DB_PATH", str(DEFAULT_DB_PATH)))
    app.teardown_appcontext(close_db)
    with app.app_context():
        init_db()
