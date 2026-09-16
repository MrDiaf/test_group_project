from __future__ import annotations

import json
import unittest

from ica import IcaClient, _extract_object_after_marker


class IcaParserTestCase(unittest.TestCase):
    def test_extracts_balanced_product_entities(self) -> None:
        entity = {"abc": {"name": "Kaffe {mellan}", "nested": {"value": "quoted } text"}}}
        html = '<script>window.state={"productEntities":' + json.dumps(entity) + ',"next":true}</script>'
        self.assertEqual(_extract_object_after_marker(html, '"productEntities":'), entity)

    def test_normalizes_price_reduction(self) -> None:
        product = {
            "productId": "uuid-1",
            "retailerProductId": "123",
            "name": "Kaffe 500g",
            "brand": "ICA",
            "price": {"original": {"amount": "59.90"}, "current": {"amount": "39.90"}},
            "size": {"value": "500g"},
            "categoryPath": ["Dryck", "Kaffe"],
            "image": {"src": "https://example.test/image.jpg"},
        }
        deal = IcaClient()._normalize_deal(product, "1004599")
        self.assertIsNotNone(deal)
        assert deal is not None
        self.assertEqual(deal["deal_price"], 39.9)
        self.assertEqual(deal["regular_price"], 59.9)
        self.assertEqual(deal["promotion_text"], "Sänkt pris")

    def test_ignores_regular_price_product(self) -> None:
        product = {
            "productId": "uuid-2",
            "name": "Mjölk",
            "price": {"current": {"amount": "20.00"}},
        }
        self.assertIsNone(IcaClient()._normalize_deal(product, "1004599"))


if __name__ == "__main__":
    unittest.main()
