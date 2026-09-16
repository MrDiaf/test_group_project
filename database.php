<?php

declare(strict_types=1);

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $directory = dirname(DB_PATH);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the data directory.');
        }

        self::$connection = new PDO('sqlite:' . DB_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::$connection->exec('PRAGMA foreign_keys = ON');
        self::$connection->exec('PRAGMA busy_timeout = 5000');
        self::migrate(self::$connection);
        self::seed(self::$connection);

        return self::$connection;
    }

    private static function migrate(PDO $pdo): void
    {
        $schema = file_get_contents(__DIR__ . '/database/schema.sql');
        if ($schema === false) {
            throw new RuntimeException('Could not read database/schema.sql.');
        }
        $pdo->exec($schema);
    }

    private static function seed(PDO $pdo): void
    {
        $count = (int) $pdo->query('SELECT COUNT(*) FROM stores')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $pdo->beginTransaction();
        try {
            $storeStatement = $pdo->prepare(
                'INSERT INTO stores (ica_store_id, account_id, name, store_format, street, postcode, city, latitude, longitude)
                 VALUES (:ica_store_id, :account_id, :name, :store_format, :street, :postcode, :city, :latitude, :longitude)'
            );
            $dealStatement = $pdo->prepare(
                'INSERT INTO deals
                    (store_id, external_id, product_name, brand, description, deal_price, regular_price, price_text,
                     quantity_text, promotion_text, valid_from, valid_to, source)
                 VALUES
                    (:store_id, :external_id, :product_name, :brand, :description, :deal_price, :regular_price, :price_text,
                     :quantity_text, :promotion_text, :valid_from, :valid_to, :source)'
            );

            $stores = [
                [
                    'ica_store_id' => '16628', 'account_id' => '1004599', 'name' => 'ICA Kvantum Kungsholmen',
                    'store_format' => 'kvantum', 'street' => 'Fleminggatan 16', 'postcode' => '112 26',
                    'city' => 'Stockholm', 'latitude' => 59.33316, 'longitude' => 18.04494,
                ],
                [
                    'ica_store_id' => '13026', 'account_id' => '1004134', 'name' => 'ICA Supermarket Sabbatsberg',
                    'store_format' => 'supermarket', 'street' => 'Dalagatan 9 N', 'postcode' => '113 61',
                    'city' => 'Stockholm', 'latitude' => 59.33989, 'longitude' => 18.04712,
                ],
                [
                    'ica_store_id' => '13164', 'account_id' => '1003418', 'name' => 'Maxi ICA Stormarknad Lindhagen',
                    'store_format' => 'maxi', 'street' => 'Lindhagensgatan 118', 'postcode' => '112 51',
                    'city' => 'Stockholm', 'latitude' => 59.33714, 'longitude' => 18.00974,
                ],
            ];

            $seedDeals = [
                ['Kaffe mellanrost', 'ICA', '500 g', 49.90, 64.90, 'Stammispris', 'Demoerbjudande – ersätt med en ICA-synk.'],
                ['Svenska äpplen', '', '1 kg', 24.90, 34.90, 'Veckans frukt', 'Demoerbjudande – lokalt sortiment kan variera.'],
                ['Hushållsost', 'ICA', 'ca 1 kg', 89.00, 109.00, '89 kr/kg', 'Demoerbjudande för att visa gränssnittet.'],
            ];

            foreach ($stores as $storeIndex => $store) {
                $storeStatement->execute($store);
                $storeId = (int) $pdo->lastInsertId();
                foreach ($seedDeals as $dealIndex => $deal) {
                    if (($dealIndex + $storeIndex) % 3 === 2 && $storeIndex !== 2) {
                        continue;
                    }
                    $dealStatement->execute([
                        'store_id' => $storeId,
                        'external_id' => 'demo-' . ($storeIndex + 1) . '-' . ($dealIndex + 1),
                        'product_name' => $deal[0],
                        'brand' => $deal[1],
                        'quantity_text' => $deal[2],
                        'deal_price' => $deal[3],
                        'regular_price' => $deal[4],
                        'price_text' => number_format($deal[3], 2, ',', ' ') . ' kr',
                        'promotion_text' => $deal[5],
                        'description' => $deal[6],
                        'valid_from' => date('Y-m-d', strtotime('-2 days')),
                        'valid_to' => date('Y-m-d', strtotime('+5 days')),
                        'source' => 'demo',
                    ]);
                }
            }
            $pdo->commit();
        } catch (Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}
