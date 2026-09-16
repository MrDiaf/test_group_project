from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

from app import create_app


class FakeIcaClient:
    def find_stores(self, _postcode: str):
        return [
            {
                "ica_store_id": "99999",
                "account_id": "1009999",
                "name": "ICA Test",
                "store_format": "nara",
                "street": "Testgatan 1",
                "postcode": "123 45",
                "city": "Teststad",
                "latitude": 59.0,
                "longitude": 18.0,
            }
        ]

    def promoted_products(self, _account_id: str, _terms):
        return [
            {
                "external_id": "ica-product-1",
                "product_name": "Synkat kaffe",
                "brand": "ICA",
                "description": "2 för 50 kr",
                "deal_price": 29.9,
                "regular_price": 39.9,
                "price_text": "29,90 kr",
                "quantity_text": "500g",
                "promotion_text": "2 för 50 kr",
                "category": "Kaffe",
                "country_of_origin": "Sverige",
                "image_url": "https://example.test/coffee.jpg",
                "product_url": "https://example.test/coffee",
                "valid_from": None,
                "valid_to": None,
            }
        ]


class ApiTestCase(unittest.TestCase):
    def setUp(self) -> None:
        self.temp_dir = tempfile.TemporaryDirectory()
        database = str(Path(self.temp_dir.name) / "test.sqlite")
        self.app = create_app({"TESTING": True, "DATABASE": database})
        self.client = self.app.test_client()

    def tearDown(self) -> None:
        self.temp_dir.cleanup()

    def test_health_and_seed_data(self) -> None:
        response = self.client.get("/api/health")
        self.assertEqual(response.status_code, 200)
        self.assertEqual(response.json["database"], "sqlite")
        self.assertEqual(response.json["stores"], 3)
        self.assertEqual(response.json["deals"], 3)

    def test_store_crud_and_cascade(self) -> None:
        payload = {
            "name": "ICA Testbutik",
            "store_format": "nara",
            "city": "Umeå",
            "account_id": "1008888",
        }
        created = self.client.post("/api/stores", json=payload)
        self.assertEqual(created.status_code, 201)
        store_id = created.json["id"]

        payload["name"] = "ICA Uppdaterad"
        updated = self.client.put(f"/api/stores/{store_id}", json=payload)
        self.assertEqual(updated.json["name"], "ICA Uppdaterad")

        deal = self.client.post(
            "/api/deals",
            json={"store_id": store_id, "product_name": "Testvara", "deal_price": 10},
        )
        self.assertEqual(deal.status_code, 201)
        self.assertEqual(deal.json["store_id"], store_id)

        deleted = self.client.delete(f"/api/stores/{store_id}")
        self.assertEqual(deleted.status_code, 204)
        remaining = self.client.get(f"/api/deals?store_id={store_id}&include_expired=true")
        self.assertEqual(remaining.json, [])

    def test_deal_update_and_delete(self) -> None:
        created = self.client.post(
            "/api/deals",
            json={
                "store_id": 1,
                "product_name": "Pasta",
                "deal_price": "19.90",
                "valid_from": "2026-09-01",
                "valid_to": "2026-09-30",
            },
        )
        deal_id = created.json["id"]
        payload = created.json | {"product_name": "Pasta uppdaterad", "deal_price": 17.9}
        updated = self.client.put(f"/api/deals/{deal_id}", json=payload)
        self.assertEqual(updated.status_code, 200)
        self.assertEqual(updated.json["product_name"], "Pasta uppdaterad")
        self.assertEqual(self.client.delete(f"/api/deals/{deal_id}").status_code, 204)
        self.assertEqual(self.client.get(f"/api/deals/{deal_id}").status_code, 404)

    @patch("app.IcaClient", FakeIcaClient)
    def test_ica_lookup_import_and_sync(self) -> None:
        lookup = self.client.get("/api/ica/stores?postcode=12345")
        self.assertEqual(lookup.status_code, 200)
        self.assertEqual(lookup.json[0]["name"], "ICA Test")

        imported = self.client.post("/api/ica/stores/import", json=lookup.json[0])
        self.assertEqual(imported.status_code, 201)
        store_id = imported.json["id"]

        sync = self.client.post(f"/api/stores/{store_id}/sync", json={"terms": "kaffe"})
        self.assertEqual(sync.status_code, 200)
        self.assertEqual(sync.json["imported"], 1)
        deals = self.client.get(f"/api/deals?store_id={store_id}")
        self.assertEqual(deals.json[0]["source"], "ica_api")
        self.assertEqual(deals.json[0]["product_name"], "Synkat kaffe")

    def test_validation_errors_are_json(self) -> None:
        response = self.client.post("/api/deals", json={"store_id": 1, "product_name": "", "deal_price": -1})
        self.assertEqual(response.status_code, 400)
        self.assertIn("error", response.json)


if __name__ == "__main__":
    unittest.main()
