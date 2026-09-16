<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$store = [
    'id' => '', 'ica_store_id' => '', 'account_id' => '', 'name' => '', 'store_format' => 'supermarket',
    'street' => '', 'postcode' => '', 'city' => '', 'latitude' => '', 'longitude' => '',
];
if ($id) {
    $statement = $db->prepare('SELECT * FROM stores WHERE id = :id');
    $statement->execute(['id' => $id]);
    $found = $statement->fetch();
    if (!$found) {
        http_response_code(404);
        exit('Butiken hittades inte.');
    }
    $store = $found;
}

$pageTitle = $id ? 'Redigera butik' : 'Ny butik';
require __DIR__ . '/partials/header.php';
?>

<section class="form-page">
    <div class="shell form-shell">
        <a class="back-link" href="<?= e(app_url('manage.php')) ?>">← Tillbaka till hantering</a>
        <div class="form-card">
            <div class="form-intro">
                <p class="eyebrow dark"><?= $id ? 'Uppdatera' : 'Skapa' ?></p>
                <h1><?= $id ? 'Redigera butik' : 'Lägg till butik' ?></h1>
                <p>Butikens <strong>accountId</strong> används för att hämta rätt sortiment och kampanjpriser från ICA:s webbshop.</p>
            </div>
            <form method="post" action="<?= e(app_url('action.php')) ?>" class="data-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_store">
                <input type="hidden" name="id" value="<?= e($store['id']) ?>">

                <label class="field wide"><span>Butiksnamn *</span><input type="text" name="name" required maxlength="120" value="<?= e($store['name']) ?>" placeholder="Till exempel ICA Kvantum Centrum"></label>
                <label class="field"><span>Butiksformat</span><select name="store_format">
                    <?php foreach (['maxi' => 'Maxi', 'kvantum' => 'Kvantum', 'supermarket' => 'Supermarket', 'nara' => 'Nära', 'other' => 'Annat'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= $store['store_format'] === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label class="field"><span>Gatuadress</span><input type="text" name="street" maxlength="160" value="<?= e($store['street']) ?>"></label>
                <label class="field"><span>Postnummer</span><input type="text" name="postcode" maxlength="12" value="<?= e($store['postcode']) ?>" inputmode="numeric"></label>
                <label class="field"><span>Ort</span><input type="text" name="city" maxlength="100" value="<?= e($store['city']) ?>"></label>
                <label class="field"><span>ICA store id</span><input type="text" name="ica_store_id" maxlength="40" value="<?= e($store['ica_store_id']) ?>"><small>Fältet <code>id</code> från butikssökningen.</small></label>
                <label class="field"><span>ICA account id</span><input type="text" name="account_id" maxlength="40" value="<?= e($store['account_id']) ?>"><small>Fältet <code>accountId</code>; krävs för erbjudandesynk.</small></label>
                <label class="field"><span>Latitud</span><input type="number" step="any" name="latitude" value="<?= e($store['latitude']) ?>"></label>
                <label class="field"><span>Longitud</span><input type="number" step="any" name="longitude" value="<?= e($store['longitude']) ?>"></label>

                <div class="form-actions wide">
                    <a class="button ghost" href="<?= e(app_url('manage.php')) ?>">Avbryt</a>
                    <button class="button primary" type="submit"><?= $id ? 'Spara ändringar' : 'Skapa butik' ?></button>
                </div>
            </form>
        </div>
    </div>
</section>

<?php require __DIR__ . '/partials/footer.php'; ?>
