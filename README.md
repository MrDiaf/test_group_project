# Lokala fynd – TypeScript + Python + Apache

En CRUD-demo för lokala ICA-erbjudanden. Frontend är skriven i TypeScript och byggs till statiska, native ES-moduler. Python/Flask tillhandahåller ett JSON-API, använder SQLite och hämtar publika butiks- och produktdata från ICA:s webbshop. Apache är den publika webbservern: den serverar frontend och reverse-proxyar `/api` till Gunicorn.

> Projektet är fristående och är inte anslutet till eller godkänt av ICA. ICA:s webbgränssnitt är odokumenterade och kan ändras. Använd integrationen varsamt och i enlighet med ICA:s villkor.

## Arkitektur

```text
Webbläsare
    │
    ▼
Apache :80
    ├── /, /assets/*  ──► frontend/dist (TypeScript/Vite)
    └── /api/*        ──► Gunicorn :8000 ──► Flask ──► SQLite
                                                  └──► ICA:s publika webbtjänster
```

Apache kör alltså inte TypeScript eller Python direkt. TypeScript kompileras till JavaScript som Apache kan servera, och Python körs som en lokal WSGI-tjänst bakom Apaches proxy. Det är en vanlig och stabil produktionsmodell.

## Funktioner

- välj butik och visa dess lokalt sparade erbjudanden;
- full CRUD för butiker och erbjudanden via ett REST-API;
- sökning och filtrering av erbjudanden;
- verklig ICA-butikssökning via svenskt postnummer;
- synkning av butiksspecifika kampanjvaror;
- importhistorik och tydliga upstream-fel;
- SQLite med främmande nycklar, cascade delete och idempotent ICA-upsert;
- responsiv TypeScript-SPA utan frontendramverk;
- Apache-konfiguration och systemd-tjänst för Python-API:t.

## Projektstruktur

```text
backend/
  app.py                 Flask REST-API och CRUD
  database.py            SQLite-anslutning, migration och demodata
  ica.py                 ICA-session, sökning, CSRF och normalisering
  requirements.txt       Python-beroenden
  tests/                  API- och ICA-parsertester
  wsgi.py                 Gunicorn-entrypoint
database/schema.sql       Databasschema
deploy/
  apache-lokala-fynd.conf Apache VirtualHost
  lokala-fynd-api.service systemd unit för Gunicorn
frontend/
  src/                    TypeScript, API-klient och CSS
  scripts/build.mjs       Kopierar statiska resurser utan bundler
  public/.htaccess        SPA fallback och säkerhetsheaders
  dist/                   Native ES-moduler, genereras med npm run build
data/                     SQLite-filen skapas här
```

## Lokal utveckling

Krav: Python 3.11+, Node.js 18+ och npm.

Det enklaste sättet att installera, bygga och starta båda processerna är:

```bash
make start
```

Öppna `http://127.0.0.1:5173`. Avsluta båda processerna med:

```bash
make stop
```

`make status` visar om processerna kör och `make logs` följer båda loggfilerna. Makefile-målen beskrivs även med `make help`.

### 1. Bygg TypeScript-frontend

```bash
cd frontend
npm install
npm run build
```

Bygget använder endast TypeScript-kompilatorn och genererar native ES-moduler. Vite/Webpack behövs inte.

### 2. Starta Python-API:t

```bash
cd backend
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python app.py
```

API:t kör nu på `http://127.0.0.1:8000`. Öppna samma adress i webbläsaren; Flask serverar den byggda frontend-versionen lokalt. SQLite-filen och demodata skapas automatiskt vid första start.

## Driftsättning på Apache

Exemplet antar att projektet ligger i `/var/www/lokala-fynd`.

### Paket

På Ubuntu/Debian:

```bash
sudo apt update
sudo apt install apache2 python3-venv nodejs npm
sudo a2enmod proxy proxy_http rewrite headers
```

`mod_wsgi` behövs inte; Apache pratar HTTP med Gunicorn på localhost.

### Bygg och installera

```bash
cd /var/www/lokala-fynd/frontend
npm install
npm run build

cd /var/www/lokala-fynd/backend
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt

sudo chown -R www-data:www-data /var/www/lokala-fynd/data
sudo chmod 775 /var/www/lokala-fynd/data
```

### Starta API-tjänsten

```bash
sudo cp /var/www/lokala-fynd/deploy/lokala-fynd-api.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now lokala-fynd-api
curl http://127.0.0.1:8000/api/health
```

Svaret ska likna:

```json
{"database":"sqlite","deals":3,"status":"ok","stores":3}
```

### Aktivera Apache

Ändra `ServerName` och sökvägar i `deploy/apache-lokala-fynd.conf` om det behövs. Aktivera sedan sajten:

```bash
sudo cp /var/www/lokala-fynd/deploy/apache-lokala-fynd.conf /etc/apache2/sites-available/lokala-fynd.conf
sudo a2ensite lokala-fynd
sudo apache2ctl configtest
sudo systemctl reload apache2
```

Apache serverar nu frontend och vidarebefordrar alla `/api`-anrop till Python. Lägg till TLS med till exempel Certbot innan publik drift.

## Databasschema

SQLite är inbäddat i Python-processen; ingen separat databasserver behövs.

```text
stores 1 ──────── * deals
   │
   └───────────── * import_runs
```

### `stores`

| Kolumn | Innehåll |
|---|---|
| `id` | Lokal primärnyckel. |
| `ica_store_id` | Fältet `id` från ICA:s butikssökning. |
| `account_id` | Fältet `accountId`, som väljer rätt onlinekatalog. |
| `name`, `store_format` | Butiksnamn och format. |
| `street`, `postcode`, `city` | Adress. |
| `latitude`, `longitude` | Valfri position från ICA. |

ICA:s `id` och `accountId` är olika identiteter och lagras därför separat.

### `deals`

| Kolumn | Innehåll |
|---|---|
| `store_id` | Främmande nyckel till `stores`; `ON DELETE CASCADE`. |
| `external_id` | ICA:s UUID eller en lokal identifierare; unik per butik när den finns. |
| `product_name`, `brand`, `description` | Produktinformation. |
| `deal_price`, `regular_price`, `price_text` | Numeriska priser samt text för multiköp. |
| `quantity_text`, `promotion_text` | Storlek och kampanjvillkor. |
| `category`, `country_of_origin` | Kategori och ursprungsland från produktobjektet. |
| `image_url`, `product_url` | Externa HTTPS-länkar. |
| `valid_from`, `valid_to` | ISO-datum eller `NULL`. |
| `source` | `manual`, `demo` eller `ica_api`. |
| `synced_at` | Senaste ICA-uppdatering. |

En partiell unik indexering på `(store_id, external_id)` gör synkningen idempotent. Manuella erbjudanden påverkas inte när ICA-data uppdateras.

### `import_runs`

Loggar lyckade och misslyckade synkningar med butik, antal poster, meddelande och tidpunkt.

## ICA-integrationen

Integrationen använder publika anrop från ICA:s egna webbgränssnitt:

1. `GET https://handla.ica.se/api/store/v1?zip=...&customerType=B2C` returnerar närliggande onlinebutiker.
2. Butikssidan `GET /stores/{accountId}/products` innehåller initial state med CSRF-token och `productEntities`.
3. `GET /stores/{accountId}/api/webproductpagews/v6/product-pages/search` returnerar produktgrupper och UUID:n för ett sökord.
4. `PUT /stores/{accountId}/api/webproductpagews/v6/products` hämtar full produktinformation i batch. Python-sessionen skickar cookie, `Origin`, `Referer`, fetch-headers och den aktuella CSRF-token.

Det sista steget är varför integrationen fungerar bättre än den tidigare PHP-versionen: ett naket serveranrop får ofta HTTP 403, medan webbshopens faktiska request context accepteras. Server-renderade `productEntities` används dessutom som fallback och ger kampanjer även om batchanropet tillfälligt blockeras.

En produkt importeras som erbjudande när den har minst ett `offer`/`offers`-objekt eller när `price.current` är lägre än `price.original`. ICA:s multiköp, till exempel “2 för 50 kr”, bevaras i `promotion_text`.

ICA kan fortfarande visa en AWS WAF-kontroll vid många eller täta anrop. API:t returnerar då ett begripligt 502-fel och skriver en misslyckad rad i `import_runs`; befintliga erbjudanden raderas aldrig. Högst tolv sökord används per manuell synkning.

Den äldre community-dokumentationen för `handla.api.ica.se` är [markerad som inaktuell sedan april 2024](https://github.com/svendahlstrand/ica-api). Den nuvarande butiksspecifika katalogen och fälten är även beskrivna av den oberoende [ICA storefront-scrapern](https://apify.com/studio-amba/ica-scraper).

## REST-API

| Metod | Endpoint | Funktion |
|---|---|---|
| `GET` | `/api/health` | Hälsa och postantal. |
| `GET/POST` | `/api/stores` | Lista eller skapa butik. |
| `GET/PUT/DELETE` | `/api/stores/{id}` | Läs, uppdatera eller radera butik. |
| `GET/POST` | `/api/deals` | Filtrera eller skapa erbjudande. |
| `GET/PUT/DELETE` | `/api/deals/{id}` | Läs, uppdatera eller radera erbjudande. |
| `GET` | `/api/ica/stores?postcode=11122` | Sök butiker hos ICA. |
| `POST` | `/api/ica/stores/import` | Importera/uppdatera hittad butik. |
| `POST` | `/api/stores/{id}/sync` | Synka butikens kampanjvaror. |
| `GET` | `/api/import-runs` | Visa synkhistorik. |

Skrivande endpoints kräver JSON och svarar med JSON-fel. Databasen använder parametriserade frågor och frontend renderar all extern text HTML-kodad.

Det finns inga användarkonton i demon. Innan en publik driftsättning bör `/api` och administrationsvyerna skyddas med autentisering, till exempel Apaches OIDC/basic auth eller en applikationsinloggning. Utan detta kan alla som når webbplatsen ändra data.

## Tester

```bash
cd backend
.venv/bin/python -m unittest discover -s tests -v

cd ../frontend
npm run build
```

Backendtesterna använder en temporär SQLite-fil och mockar ICA för deterministisk CRUD-/synkverifiering. Den verkliga butikssökningen kan kontrolleras med:

```bash
curl 'http://127.0.0.1:8000/api/ica/stores?postcode=11122'
```

## Återställa demodata

Stoppa API-tjänsten, säkerhetskopiera vid behov och ta bort `data/deals.sqlite`. Nästa start skapar schema och demoposter igen.
