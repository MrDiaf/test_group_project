import type { Deal, IcaStore, ImportRun, Store } from './types.js';

type JsonObject = Record<string, unknown>;

class ApiClient {
  private async request<T>(path: string, options: RequestInit = {}): Promise<T> {
    const response = await fetch(`/api${path}`, {
      ...options,
      headers: {
        ...(options.body ? { 'Content-Type': 'application/json' } : {}),
        'X-Requested-With': 'XMLHttpRequest',
        ...options.headers,
      },
    });
    if (!response.ok) {
      const body = await response.json().catch(() => ({ error: `HTTP ${response.status}` })) as { error?: string };
      throw new Error(body.error || `API-anropet misslyckades (HTTP ${response.status}).`);
    }
    if (response.status === 204) return undefined as T;
    return response.json() as Promise<T>;
  }

  stores = () => this.request<Store[]>('/stores');
  createStore = (payload: JsonObject) => this.request<Store>('/stores', { method: 'POST', body: JSON.stringify(payload) });
  updateStore = (id: number, payload: JsonObject) => this.request<Store>(`/stores/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
  deleteStore = (id: number) => this.request<void>(`/stores/${id}`, { method: 'DELETE' });

  deals = (params: URLSearchParams = new URLSearchParams()) => this.request<Deal[]>(`/deals?${params}`);
  createDeal = (payload: JsonObject) => this.request<Deal>('/deals', { method: 'POST', body: JSON.stringify(payload) });
  updateDeal = (id: number, payload: JsonObject) => this.request<Deal>(`/deals/${id}`, { method: 'PUT', body: JSON.stringify(payload) });
  deleteDeal = (id: number) => this.request<void>(`/deals/${id}`, { method: 'DELETE' });

  findIcaStores = (postcode: string) => this.request<IcaStore[]>(`/ica/stores?postcode=${encodeURIComponent(postcode)}`);
  importIcaStore = (store: IcaStore) => this.request<Store>('/ica/stores/import', { method: 'POST', body: JSON.stringify(store) });
  syncStore = (id: number, terms: string) => this.request<{ imported: number; store_id: number }>(`/stores/${id}/sync`, { method: 'POST', body: JSON.stringify({ terms }) });
  importRuns = () => this.request<ImportRun[]>('/import-runs');
}

export const api = new ApiClient();
