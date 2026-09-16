<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$stores = $db->query('SELECT id, name FROM stores ORDER BY name')->fetchAll();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$requestedStoreId = filter_input(INPUT_GET, 'store_id', FILTER_VALIDATE_INT);
$deal = [
    'id' => '', 'store_id' => $requestedStoreId ?: ($stores[0]['id'] ?? ''), 'external_id' => '',
    'product_name' => '', 'brand' => '', 'description' => '', 'deal_price' => '', 'regular_price' => '',
    'price_text' => '', 'quantity_text' => '', 'promotion_text' => '', 'image_url' => '', 'product_url' => '',
    'valid_from' => date('Y-m-d'), 'valid_to' => date('Y-m-d', strtotime('+7 days')), 'source' => 'manual',
];
if ($id) {
    $statement = $db->prepare('SELECT * FROM deals WHERE id = :id');
    $statement->execute(['id' => $id]);
    $found = $statement->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Erbjudandet hittades inte.');
    }
    $deal = $found;
}

$pageTitle = $id ? 'Redigera erbjudande' : 'Nytt erbjudande';
require __DIR__ . '/partials/header.php';
?>

<section class="form-page">
    <div class="shell form-shell">
        <a class="back-link" href="<?= e(app_url('manage.php')) ?>">← Tillbaka till hantering</a>
        <div class="form-card">
            <div class="form-intro">
                <p class="eyebrow dark"><?= $id ? 'Uppdatera' : 'Skapa' ?></p>
                <h1><?= $id ? 'Redigera erbjudande' : 'Lägg till erbjudande' ?></h1>
                <p>Manuellt inlagda erbjudanden bevaras när ICA-data synkas om.</p>
            </div>
            <?php if (!$stores): ?>
                <div class="empty-state compact"><h3>En butik behövs först</h3><a class="button primary" href="<?= e(app_url('store_form.php')) ?>">Lägg till butik</a></div>
            <?php else: ?>
            <form method="post" action="<?= e(app_url('action.php')) ?>" class="data-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_deal">
                <input type="hidden" name="id" value="<?= e($deal['id']) ?>">
                <input type="hidden" name="source" value="<?= e($deal['source']) ?>">
                <input type="hidden" name="external_id" value="<?= e($deal['external_id']) ?>">

                <label class="field wide"><span>Butik *</span><select name="store_id" required><?php foreach ($stores as $store): ?><option value="<?= (int) $store['id'] ?>" <?= (int) $deal['store_id'] === (int) $store['id'] ? 'selected' : '' ?>><?= e($store['name']) ?></option><?php endforeach; ?></select></label>
                <label class="field wide"><span>Produktnamn *</span><input type="text" name="product_name" required maxlength="180" value="<?= e($deal['product_name']) ?>"></label>
                <label class="field"><span>Varumärke</span><input type="text" name="brand" maxlength="100" value="<?= e($deal['brand']) ?>"></label>
                <label class="field"><span>Storlek / mängd</span><input type="text" name="quantity_text" maxlength="100" value="<?= e($deal['quantity_text']) ?>" placeholder="500 g"></label>
                <label class="field"><span>Erbjudandepris</span><input type="number" step="0.01" min="0" name="deal_price" value="<?= e($deal['deal_price']) ?>" placeholder="39,90"></label>
                <label class="field"><span>Ordinarie pris</span><input type="number" step="0.01" min="0" name="regular_price" value="<?= e($deal['regular_price']) ?>"></label>
                <label class="field"><span>Priset som text</span><input type="text" name="price_text" maxlength="80" value="<?= e($deal['price_text']) ?>" placeholder="2 för 50 kr"></label>
                <label class="field"><span>Kampanjtext</span><input type="text" name="promotion_text" maxlength="200" value="<?= e($deal['promotion_text']) ?>" placeholder="Stammispris"></label>
                <label class="field"><span>Giltig från</span><input type="date" name="valid_from" value="<?= e($deal['valid_from']) ?>"></label>
                <label class="field"><span>Giltig till</span><input type="date" name="valid_to" value="<?= e($deal['valid_to']) ?>"></label>
                <label class="field wide"><span>Beskrivning</span><textarea name="description" maxlength="600" rows="3"><?= e($deal['description']) ?></textarea></label>
                <label class="field wide"><span>Bild-URL</span><input type="url" name="image_url" maxlength="1000" value="<?= e($deal['image_url']) ?>"></label>
                <label class="field wide"><span>Produkt-URL</span><input type="url" name="product_url" maxlength="1000" value="<?= e($deal['product_url']) ?>"></label>

                <div class="form-actions wide">
                    <a class="button ghost" href="<?= e(app_url('manage.php')) ?>">Avbryt</a>
                    <button class="button primary" type="submit"><?= $id ? 'Spara ändringar' : 'Skapa erbjudande' ?></button>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
