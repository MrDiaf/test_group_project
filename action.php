<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Endast POST är tillåtet.');
}

verify_csrf();
$action = post_string('action', 40);

try {
    switch ($action) {
        case 'save_store':
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $name = post_string('name', 120);
            if ($name === '') {
                throw new InvalidArgumentException('Butiksnamn måste anges.');
            }
            $allowedFormats = ['maxi', 'kvantum', 'supermarket', 'nara', 'other'];
            $format = post_string('store_format', 30);
            if (!in_array($format, $allowedFormats, true)) {
                $format = 'other';
            }
            $values = [
                'ica_store_id' => post_string('ica_store_id', 40) ?: null,
                'account_id' => post_string('account_id', 40) ?: null,
                'name' => $name,
                'store_format' => $format,
                'street' => post_string('street', 160),
                'postcode' => post_string('postcode', 12),
                'city' => post_string('city', 100),
                'latitude' => nullable_float('latitude'),
                'longitude' => nullable_float('longitude'),
            ];
            if ($id) {
                $values['id'] = $id;
                $statement = $db->prepare(
                    'UPDATE stores SET ica_store_id = :ica_store_id, account_id = :account_id, name = :name,
                     store_format = :store_format, street = :street, postcode = :postcode, city = :city,
                     latitude = :latitude, longitude = :longitude, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $statement->execute($values);
                flash('success', 'Butiken uppdaterades.');
            } else {
                $statement = $db->prepare(
                    'INSERT INTO stores (ica_store_id, account_id, name, store_format, street, postcode, city, latitude, longitude)
                     VALUES (:ica_store_id, :account_id, :name, :store_format, :street, :postcode, :city, :latitude, :longitude)'
                );
                $statement->execute($values);
                flash('success', 'Butiken lades till.');
            }
            redirect('manage.php');

        case 'delete_store':
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Ogiltigt butiks-id.');
            }
            $statement = $db->prepare('DELETE FROM stores WHERE id = :id');
            $statement->execute(['id' => $id]);
            flash('success', 'Butiken och dess erbjudanden togs bort.');
            redirect('manage.php');

        case 'save_deal':
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT) ?: null;
            $storeId = filter_var($_POST['store_id'] ?? null, FILTER_VALIDATE_INT);
            $productName = post_string('product_name', 180);
            if (!$storeId || $productName === '') {
                throw new InvalidArgumentException('Butik och produktnamn måste anges.');
            }
            $source = post_string('source', 20);
            if (!in_array($source, ['manual', 'ica_api', 'demo'], true)) {
                $source = 'manual';
            }
            $values = [
                'store_id' => $storeId,
                'external_id' => post_string('external_id', 180) ?: null,
                'product_name' => $productName,
                'brand' => post_string('brand', 100),
                'description' => post_string('description', 600),
                'deal_price' => nullable_float('deal_price'),
                'regular_price' => nullable_float('regular_price'),
                'price_text' => post_string('price_text', 80),
                'quantity_text' => post_string('quantity_text', 100),
                'promotion_text' => post_string('promotion_text', 200),
                'image_url' => filter_var(post_string('image_url', 1000), FILTER_VALIDATE_URL) ?: null,
                'product_url' => filter_var(post_string('product_url', 1000), FILTER_VALIDATE_URL) ?: null,
                'valid_from' => nullable_date('valid_from'),
                'valid_to' => nullable_date('valid_to'),
                'source' => $source,
            ];
            if ($values['valid_from'] && $values['valid_to'] && $values['valid_from'] > $values['valid_to']) {
                throw new InvalidArgumentException('Slutdatum kan inte vara före startdatum.');
            }
            if ($id) {
                $values['id'] = $id;
                $statement = $db->prepare(
                    'UPDATE deals SET store_id = :store_id, external_id = :external_id, product_name = :product_name,
                     brand = :brand, description = :description, deal_price = :deal_price, regular_price = :regular_price,
                     price_text = :price_text, quantity_text = :quantity_text, promotion_text = :promotion_text,
                     image_url = :image_url, product_url = :product_url, valid_from = :valid_from, valid_to = :valid_to,
                     source = :source, updated_at = CURRENT_TIMESTAMP WHERE id = :id'
                );
                $statement->execute($values);
                flash('success', 'Erbjudandet uppdaterades.');
            } else {
                $statement = $db->prepare(
                    'INSERT INTO deals (store_id, external_id, product_name, brand, description, deal_price, regular_price,
                     price_text, quantity_text, promotion_text, image_url, product_url, valid_from, valid_to, source)
                     VALUES (:store_id, :external_id, :product_name, :brand, :description, :deal_price, :regular_price,
                     :price_text, :quantity_text, :promotion_text, :image_url, :product_url, :valid_from, :valid_to, :source)'
                );
                $statement->execute($values);
                flash('success', 'Erbjudandet lades till.');
            }
            redirect('manage.php');

        case 'delete_deal':
            $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id) {
                throw new InvalidArgumentException('Ogiltigt erbjudande-id.');
            }
            $statement = $db->prepare('DELETE FROM deals WHERE id = :id');
            $statement->execute(['id' => $id]);
            flash('success', 'Erbjudandet togs bort.');
            redirect('manage.php');

        default:
            throw new InvalidArgumentException('Okänd åtgärd.');
    }
} catch (PDOException $error) {
    $message = str_contains($error->getMessage(), 'UNIQUE constraint failed')
        ? 'ICA-id:t används redan av en annan post.'
        : 'Databasen kunde inte uppdateras.';
    flash('error', $message);
    redirect('manage.php');
} catch (InvalidArgumentException $error) {
    flash('error', $error->getMessage());
    redirect('manage.php');
}
