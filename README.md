# Lokala fynd – CRUD-demo för ICA-erbjudanden

En liten, ramverksfri PHP-applikation där besökaren väljer en ICA-butik och ser erbjudanden som hör till just den platsen. Appen är byggd efter samma enkla modell som projektet i `example/`, men använder SQLite i stället för PostgreSQL och har full CRUD för både butiker och erbjudanden.

SQLite är en inbäddad databas, inte en separat databasserver: Apache/PHP-processen läser och skriver direkt i `data/deals.sqlite`. Det gör demon enkel att flytta och driftsätta utan ytterligare tjänster.

> **Viktigt:** Projektet är en fristående demo och är inte anslutet till, godkänt av eller supportat av ICA. ICA erbjuder inget dokumenterat publikt utvecklar-API för dessa data. Integrationen använder odokumenterade JSON-anrop från ICA:s publika butikssökning/webbshop och kan därför sluta fungera utan förvarning.
<>
## Funktioner

- välj en butik och visa alla lokalt sparade, aktuella erbjudanden för platsen;
- sök bland varor, varumärken och kampanjtexter;
- skapa, läsa, uppdatera och radera butiker;
- skapa, läsa, uppdatera och radera erbjudanden;
- hitta verkliga ICA-butiker via svenskt postnummer;
- synka kampanjmärkta produkter från en butiks webbshopskatalog;
- automatisk databasinitiering och tydligt märkt demodata;
- responsivt gränssnitt, förberedda SQL-frågor, CSRF-skydd och POST för alla ändringar.

## Krav

- Apache 2.4 med `AllowOverride All` (för `.htaccess`);
- PHP 8.1 eller senare;
- PHP-tillägget `pdo_sqlite`;
- helst PHP-tillägget `curl` för ICA-synk. Appen kan falla tillbaka till HTTPS-strömmar om `allow_url_fopen` är aktiverat.

På Ubuntu/Debian:

```bash
sudo apt update
sudo apt install apache2 libapache2-mod-php php-sqlite3 php-curl
sudo a2enmod headers
sudo systemctl restart apache2
```

## Köra med Apache

1. Lägg projektmappen under Apaches document root, exempelvis `/var/www/html/lokala-fynd`.
2. Ge webbservern skrivrättighet till enbart `data/`:

   ```bash
   sudo chown -R www-data:www-data /var/www/html/lokala-fynd/data
   sudo chmod 775 /var/www/html/lokala-fynd/data
   ```

3. Kontrollera att aktuell `<Directory>` i Apache-konfigurationen tillåter `.htaccess`:

   ```apache
   <Directory /var/www/html/lokala-fynd>
       AllowOverride All
       Require all granted
   </Directory>
   ```

4. Öppna `http://localhost/lokala-fynd/`. Filen `data/deals.sqlite` skapas och fylls med demodata vid första anropet.

För en snabb lokal kontroll kan PHP:s inbyggda server också användas:

```bash
php -S 127.0.0.1:8080
```

Öppna därefter `http://127.0.0.1:8080`. Apache är fortfarande rekommenderat för den avsedda driftsmiljön eftersom `.htaccess` inte används av den inbyggda servern.

## Databasschema

Schemat finns i [`database/schema.sql`](database/schema.sql). SQLite-filen skapas automatiskt och migreringen är idempotent (`CREATE TABLE IF NOT EXISTS`).

```text
stores 1 ──────── * deals
   │
   └───────────── * import_runs
```

### `stores`

En rad per fysisk butik/plats.

| Kolumn | Innehåll |
|---|---|
| `id` | Lokal heltalsnyckel. Används av appens relationer. |
| `ica_store_id` | ICA-butikssökningens `id`; unikt när det finns. |
| `account_id` | Webbshopens `accountId`. Behövs för det butiksspecifika produktanropet. |
| `name`, `store_format` | Visningsnamn och format, till exempel `maxi` eller `kvantum`. |
| `street`, `postcode`, `city` | Adressfält för presentation och sökning. |
| `latitude`, `longitude` | Valfri position från ICA:s butikssökning. |
| `created_at`, `updated_at` | Lokala tidsstämplar. |

`ica_store_id` och `account_id` hålls isär eftersom de representerar olika identiteter i ICA:s webbtjänster. Den första identifierar butiksplatsen, medan den andra väljer sortimentet i onlinebutiken.

### `deals`

En rad per erbjudande i en butik. `store_id` är en främmande nyckel till `stores.id` med `ON DELETE CASCADE`, så erbjudanden försvinner när butiken tas bort.

| Kolumn | Innehåll |
|---|---|
| `external_id` | Produkt-id från ICA eller en stabil lokalt genererad nyckel. Unikt per butik när det finns. |
| `product_name`, `brand`, `description` | Produktens textfält. |
| `deal_price`, `regular_price` | Numeriska priser som kan användas för beräkningar. |
| `price_text` | Kampanjpris som inte alltid är ett enkelt tal, exempelvis “2 för 50 kr”. |
| `quantity_text`, `promotion_text` | Förpackningsstorlek och kampanjvillkor. |
| `image_url`, `product_url` | Valfria externa länkar; inga bilder laddas ned till databasen. |
| `valid_from`, `valid_to` | ISO-datum (`YYYY-MM-DD`) eller `NULL`. |
| `source` | `manual`, `ica_api` eller `demo`. |
| `synced_at` | Senaste lyckade uppdatering från ICA. |

Den partiella unika indexeringen på `(store_id, external_id)` gör synkningen idempotent: samma ICA-produkt uppdateras i stället för att dupliceras. Manuella rader kan sakna `external_id` och påverkas inte av synkningen.

### `import_runs`

En enkel revisionslogg för erbjudandesynkningar. Den lagrar butik, status, antal importerade poster, meddelande och tidpunkt. Om en butik raderas sätts `store_id` till `NULL`, så historikraden kan finnas kvar.

## ICA-data och synkflöde

Projektet använder två odokumenterade, publikt åtkomliga webbgränssnitt:

1. `GET https://handla.ica.se/api/store/v1?zip=...&customerType=B2C` hittar onlineanslutna butiker nära ett postnummer. Svaret ger bland annat `id`, `accountId`, adress och koordinater.
2. `GET https://handlaprivatkund.ica.se/stores/{accountId}/api/webproductpagews/v6/product-pages/search` söker i den valda butikens katalog. Appen sparar bara svar som innehåller kampanjinformation.

Produktgränssnittet är sökbaserat, så “synka” söker med en redigerbar lista av breda svenska varuord. Resultatet är därför de kampanjvaror som hittas av dessa sökningar, inte en garanterat komplett kampanjkatalog. Lägg till relevanta sökord på synksidan för större täckning. Som skydd gör sidan högst tolv API-anrop per synkning.

Den välkända äldre community-dokumentationen för `handla.api.ica.se` markerades som inaktuell efter ICA:s ändringar den 17 april 2024. Därför använder den här demon inte den gamla Basic Auth-/`AuthenticationTicket`-lösningen. Se [svendahlstrand/ica-api](https://github.com/svendahlstrand/ica-api) för historiken och en [beskrivning av den nuvarande publika butikskatalogen](https://apify.com/studio-amba/ica-scraper) för den response shape som importern tolererar.

ICA kan svara med exempelvis HTTP 403 beroende på nätverk, region, belastning eller framtida ändringar. Appen behåller då befintliga lokala data och skriver felet till `import_runs`. Det gör att CRUD-demon fortsätter fungera offline.

## Projektstruktur

```text
assets/              CSS, JavaScript och favicon
data/                SQLite-fil vid körning; blockerad av .htaccess
database/schema.sql  Normaliserat SQLite-schema
partials/            Gemensam sidheader och footer
action.php           Alla lokala create/update/delete-operationer
ica_client.php       ICA HTTP-klient, normalisering och upsert
index.php            Butiksval och läsning av erbjudanden
manage.php           CRUD-översikt
store_form.php       Skapa/uppdatera butik
deal_form.php        Skapa/uppdatera erbjudande
sync.php             Butikssökning, import och synkhistorik
```

## Säkerhetsanteckningar

- Alla databasvärden skrivs med PDO prepared statements.
- Alla HTML-värden kodas med `htmlspecialchars`.
- Alla skrivoperationer kräver POST och en sessionsbunden CSRF-token.
- SQLite-filen, konfigurationen och källfiler med anslutningslogik blockeras i `.htaccess`.
- ICA-URL:ernas värd är hårdkodad; användaren kan inte välja en godtycklig upstream-värd.
- Detta är fortfarande en demo utan användarkonton. Lägg autentisering och behörighetskontroll framför administrations- och synksidorna innan publik drift.

## Återställa demon

Ta bort `data/deals.sqlite` när webbservern är stoppad. Nästa anrop skapar databasen och demoposterna på nytt. Gör först en kopia om data ska bevaras.
