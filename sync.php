<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/ica_client.php';

$client = new IcaClient();
$foundStores = [];
$lookupError = null;
$postcode = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post_string('action', 40);
    try {
        if ($action === 'discover_stores') {
            $postcode = post_string('postcode', 12);
            $foundStores = $client->findStores($postcode);
            if (!$foundStores) {
                $lookupError = 'Inga ICA-butiker hittades för postnumret.';
            }
        } elseif ($action === 'import_store') {
            $icaStoreId = post_string('ica_store_id', 40);
            $name = post_string('name', 120);
            if ($icaStoreId === '' || $name === '') {
                throw new InvalidArgumentException('Butiksuppgifterna är ofullständiga.');
            }
            $values = [
                'ica_store_id' => $icaStoreId,
                'account_id' => post_string('account_id', 40) ?: null,
                'name' => $name,
                'store_format' => post_string('store_format', 30) ?: 'other',
                'street' => post_string('street', 160),
                'postcode' => post_string('postcode', 12),
                'city' => post_string('city', 100),
                'latitude' => nullable_float('latitude'),
                'longitude' => nullable_float('longitude'),
            ];
            $existing = $db->prepare('SELECT id FROM stores WHERE ica_store_id = :ica_store_id');
            $existing->execute(['ica_store_id' => $icaStoreId]);
            $existingId = $existing->fetchColumn();
            if ($existingId) {
                $values['id'] = (int) $existingId;
                $statement = $db->prepare(
                    'UPDATE stores SET account_id = :account_id, name = :name, store_format = :store_format,
                     street = :street, postcode = :postcode, city = :city, latitude = :latitude,
                     longitude = :longitude, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $statement->execute($values);
                flash('success', 'Butiken fanns redan och uppdaterades från ICA.');
            } else {
                $statement = $db->prepare(
                    'INSERT INTO stores (ica_store_id, account_id, name, store_format, street, postcode, city, latitude, longitude)
                     VALUES (:ica_store_id, :account_id, :name, :store_format, :street, :postcode, :city, :latitude, :longitude)'
                );
                $statement->execute($values);
                flash('success', 'Butiken importerades från ICA.');
            }
            redirect('sync.php');
        } elseif ($action === 'sync_deals') {
            $storeId = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$storeId) {
                throw new InvalidArgumentException('Välj en butik att synka.');
            }
            $statement = $db->prepare('SELECT * FROM stores WHERE id = :id');
            $statement->execute(['id' => $storeId]);
            $store = $statement->fetch();
            if (!$store) {
                throw new InvalidArgumentException('Butiken finns inte.');
            }
            $termsRaw = post_string('terms', 500);
            $terms = array_values(array_filter(array_map('trim', explode(',', $termsRaw))));
            if (!$terms) {
                throw new InvalidArgumentException('Ange minst ett sökord.');
            }
            try {
                $products = $client->findPromotedProducts((string) $store['account_id'], $terms);
                $count = upsertIcaDeals($db, (int) $storeId, $products);
                $log = $db->prepare('INSERT INTO import_runs (store_id, status, imported_count, message) VALUES (:store_id, \'success\', :count, :message)');
                $log->execute(['store_id' => $storeId, 'count' => $count, 'message' => 'Sökord: ' . implode(', ', $terms)]);
                flash('success', $count . ' kampanjprodukter synkades från ICA.');
            } catch (Throwable $syncError) {
                $log = $db->prepare('INSERT INTO import_runs (store_id, status, imported_count, message) VALUES (:store_id, \'failed\', 0, :message)');
                $log->execute(['store_id' => $storeId, 'message' => substr($syncError->getMessage(), 0, 500)]);
                throw $syncError;
            }
            redirect('sync.php');
        }
    } catch (Throwable $error) {
        if ($action === 'discover_stores') {
            $lookupError = $error->getMessage();
        } else {
            flash('error', $error->getMessage());
            redirect('sync.php');
        }
    }
}

$stores = $db->query(
    'SELECT s.*, COUNT(d.id) AS deal_count, MAX(ir.created_at) AS last_sync
     FROM stores s
     LEFT JOIN deals d ON d.store_id = s.id
     LEFT JOIN import_runs ir ON ir.store_id = s.id AND ir.status = \'success\'
     GROUP BY s.id ORDER BY s.name'
)->fetchAll();
$runs = $db->query(
    'SELECT ir.*, s.name AS store_name FROM import_runs ir
     LEFT JOIN stores s ON s.id = ir.store_id ORDER BY ir.id DESC LIMIT 8'
)->fetchAll();

$pageTitle = 'Synka ICA-data';
require __DIR__ . '/partials/header.php';
?>

<section class="page-hero sync-hero">
    <div class="shell split-heading">
        <div><p class="eyebrow">Inofficiell integration</p><h1>Hämta lokala data<br>från ICA.</h1></div>
        <p class="hero-aside">Integrationen använder samma publika JSON-anrop som ICA:s butikssökning och webbshop. Den kan ändras eller blockeras utan förvarning.</p>
    </div>
</section>

<section class="shell sync-grid">
    <article class="panel">
        <div class="step-number">01</div>
        <p class="eyebrow dark">Butikssökning</p>
        <h2>Hitta via postnummer</h2>
        <p>Sök i ICA:s butiksregister och spara en plats i den lokala databasen.</p>
        <form method="post" action="<?= e(app_url('sync.php')) ?>" class="inline-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="discover_stores">
            <label class="field grow"><span>Postnummer</span><input type="text" name="postcode" value="<?= e($postcode) ?>" inputmode="numeric" pattern="[0-9 ]{5,6}" placeholder="111 22" required></label>
            <button class="button primary" type="submit">Sök butiker</button>
        </form>
        <?php if ($lookupError): ?><div class="inline-alert error"><?= e($lookupError) ?></div><?php endif; ?>
        <?php if ($foundStores): ?>
            <div class="found-stores">
                <?php foreach ($foundStores as $store): ?>
                    <div class="found-store">
                        <div><strong><?= e($store['name']) ?></strong><small><?= e($store['street'] . ', ' . $store['postcode'] . ' ' . $store['city']) ?></small></div>
                        <form method="post" action="<?= e(app_url('sync.php')) ?>">
                            <?= csrf_field() ?><input type="hidden" name="action" value="import_store">
                            <?php foreach ($store as $key => $value): ?><input type="hidden" name="<?= e($key) ?>" value="<?= e($value) ?>"><?php endforeach; ?>
                            <button class="button mini secondary" type="submit">Importera</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </article>

    <article class="panel featured">
        <div class="step-number">02</div>
        <p class="eyebrow dark">Erbjudanden</p>
        <h2>Synka kampanjvaror</h2>
        <p>ICA:s produkt-API är sökbaserat. Vi söker brett och sparar bara produkter som har kampanjinformation.</p>
        <form method="post" action="<?= e(app_url('sync.php')) ?>" class="stack-form">
            <?= csrf_field() ?><input type="hidden" name="action" value="sync_deals">
            <label class="field"><span>Butik</span><select name="store_id" required><option value="">Välj butik</option><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= !$store['account_id'] ? 'disabled' : '' ?>><?= e($store['name']) ?><?= !$store['account_id'] ? ' (saknar accountId)' : '' ?></option><?php endforeach; ?></select></label>
            <label class="field"><span>Sökord, separerade med komma</span><textarea name="terms" rows="3" maxlength="500"><?= e(DEFAULT_SYNC_TERMS) ?></textarea><small>Högst 12 sökord och ett API-anrop per ord.</small></label>
            <button class="button dark full" type="submit">Synka erbjudanden</button>
        </form>
    </article>
</section>

<section class="shell admin-section last">
    <div class="section-heading"><div><p class="eyebrow dark">Historik</p><h2>Senaste synkningar</h2></div></div>
    <div class="table-wrap"><table><thead><tr><th>Tid</th><th>Butik</th><th>Status</th><th>Antal</th><th>Information</th></tr></thead><tbody>
        <?php foreach ($runs as $run): ?><tr><td><?= e($run['created_at']) ?></td><td><?= e($run['store_name'] ?: 'Borttagen butik') ?></td><td><span class="source-tag <?= $run['status'] === 'success' ? 'source-ica_api' : 'status-error' ?>"><?= $run['status'] === 'success' ? 'Klar' : 'Misslyckad' ?></span></td><td><?= (int) $run['imported_count'] ?></td><td><?= e($run['message']) ?></td></tr><?php endforeach; ?>
        <?php if (!$runs): ?><tr><td colspan="5" class="table-empty">Ingen synkning har körts ännu.</td></tr><?php endif; ?>
    </tbody></table></div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
