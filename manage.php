<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$stores = $db->query(
    'SELECT s.*, COUNT(d.id) AS deal_count,
            SUM(CASE WHEN d.source = \'ica_api\' THEN 1 ELSE 0 END) AS synced_count
     FROM stores s
     LEFT JOIN deals d ON d.store_id = s.id
     GROUP BY s.id
     ORDER BY s.name'
)->fetchAll();

$deals = $db->query(
    'SELECT d.*, s.name AS store_name
     FROM deals d
     JOIN stores s ON s.id = d.store_id
     ORDER BY d.updated_at DESC, d.id DESC
     LIMIT 100'
)->fetchAll();

$pageTitle = 'Hantera data';
require __DIR__ . '/partials/header.php';
?>

<section class="page-hero small">
    <div class="shell split-heading">
        <div>
            <p class="eyebrow">CRUD-kontrollpanel</p>
            <h1>Hantera butiker<br>och erbjudanden.</h1>
        </div>
        <div class="button-row">
            <a class="button light" href="<?= e(app_url('store_form.php')) ?>">+ Lägg till butik</a>
            <a class="button outline-light" href="<?= e(app_url('deal_form.php')) ?>">+ Lägg till erbjudande</a>
        </div>
    </div>
</section>

<section class="shell admin-section">
    <div class="section-heading">
        <div><p class="eyebrow dark">Butiker</p><h2><?= count($stores) ?> platser</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Butik</th><th>Plats</th><th>ICA-id</th><th>Erbjudanden</th><th><span class="sr-only">Åtgärder</span></th></tr></thead>
            <tbody>
            <?php foreach ($stores as $store): ?>
                <tr>
                    <td><strong><?= e($store['name']) ?></strong><small><?= e(store_format_label($store['store_format'])) ?></small></td>
                    <td><?= e($store['city']) ?><small><?= e(trim($store['street'] . ' ' . $store['postcode'])) ?></small></td>
                    <td><code><?= e($store['account_id'] ?: '—') ?></code></td>
                    <td><a href="<?= e(app_url('index.php?store_id=' . (int) $store['id'])) ?>"><?= (int) $store['deal_count'] ?> st</a></td>
                    <td class="row-actions">
                        <a href="<?= e(app_url('store_form.php?id=' . (int) $store['id'])) ?>">Redigera</a>
                        <form method="post" action="<?= e(app_url('action.php')) ?>" data-confirm="Ta bort butiken och alla dess erbjudanden?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="delete_store">
                            <input type="hidden" name="id" value="<?= (int) $store['id'] ?>">
                            <button class="link-button danger" type="submit">Ta bort</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>

<section class="shell admin-section last">
    <div class="section-heading">
        <div><p class="eyebrow dark">Senast ändrade</p><h2>Erbjudanden</h2></div>
    </div>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Produkt</th><th>Butik</th><th>Pris</th><th>Källa</th><th>Giltig till</th><th><span class="sr-only">Åtgärder</span></th></tr></thead>
            <tbody>
            <?php foreach ($deals as $deal): ?>
                <tr>
                    <td><strong><?= e($deal['product_name']) ?></strong><small><?= e($deal['promotion_text']) ?></small></td>
                    <td><?= e($deal['store_name']) ?></td>
                    <td><?= e($deal['price_text'] ?: money($deal['deal_price'] !== null ? (float) $deal['deal_price'] : null)) ?></td>
                    <td><span class="source-tag source-<?= e($deal['source']) ?>"><?= e(source_label($deal['source'])) ?></span></td>
                    <td><?= e($deal['valid_to'] ?: '—') ?></td>
                    <td class="row-actions">
                        <a href="<?= e(app_url('deal_form.php?id=' . (int) $deal['id'])) ?>">Redigera</a>
                        <form method="post" action="<?= e(app_url('action.php')) ?>" data-confirm="Ta bort erbjudandet?"><?= csrf_field() ?><input type="hidden" name="action" value="delete_deal"><input type="hidden" name="id" value="<?= (int) $deal['id'] ?>"><button class="link-button danger" type="submit">Ta bort</button></form>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$deals): ?><tr><td colspan="6" class="table-empty">Inga erbjudanden ännu.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
