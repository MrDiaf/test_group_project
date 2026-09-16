<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$stores = $db->query(
    'SELECT s.*, COUNT(d.id) AS deal_count
     FROM stores s
     LEFT JOIN deals d ON d.store_id = s.id
     GROUP BY s.id
     ORDER BY s.city, s.name'
)->fetchAll();

$requestedStoreId = filter_input(INPUT_GET, 'store_id', FILTER_VALIDATE_INT);
$selectedStoreId = $requestedStoreId ? (int) $requestedStoreId : (int) ($stores[0]['id'] ?? 0);
$selectedStore = null;
foreach ($stores as $store) {
    if ((int) $store['id'] === $selectedStoreId) {
        $selectedStore = $store;
        break;
    }
}
if ($selectedStore === null && $stores) {
    $selectedStore = $stores[0];
    $selectedStoreId = (int) $selectedStore['id'];
}

$query = trim((string) ($_GET['q'] ?? ''));
$showExpired = ($_GET['show_expired'] ?? '') === '1';
$deals = [];
if ($selectedStore) {
    $sql = 'SELECT * FROM deals WHERE store_id = :store_id';
    $parameters = ['store_id' => $selectedStoreId];
    if ($query !== '') {
        $sql .= " AND (product_name LIKE :query ESCAPE '\\' OR brand LIKE :query ESCAPE '\\' OR promotion_text LIKE :query ESCAPE '\\')";
        $escapedQuery = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $query);
        $parameters['query'] = '%' . $escapedQuery . '%';
    }
    if (!$showExpired) {
        $sql .= " AND (valid_to IS NULL OR valid_to = '' OR valid_to >= date('now', 'localtime'))";
    }
    $sql .= " ORDER BY CASE WHEN valid_to IS NULL OR valid_to = '' THEN 1 ELSE 0 END, valid_to, product_name";
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $deals = $statement->fetchAll();
}

$pageTitle = 'Erbjudanden nära dig';
require __DIR__ . '/partials/header.php';
?>

<section class="hero">
    <div class="shell hero-grid">
        <div>
            <p class="eyebrow">Din butik. Dina priser.</p>
            <h1>Veckans bästa fynd,<br><em>butik för butik.</em></h1>
            <p class="hero-copy">Välj en ICA-butik och se alla sparade erbjudanden från just den platsen.</p>
        </div>
        <div class="hero-orbit" aria-hidden="true">
            <div class="price-sticker"><small>upp till</small><strong>30%</strong><span>demo-rabatt</span></div>
        </div>
    </div>
</section>

<section class="shell store-section" aria-labelledby="store-heading">
    <div class="section-heading">
        <div>
            <p class="eyebrow dark">Välj plats</p>
            <h2 id="store-heading">Vilken butik handlar du i?</h2>
        </div>
        <a class="text-link" href="<?= e(app_url('sync.php')) ?>">Hitta butik via postnummer →</a>
    </div>

    <?php if (!$stores): ?>
        <div class="empty-state">
            <h3>Inga butiker ännu</h3>
            <p>Lägg till din första butik för att börja samla lokala erbjudanden.</p>
            <a class="button primary" href="<?= e(app_url('store_form.php')) ?>">Lägg till butik</a>
        </div>
    <?php else: ?>
        <div class="store-tabs" role="list" aria-label="Butiker">
            <?php foreach ($stores as $store): ?>
                <a role="listitem" class="store-tab <?= (int) $store['id'] === $selectedStoreId ? 'selected' : '' ?>"
                   href="<?= e(app_url('index.php?store_id=' . (int) $store['id'])) ?>">
                    <span class="store-icon" aria-hidden="true">⌖</span>
                    <span>
                        <strong><?= e($store['name']) ?></strong>
                        <small><?= e(trim($store['city'] . ' · ' . $store['deal_count'] . ' erbjudanden', ' ·')) ?></small>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<?php if ($selectedStore): ?>
<section class="deals-band">
    <div class="shell">
        <div class="section-heading deal-heading">
            <div>
                <p class="eyebrow dark">Lokala erbjudanden</p>
                <h2><?= e($selectedStore['name']) ?></h2>
                <p class="muted"><?= e(trim($selectedStore['street'] . ', ' . $selectedStore['postcode'] . ' ' . $selectedStore['city'], ', ')) ?></p>
            </div>
            <a class="button secondary" href="<?= e(app_url('deal_form.php?store_id=' . $selectedStoreId)) ?>">+ Nytt erbjudande</a>
        </div>

        <form class="filter-bar" method="get" action="<?= e(app_url('index.php')) ?>">
            <input type="hidden" name="store_id" value="<?= $selectedStoreId ?>">
            <label class="search-field">
                <span class="sr-only">Sök erbjudanden</span>
                <span aria-hidden="true">⌕</span>
                <input type="search" name="q" value="<?= e($query) ?>" placeholder="Sök vara, märke eller kampanj">
            </label>
            <label class="check-field">
                <input type="checkbox" name="show_expired" value="1" <?= $showExpired ? 'checked' : '' ?>>
                Visa utgångna
            </label>
            <button class="button dark" type="submit">Filtrera</button>
        </form>

        <?php if (!$deals): ?>
            <div class="empty-state compact">
                <span class="empty-icon" aria-hidden="true">◇</span>
                <h3>Inga erbjudanden matchar</h3>
                <p><?= $query !== '' ? 'Prova ett annat sökord eller visa utgångna erbjudanden.' : 'Lägg till ett manuellt erbjudande eller synka från ICA.' ?></p>
            </div>
        <?php else: ?>
            <div class="deal-grid">
                <?php foreach ($deals as $deal): [$stateLabel, $stateClass] = deal_state($deal); ?>
                    <article class="deal-card">
                        <div class="deal-visual <?= empty($deal['image_url']) ? 'placeholder' : '' ?>">
                            <?php if (!empty($deal['image_url'])): ?>
                                <img src="<?= e($deal['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
                            <?php else: ?>
                                <span aria-hidden="true"><?= e(text_upper(text_slice($deal['product_name'], 0, 1))) ?></span>
                            <?php endif; ?>
                            <span class="status-pill <?= e($stateClass) ?>"><?= e($stateLabel) ?></span>
                        </div>
                        <div class="deal-body">
                            <p class="deal-meta"><?= e($deal['brand'] ?: source_label($deal['source'])) ?><?= $deal['quantity_text'] ? ' · ' . e($deal['quantity_text']) : '' ?></p>
                            <h3><?= e($deal['product_name']) ?></h3>
                            <?php if ($deal['promotion_text']): ?><p class="promotion"><?= e($deal['promotion_text']) ?></p><?php endif; ?>
                            <div class="price-row">
                                <strong><?= e($deal['price_text'] ?: money($deal['deal_price'] !== null ? (float) $deal['deal_price'] : null)) ?></strong>
                                <?php if ($deal['regular_price'] !== null): ?><del><?= e(money((float) $deal['regular_price'])) ?></del><?php endif; ?>
                            </div>
                            <?php if ($deal['valid_from'] || $deal['valid_to']): ?>
                                <p class="validity">Gäller <?= e($deal['valid_from'] ?: 'tills vidare') ?> – <?= e($deal['valid_to'] ?: 'tills vidare') ?></p>
                            <?php endif; ?>
                            <div class="card-actions">
                                <?php if ($deal['product_url']): ?><a href="<?= e($deal['product_url']) ?>" target="_blank" rel="noopener noreferrer">Visa hos ICA ↗</a><?php else: ?><span><?= e(source_label($deal['source'])) ?></span><?php endif; ?>
                                <a href="<?= e(app_url('deal_form.php?id=' . (int) $deal['id'])) ?>">Redigera</a>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/partials/footer.php'; ?>
