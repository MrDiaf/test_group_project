<?php

declare(strict_types=1);

const APP_NAME = 'Lokala fynd';
const DB_PATH = __DIR__ . '/data/deals.sqlite';

// Public, unofficial storefront endpoints used by ICA's web shop. They may
// change without notice because ICA does not publish a supported developer API.
const ICA_STORE_API = 'https://handla.ica.se/api/store/v1';
const ICA_PRODUCT_API_TEMPLATE = 'https://handlaprivatkund.ica.se/stores/%s/api/webproductpagews/v6/product-pages/search';

const DEFAULT_SYNC_TERMS = 'kaffe,mjölk,ost,bröd,frukt,grönsaker,kött,fisk,pasta';
