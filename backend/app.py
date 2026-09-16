from __future__ import annotations

import os
import sqlite3
from datetime import date
from pathlib import Path
from typing import Any
from urllib.parse import urlparse

from flask import Flask, jsonify, request, send_from_directory
import requests

from database import configure_database, get_db
from ica import IcaClient, IcaUpstreamError

STORE_FIELDS = (
    "ica_store_id",
    "account_id",
    "name",
    "store_format",
    "street",
    "postcode",
    "city",
    "latitude",
    "longitude",
)
DEAL_FIELDS = (
    "store_id",
    "external_id",
    "product_name",
    "brand",
    "description",
    "deal_price",
    "regular_price",
    "price_text",
    "quantity_text",
    "promotion_text",
    "category",
    "country_of_origin",
    "image_url",
    "product_url",
    "valid_from",
    "valid_to",
    "source",
)


class ApiError(Exception):
    def __init__(self, message: str, status: int = 400) -> None:
        super().__init__(message)
        self.message = message
        self.status = status


def create_app(test_config: dict[str, Any] | None = None) -> Flask:
    frontend_dist = Path(__file__).resolve().parent.parent / "frontend" / "dist"
    app = Flask(__name__, static_folder=None)
    app.config.update(
        JSON_SORT_KEYS=False,
        MAX_CONTENT_LENGTH=64 * 1024,
    )
    if test_config:
        app.config.update(test_config)
    configure_database(app)

    @app.after_request
    def secure_headers(response):
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "SAMEORIGIN"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        if request.path.startswith("/api/"):
            response.headers["Cache-Control"] = "no-store"
        return response

    @app.errorhandler(ApiError)
    def handle_api_error(error: ApiError):
        return jsonify({"error": error.message}), error.status

    @app.errorhandler(IcaUpstreamError)
    def handle_ica_error(error: IcaUpstreamError):
        return jsonify({"error": str(error)}), 502

    @app.errorhandler(404)
    def handle_not_found(_error):
        return jsonify({"error": "Resursen hittades inte."}), 404

    @app.errorhandler(405)
    def handle_method_not_allowed(_error):
        return jsonify({"error": "Metoden är inte tillåten."}), 405

    @app.errorhandler(sqlite3.IntegrityError)
    def handle_integrity(error: sqlite3.IntegrityError):
        app.logger.warning("SQLite integrity error: %s", error)
        return jsonify({"error": "Posten krockar med befintliga data eller refererar till en saknad butik."}), 409

    @app.get("/api/health")
    def health():
        db = get_db()
        return jsonify(
            {
                "status": "ok",
                "database": "sqlite",
                "stores": db.execute("SELECT COUNT(*) FROM stores").fetchone()[0],
                "deals": db.execute("SELECT COUNT(*) FROM deals").fetchone()[0],
            }
        )

    @app.get("/api/stores")
    def list_stores():
        rows = get_db().execute(
            """SELECT s.*,
                      (SELECT COUNT(*) FROM deals d WHERE d.store_id = s.id) AS deal_count,
                      (SELECT MAX(created_at) FROM import_runs r
                       WHERE r.store_id = s.id AND r.status = 'success') AS last_sync
               FROM stores s ORDER BY s.city, s.name"""
        ).fetchall()
        return jsonify([dict(row) for row in rows])

    @app.post("/api/stores")
    def create_store():
        values = _store_payload(_json_object())
        db = get_db()
        cursor = db.execute(
            f"INSERT INTO stores ({', '.join(STORE_FIELDS)}) VALUES ({', '.join('?' for _ in STORE_FIELDS)})",
            tuple(values[field] for field in STORE_FIELDS),
        )
        db.commit()
        return jsonify(_one_store(cursor.lastrowid)), 201

    @app.get("/api/stores/<int:store_id>")
    def get_store(store_id: int):
        return jsonify(_one_store(store_id))

    @app.put("/api/stores/<int:store_id>")
    def update_store(store_id: int):
        _one_store(store_id)
        values = _store_payload(_json_object())
        get_db().execute(
            f"UPDATE stores SET {', '.join(f'{field} = ?' for field in STORE_FIELDS)}, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            tuple(values[field] for field in STORE_FIELDS) + (store_id,),
        )
        get_db().commit()
        return jsonify(_one_store(store_id))

    @app.delete("/api/stores/<int:store_id>")
    def delete_store(store_id: int):
        _one_store(store_id)
        db = get_db()
        db.execute("DELETE FROM stores WHERE id = ?", (store_id,))
        db.commit()
        return "", 204

    @app.get("/api/deals")
    def list_deals():
        store_id = request.args.get("store_id", type=int)
        query = request.args.get("q", "").strip()
        include_expired = request.args.get("include_expired") == "true"
        sql = """SELECT d.*, s.name AS store_name FROM deals d
                 JOIN stores s ON s.id = d.store_id WHERE 1 = 1"""
        params: list[Any] = []
        if store_id:
            sql += " AND d.store_id = ?"
            params.append(store_id)
        if query:
            escaped = query.replace("\\", "\\\\").replace("%", "\\%").replace("_", "\\_")
            sql += " AND (d.product_name LIKE ? ESCAPE '\\' OR d.brand LIKE ? ESCAPE '\\' OR d.promotion_text LIKE ? ESCAPE '\\')"
            params.extend([f"%{escaped}%"] * 3)
        if not include_expired:
            sql += " AND (d.valid_to IS NULL OR d.valid_to = '' OR d.valid_to >= date('now', 'localtime'))"
        sql += " ORDER BY CASE WHEN d.valid_to IS NULL OR d.valid_to = '' THEN 1 ELSE 0 END, d.valid_to, d.product_name"
        rows = get_db().execute(sql, params).fetchall()
        return jsonify([dict(row) for row in rows])

    @app.post("/api/deals")
    def create_deal():
        values = _deal_payload(_json_object())
        db = get_db()
        cursor = db.execute(
            f"INSERT INTO deals ({', '.join(DEAL_FIELDS)}) VALUES ({', '.join('?' for _ in DEAL_FIELDS)})",
            tuple(values[field] for field in DEAL_FIELDS),
        )
        db.commit()
        return jsonify(_one_deal(cursor.lastrowid)), 201

    @app.get("/api/deals/<int:deal_id>")
    def get_deal(deal_id: int):
        return jsonify(_one_deal(deal_id))

    @app.put("/api/deals/<int:deal_id>")
    def update_deal(deal_id: int):
        _one_deal(deal_id)
        values = _deal_payload(_json_object())
        db = get_db()
        db.execute(
            f"UPDATE deals SET {', '.join(f'{field} = ?' for field in DEAL_FIELDS)}, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
            tuple(values[field] for field in DEAL_FIELDS) + (deal_id,),
        )
        db.commit()
        return jsonify(_one_deal(deal_id))

    @app.delete("/api/deals/<int:deal_id>")
    def delete_deal(deal_id: int):
        _one_deal(deal_id)
        db = get_db()
        db.execute("DELETE FROM deals WHERE id = ?", (deal_id,))
        db.commit()
        return "", 204

    @app.get("/api/ica/stores")
    def find_ica_stores():
        postcode = request.args.get("postcode", "")
        try:
            stores = IcaClient().find_stores(postcode)
        except ValueError as error:
            raise ApiError(str(error)) from error
        return jsonify(stores)

    @app.post("/api/ica/stores/import")
    def import_ica_store():
        values = _store_payload(_json_object())
        if not values["ica_store_id"]:
            raise ApiError("ICA-butiken saknar id.")
        db = get_db()
        existing = db.execute("SELECT id FROM stores WHERE ica_store_id = ?", (values["ica_store_id"],)).fetchone()
        if existing:
            store_id = existing["id"]
            db.execute(
                f"UPDATE stores SET {', '.join(f'{field} = ?' for field in STORE_FIELDS)}, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                tuple(values[field] for field in STORE_FIELDS) + (store_id,),
            )
        else:
            cursor = db.execute(
                f"INSERT INTO stores ({', '.join(STORE_FIELDS)}) VALUES ({', '.join('?' for _ in STORE_FIELDS)})",
                tuple(values[field] for field in STORE_FIELDS),
            )
            store_id = cursor.lastrowid
        db.commit()
        return jsonify(_one_store(store_id)), 201 if not existing else 200

    @app.post("/api/stores/<int:store_id>/sync")
    def sync_store(store_id: int):
        store = _one_store(store_id)
        payload = _json_object()
        terms_value = payload.get("terms", [])
        if isinstance(terms_value, str):
            terms = [value.strip() for value in terms_value.split(",")]
        elif isinstance(terms_value, list):
            terms = [str(value).strip() for value in terms_value]
        else:
            raise ApiError("Sökord måste vara en lista eller kommaseparerad text.")
        terms = [value for value in terms if value][:12]
        if not terms:
            raise ApiError("Ange minst ett sökord.")
        if not store.get("account_id"):
            raise ApiError("Butiken saknar ICA accountId.")

        db = get_db()
        try:
            products = IcaClient().promoted_products(str(store["account_id"]), terms)
            imported = _upsert_ica_deals(db, store_id, products)
            db.execute(
                "INSERT INTO import_runs (store_id, status, imported_count, message) VALUES (?, 'success', ?, ?)",
                (store_id, imported, "Sökord: " + ", ".join(terms)),
            )
            db.commit()
            return jsonify({"imported": imported, "store_id": store_id})
        except (IcaUpstreamError, requests.RequestException, ValueError) as error:
            db.rollback()
            db.execute(
                "INSERT INTO import_runs (store_id, status, imported_count, message) VALUES (?, 'failed', 0, ?)",
                (store_id, str(error)[:500]),
            )
            db.commit()
            if isinstance(error, ValueError):
                raise ApiError(str(error)) from error
            raise IcaUpstreamError(str(error)) from error

    @app.get("/api/import-runs")
    def import_runs():
        rows = get_db().execute(
            """SELECT r.*, s.name AS store_name FROM import_runs r
               LEFT JOIN stores s ON s.id = r.store_id ORDER BY r.id DESC LIMIT 20"""
        ).fetchall()
        return jsonify([dict(row) for row in rows])

    @app.get("/")
    @app.get("/<path:path>")
    def frontend(path: str = ""):
        candidate = frontend_dist / path
        if path and candidate.is_file():
            return send_from_directory(frontend_dist, path)
        index = frontend_dist / "index.html"
        if index.is_file():
            return send_from_directory(frontend_dist, "index.html")
        raise ApiError("Frontend är inte byggd. Kör npm run build i frontend/.", 503)

    return app


def _json_object() -> dict[str, Any]:
    if not request.is_json:
        raise ApiError("Content-Type måste vara application/json.", 415)
    payload = request.get_json(silent=True)
    if not isinstance(payload, dict):
        raise ApiError("JSON-kroppen måste vara ett objekt.")
    return payload


def _clean_text(payload: dict[str, Any], field: str, maximum: int, required: bool = False) -> str:
    value = str(payload.get(field) or "").strip()[:maximum]
    if required and not value:
        raise ApiError(f"Fältet {field} måste anges.")
    return value


def _optional_number(payload: dict[str, Any], field: str) -> float | None:
    value = payload.get(field)
    if value in (None, ""):
        return None
    try:
        number = float(str(value).replace(",", "."))
    except ValueError as error:
        raise ApiError(f"Fältet {field} måste vara ett tal.") from error
    if field in {"deal_price", "regular_price"} and number < 0:
        raise ApiError("Pris kan inte vara negativt.")
    return round(number, 6)


def _optional_date(payload: dict[str, Any], field: str) -> str | None:
    value = _clean_text(payload, field, 10)
    if not value:
        return None
    try:
        date.fromisoformat(value)
    except ValueError as error:
        raise ApiError(f"Fältet {field} måste vara ett ISO-datum (ÅÅÅÅ-MM-DD).") from error
    return value


def _optional_url(payload: dict[str, Any], field: str) -> str | None:
    value = _clean_text(payload, field, 1000)
    if not value:
        return None
    parsed = urlparse(value)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        raise ApiError(f"Fältet {field} måste vara en http- eller https-URL.")
    return value


def _store_payload(payload: dict[str, Any]) -> dict[str, Any]:
    store_format = _clean_text(payload, "store_format", 30) or "other"
    if store_format not in {"maxi", "kvantum", "supermarket", "nara", "other"}:
        store_format = "other"
    return {
        "ica_store_id": _clean_text(payload, "ica_store_id", 40) or None,
        "account_id": _clean_text(payload, "account_id", 40) or None,
        "name": _clean_text(payload, "name", 120, required=True),
        "store_format": store_format,
        "street": _clean_text(payload, "street", 160),
        "postcode": _clean_text(payload, "postcode", 12),
        "city": _clean_text(payload, "city", 100),
        "latitude": _optional_number(payload, "latitude"),
        "longitude": _optional_number(payload, "longitude"),
    }


def _deal_payload(payload: dict[str, Any]) -> dict[str, Any]:
    try:
        store_id = int(payload.get("store_id"))
    except (TypeError, ValueError) as error:
        raise ApiError("En giltig butik måste väljas.") from error
    source = _clean_text(payload, "source", 20) or "manual"
    if source not in {"manual", "ica_api", "demo"}:
        source = "manual"
    valid_from = _optional_date(payload, "valid_from")
    valid_to = _optional_date(payload, "valid_to")
    if valid_from and valid_to and valid_from > valid_to:
        raise ApiError("Slutdatum kan inte vara före startdatum.")
    return {
        "store_id": store_id,
        "external_id": _clean_text(payload, "external_id", 180) or None,
        "product_name": _clean_text(payload, "product_name", 180, required=True),
        "brand": _clean_text(payload, "brand", 100),
        "description": _clean_text(payload, "description", 600),
        "deal_price": _optional_number(payload, "deal_price"),
        "regular_price": _optional_number(payload, "regular_price"),
        "price_text": _clean_text(payload, "price_text", 80),
        "quantity_text": _clean_text(payload, "quantity_text", 100),
        "promotion_text": _clean_text(payload, "promotion_text", 250),
        "category": _clean_text(payload, "category", 120),
        "country_of_origin": _clean_text(payload, "country_of_origin", 100),
        "image_url": _optional_url(payload, "image_url"),
        "product_url": _optional_url(payload, "product_url"),
        "valid_from": valid_from,
        "valid_to": valid_to,
        "source": source,
    }


def _one_store(store_id: int) -> dict[str, Any]:
    row = get_db().execute(
        """SELECT s.*, (SELECT COUNT(*) FROM deals d WHERE d.store_id = s.id) AS deal_count
           FROM stores s WHERE s.id = ?""",
        (store_id,),
    ).fetchone()
    if row is None:
        raise ApiError("Butiken hittades inte.", 404)
    return dict(row)


def _one_deal(deal_id: int) -> dict[str, Any]:
    row = get_db().execute(
        """SELECT d.*, s.name AS store_name FROM deals d
           JOIN stores s ON s.id = d.store_id WHERE d.id = ?""",
        (deal_id,),
    ).fetchone()
    if row is None:
        raise ApiError("Erbjudandet hittades inte.", 404)
    return dict(row)


def _upsert_ica_deals(db: sqlite3.Connection, store_id: int, products: list[dict[str, Any]]) -> int:
    fields = [field for field in DEAL_FIELDS if field not in {"store_id", "source"}]
    for product in products:
        existing = db.execute(
            "SELECT id FROM deals WHERE store_id = ? AND external_id = ?",
            (store_id, product["external_id"]),
        ).fetchone()
        values = [product.get(field) for field in fields]
        if existing:
            db.execute(
                f"UPDATE deals SET {', '.join(f'{field} = ?' for field in fields)}, source = 'ica_api', synced_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
                values + [existing["id"]],
            )
        else:
            db.execute(
                f"INSERT INTO deals (store_id, {', '.join(fields)}, source, synced_at) VALUES (?, {', '.join('?' for _ in fields)}, 'ica_api', CURRENT_TIMESTAMP)",
                [store_id] + values,
            )
    return len(products)


if __name__ == "__main__":
    application = create_app()
    application.run(host=os.environ.get("API_HOST", "127.0.0.1"), port=int(os.environ.get("API_PORT", "8000")), debug=True)
