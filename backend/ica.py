from __future__ import annotations

import json
import re
import unicodedata
from dataclasses import dataclass
from typing import Any, Iterable
from urllib.parse import quote

import requests

STORE_API = "https://handla.ica.se/api/store/v1"
SHOP_BASE = "https://handlaprivatkund.ica.se"
USER_AGENT = (
    "Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36"
)


class IcaUpstreamError(RuntimeError):
    pass


@dataclass
class StorefrontState:
    csrf_token: str
    products: dict[str, dict[str, Any]]


class IcaClient:
    def __init__(self, timeout: int = 25) -> None:
        self.timeout = timeout
        self.session = requests.Session()
        self.session.headers.update(
            {
                "User-Agent": USER_AGENT,
                "Accept-Language": "sv-SE,sv;q=0.9,en;q=0.7",
            }
        )

    def find_stores(self, postcode: str) -> list[dict[str, Any]]:
        normalized = re.sub(r"\D", "", postcode)
        if len(normalized) != 5:
            raise ValueError("Ange ett svenskt postnummer med fem siffror.")
        response = self.session.get(
            STORE_API,
            params={"zip": normalized, "customerType": "B2C"},
            headers={"Accept": "application/json"},
            timeout=self.timeout,
        )
        self._raise_for_status(response, "butikssökning")
        payload = response.json()
        found: dict[str, dict[str, Any]] = {}
        for group in payload.values():
            if not isinstance(group, list):
                continue
            for store in group:
                if not isinstance(store, dict) or not store.get("id") or not store.get("name"):
                    continue
                store_id = str(store["id"])
                found[store_id] = {
                    "ica_store_id": store_id,
                    "account_id": str(store.get("accountId") or ""),
                    "name": str(store["name"]),
                    "store_format": str(store.get("storeFormat") or "other"),
                    "street": str(store.get("street") or ""),
                    "postcode": str(store.get("zipCode") or ""),
                    "city": str(store.get("city") or ""),
                    "latitude": _number(store.get("latitude")),
                    "longitude": _number(store.get("longitude")),
                }
        return list(found.values())[:30]

    def promoted_products(self, account_id: str, terms: Iterable[str]) -> list[dict[str, Any]]:
        if not re.fullmatch(r"\d{4,20}", account_id):
            raise ValueError("Butiken saknar ett giltigt ICA accountId.")

        state = self._load_storefront(account_id)
        products = dict(state.products)
        unresolved: set[str] = set()
        cleaned_terms = [term.strip() for term in terms if term.strip()][:12]

        for term in cleaned_terms:
            response = self.session.get(
                f"{SHOP_BASE}/stores/{quote(account_id)}/api/webproductpagews/v6/product-pages/search",
                params={"q": term, "tag": "web", "maxPageSize": 60},
                headers=self._api_headers(account_id),
                timeout=self.timeout,
            )
            self._raise_for_status(response, f"produktsökning efter {term!r}")
            payload = response.json()
            for group in payload.get("productGroups", []):
                for product in group.get("decoratedProducts", []):
                    if isinstance(product, dict) and product.get("productId"):
                        products[str(product["productId"])] = product
                unresolved.update(str(value) for value in group.get("otherProductIds", []) if value)

        unresolved.difference_update(products)
        if unresolved:
            for chunk in _chunks(sorted(unresolved), 50):
                response = self.session.put(
                    f"{SHOP_BASE}/stores/{quote(account_id)}/api/webproductpagews/v6/products",
                    json=chunk,
                    headers={
                        **self._api_headers(account_id),
                        "Content-Type": "application/json; charset=utf-8",
                        "x-csrf-token": state.csrf_token,
                    },
                    timeout=self.timeout,
                )
                if response.status_code == 403:
                    # ICA occasionally rotates the token. Refresh it once.
                    state = self._load_storefront(account_id, no_cache=True)
                    response = self.session.put(
                        f"{SHOP_BASE}/stores/{quote(account_id)}/api/webproductpagews/v6/products",
                        json=chunk,
                        headers={
                            **self._api_headers(account_id),
                            "Content-Type": "application/json; charset=utf-8",
                            "x-csrf-token": state.csrf_token,
                        },
                        timeout=self.timeout,
                    )
                self._raise_for_status(response, "hämtning av produktdetaljer")
                for product in response.json().get("products", []):
                    if isinstance(product, dict) and product.get("productId"):
                        products[str(product["productId"])] = product

        normalized = []
        for product in products.values():
            deal = self._normalize_deal(product, account_id)
            if deal is not None:
                normalized.append(deal)
        return sorted(normalized, key=lambda item: item["product_name"].casefold())

    def _load_storefront(self, account_id: str, no_cache: bool = False) -> StorefrontState:
        headers = {
            "Accept": "text/html,application/xhtml+xml",
            "Referer": f"{SHOP_BASE}/stores/{quote(account_id)}/products",
        }
        if no_cache:
            headers["Cache-Control"] = "no-cache"
        response = self.session.get(
            f"{SHOP_BASE}/stores/{quote(account_id)}/products",
            headers=headers,
            timeout=self.timeout,
        )
        self._raise_for_status(response, "ICA:s butikssida")
        if response.status_code == 202 or "window.awsWafCookieDomainList" in response.text:
            raise IcaUpstreamError("ICA visade en tillfällig WAF-kontroll. Vänta en minut och försök igen.")
        csrf_match = re.search(r'"csrf":\{"token":"([^"]+)"', response.text)
        if not csrf_match:
            raise IcaUpstreamError("ICA:s butikssida saknade CSRF-token; sidformatet kan ha ändrats.")
        entities = _extract_object_after_marker(response.text, '"productEntities":')
        return StorefrontState(csrf_match.group(1), entities)

    def _api_headers(self, account_id: str) -> dict[str, str]:
        return {
            "Accept": "application/json, text/plain, */*",
            "Origin": SHOP_BASE,
            "Referer": f"{SHOP_BASE}/stores/{quote(account_id)}/products",
            "Sec-Fetch-Dest": "empty",
            "Sec-Fetch-Mode": "cors",
            "Sec-Fetch-Site": "same-origin",
        }

    def _normalize_deal(self, product: dict[str, Any], account_id: str) -> dict[str, Any] | None:
        price = product.get("price") if isinstance(product.get("price"), dict) else {}
        current = _nested_number(price, "current", "amount") or _nested_number(price, "amount")
        original = _nested_number(price, "original", "amount")
        offers = product.get("offers") if isinstance(product.get("offers"), list) else []
        offer = product.get("offer") if isinstance(product.get("offer"), dict) else None
        if offer and not offers:
            offers = [offer]
        descriptions = [str(value.get("description")).strip() for value in offers if value.get("description")]
        discounted = current is not None and original is not None and current < original
        if not descriptions and not discounted:
            return None

        product_id = str(product.get("productId") or "")
        retailer_id = str(product.get("retailerProductId") or "")
        name = str(product.get("name") or "Okänd produkt")
        image = product.get("image") if isinstance(product.get("image"), dict) else {}
        size = product.get("size") if isinstance(product.get("size"), dict) else {}
        categories = product.get("categoryPath") if isinstance(product.get("categoryPath"), list) else []
        promotion_text = " · ".join(dict.fromkeys(descriptions)) or "Sänkt pris"
        return {
            "external_id": product_id or f"retailer-{retailer_id}",
            "product_name": name,
            "brand": str(product.get("brand") or ""),
            "description": promotion_text,
            "deal_price": current,
            "regular_price": original,
            "price_text": _format_price(current) if current is not None else promotion_text,
            "quantity_text": str(size.get("value") or ""),
            "promotion_text": promotion_text,
            "category": str(categories[-1]) if categories else "",
            "country_of_origin": str(product.get("countryOfOrigin") or ""),
            "image_url": str(image.get("src") or "") or None,
            "product_url": (
                f"{SHOP_BASE}/stores/{quote(account_id)}/products/{_slug(name)}/{quote(retailer_id)}"
                if retailer_id
                else f"{SHOP_BASE}/stores/{quote(account_id)}/products"
            ),
            "valid_from": None,
            "valid_to": None,
        }

    @staticmethod
    def _raise_for_status(response: requests.Response, operation: str) -> None:
        if 200 <= response.status_code < 300:
            return
        raise IcaUpstreamError(f"ICA misslyckades med {operation} (HTTP {response.status_code}).")


def _extract_object_after_marker(text: str, marker: str) -> dict[str, Any]:
    marker_index = text.find(marker)
    if marker_index < 0:
        return {}
    start = text.find("{", marker_index + len(marker))
    if start < 0:
        return {}
    depth = 0
    in_string = False
    escaped = False
    for index in range(start, len(text)):
        char = text[index]
        if in_string:
            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_string = False
            continue
        if char == '"':
            in_string = True
        elif char == "{":
            depth += 1
        elif char == "}":
            depth -= 1
            if depth == 0:
                value = json.loads(text[start : index + 1])
                return value if isinstance(value, dict) else {}
    return {}


def _chunks(values: list[str], size: int) -> Iterable[list[str]]:
    for index in range(0, len(values), size):
        yield values[index : index + size]


def _number(value: Any) -> float | None:
    try:
        return float(value) if value is not None and value != "" else None
    except (TypeError, ValueError):
        return None


def _nested_number(data: dict[str, Any], *path: str) -> float | None:
    value: Any = data
    for part in path:
        if not isinstance(value, dict) or part not in value:
            return None
        value = value[part]
    return _number(value)


def _format_price(value: float) -> str:
    decimals = 0 if value.is_integer() else 2
    return f"{value:.{decimals}f}".replace(".", ",") + " kr"


def _slug(value: str) -> str:
    normalized = unicodedata.normalize("NFKD", value).encode("ascii", "ignore").decode("ascii")
    return re.sub(r"[^a-z0-9]+", "-", normalized.lower()).strip("-")[:100] or "produkt"
