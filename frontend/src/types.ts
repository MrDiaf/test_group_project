export interface Store {
  id: number;
  ica_store_id: string | null;
  account_id: string | null;
  name: string;
  store_format: string;
  street: string;
  postcode: string;
  city: string;
  latitude: number | null;
  longitude: number | null;
  deal_count: number;
  last_sync?: string | null;
}

export interface Deal {
  id: number;
  store_id: number;
  store_name: string;
  external_id: string | null;
  product_name: string;
  brand: string;
  description: string;
  deal_price: number | null;
  regular_price: number | null;
  price_text: string;
  quantity_text: string;
  promotion_text: string;
  category: string;
  country_of_origin: string;
  image_url: string | null;
  product_url: string | null;
  valid_from: string | null;
  valid_to: string | null;
  source: 'manual' | 'ica_api' | 'demo';
  synced_at: string | null;
  created_at: string;
  updated_at: string;
}

export interface IcaStore {
  ica_store_id: string;
  account_id: string;
  name: string;
  store_format: string;
  street: string;
  postcode: string;
  city: string;
  latitude: number | null;
  longitude: number | null;
}

export interface ImportRun {
  id: number;
  store_id: number | null;
  store_name: string | null;
  status: 'success' | 'failed';
  imported_count: number;
  message: string;
  created_at: string;
}

export type ViewName = 'deals' | 'manage' | 'sync';
