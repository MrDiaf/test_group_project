import { api } from './api.js';
import type { Deal, IcaStore, ImportRun, Store, ViewName } from './types.js';

function mustElement<T extends Element>(selector: string): T {
  const element = document.querySelector<T>(selector);
  if (!element) throw new Error(`Elementet ${selector} saknas.`);
  return element;
}

const root = mustElement<HTMLElement>('#app');
const modalRoot = mustElement<HTMLElement>('#modal-root');
const toastRegion = mustElement<HTMLElement>('#toast-region');

const DEFAULT_TERMS = 'kaffe,mjölk,ost,bröd,frukt,grönsaker,kött,fisk,pasta';
let stores: Store[] = [];
let deals: Deal[] = [];
let selectedStoreId = Number(localStorage.getItem('selectedStoreId')) || 0;

const escapeHtml = (value: unknown): string => String(value ?? '')
  .replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;').replaceAll("'", '&#039;');

const money = (value: number | null): string => value === null
  ? ''
  : new Intl.NumberFormat('sv-SE', { style: 'currency', currency: 'SEK', maximumFractionDigits: value % 1 ? 2 : 0 }).format(value);

const errorMessage = (error: unknown): string => error instanceof Error ? error.message : 'Något gick fel.';

function currentView(): ViewName {
  const value = location.hash.replace('#', '');
  return value === 'manage' || value === 'sync' ? value : 'deals';
}

function setActiveNav(view: ViewName): void {
  document.querySelectorAll<HTMLElement>('[data-nav]').forEach((item) => {
    item.classList.toggle('active', item.dataset.nav === view);
  });
}

function toast(message: string, type: 'success' | 'error' = 'success'): void {
  const element = document.createElement('div');
  element.className = `toast ${type}`;
  element.textContent = message;
  toastRegion.append(element);
  window.setTimeout(() => element.remove(), 4200);
}

function loading(label = 'Laddar…'): void {
  root.innerHTML = `<div class="loading-screen"><span></span><p>${escapeHtml(label)}</p></div>`;
}

async function refreshStores(): Promise<void> {
  stores = await api.stores();
  if (!stores.some((store) => store.id === selectedStoreId)) selectedStoreId = stores[0]?.id ?? 0;
  if (selectedStoreId) localStorage.setItem('selectedStoreId', String(selectedStoreId));
}

async function render(): Promise<void> {
  const view = currentView();
  setActiveNav(view);
  loading();
  try {
    await refreshStores();
    if (view === 'manage') await renderManage();
    else if (view === 'sync') await renderSync();
    else await renderDeals();
  } catch (error) {
    root.innerHTML = `<section class="shell fatal"><p class="eyebrow dark">API-fel</p><h1>Kunde inte ladda appen</h1><p>${escapeHtml(errorMessage(error))}</p><button class="button primary" data-action="retry">Försök igen</button></section>`;
  }
}

function sourceLabel(source: Deal['source']): string {
  return source === 'ica_api' ? 'ICA-synk' : source === 'demo' ? 'Demodata' : 'Manuell';
}

function dealState(deal: Deal): { label: string; className: string } {
  const today = new Date().toISOString().slice(0, 10);
  if (deal.valid_from && deal.valid_from > today) return { label: 'Kommande', className: 'upcoming' };
  if (deal.valid_to && deal.valid_to < today) return { label: 'Utgånget', className: 'expired' };
  return { label: 'Aktuellt', className: 'active' };
}

function storeTabs(): string {
  return stores.map((store) => `
    <button class="store-tab ${store.id === selectedStoreId ? 'selected' : ''}" data-action="select-store" data-id="${store.id}">
      <span class="store-icon">⌖</span><span><strong>${escapeHtml(store.name)}</strong><small>${escapeHtml(store.city)} · ${store.deal_count} erbjudanden</small></span>
    </button>`).join('');
}

function dealCard(deal: Deal): string {
  const state = dealState(deal);
  const visual = deal.image_url
    ? `<img src="${escapeHtml(deal.image_url)}" alt="" loading="lazy" referrerpolicy="no-referrer">`
    : `<span class="product-letter">${escapeHtml(deal.product_name.slice(0, 1).toUpperCase())}</span>`;
  return `<article class="deal-card">
    <div class="deal-visual ${deal.image_url ? '' : 'placeholder'}">${visual}<span class="status-pill ${state.className}">${state.label}</span></div>
    <div class="deal-body">
      <p class="deal-meta">${escapeHtml(deal.brand || sourceLabel(deal.source))}${deal.quantity_text ? ` · ${escapeHtml(deal.quantity_text)}` : ''}</p>
      <h3>${escapeHtml(deal.product_name)}</h3>
      <p class="promotion">${escapeHtml(deal.promotion_text || deal.description)}</p>
      <div class="price-row"><strong>${escapeHtml(deal.price_text || money(deal.deal_price))}</strong>${deal.regular_price !== null ? `<del>${escapeHtml(money(deal.regular_price))}</del>` : ''}</div>
      ${deal.category ? `<p class="category">${escapeHtml(deal.category)}${deal.country_of_origin ? ` · ${escapeHtml(deal.country_of_origin)}` : ''}</p>` : ''}
      <div class="card-actions">${deal.product_url ? `<a href="${escapeHtml(deal.product_url)}" target="_blank" rel="noopener">Visa hos ICA ↗</a>` : `<span>${sourceLabel(deal.source)}</span>`}<button data-action="edit-deal" data-id="${deal.id}">Redigera</button></div>
    </div>
  </article>`;
}

async function renderDeals(query = '', includeExpired = false): Promise<void> {
  const params = new URLSearchParams();
  if (selectedStoreId) params.set('store_id', String(selectedStoreId));
  if (query) params.set('q', query);
  if (includeExpired) params.set('include_expired', 'true');
  deals = selectedStoreId ? await api.deals(params) : [];
  const store = stores.find((item) => item.id === selectedStoreId);
  root.innerHTML = `
    <section class="hero"><div class="shell hero-grid"><div><p class="eyebrow">Din butik. Dina priser.</p><h1>Veckans bästa fynd,<br><em>butik för butik.</em></h1><p class="hero-copy">Välj en ICA-butik och se kampanjer från just den platsen.</p></div><div class="hero-orbit"><div class="price-sticker"><small>lokala</small><strong>%</strong><span>ICA-fynd</span></div></div></div></section>
    <section class="shell store-section"><div class="section-heading"><div><p class="eyebrow dark">Välj plats</p><h2>Vilken butik handlar du i?</h2></div><a class="text-link" href="#sync">Hitta butik via postnummer →</a></div>
      ${stores.length ? `<div class="store-tabs">${storeTabs()}</div>` : `<div class="empty-state"><h3>Inga butiker ännu</h3><button class="button primary" data-action="new-store">Lägg till butik</button></div>`}
    </section>
    ${store ? `<section class="deals-band"><div class="shell"><div class="section-heading"><div><p class="eyebrow dark">Lokala erbjudanden</p><h2>${escapeHtml(store.name)}</h2><p class="muted">${escapeHtml([store.street, store.postcode, store.city].filter(Boolean).join(', '))}</p></div><button class="button secondary" data-action="new-deal">+ Nytt erbjudande</button></div>
      <form id="filter-form" class="filter-bar"><label class="search-field"><span>⌕</span><input type="search" name="q" value="${escapeHtml(query)}" placeholder="Sök vara, märke eller kampanj"></label><label class="check-field"><input type="checkbox" name="include_expired" ${includeExpired ? 'checked' : ''}> Visa utgångna</label><button class="button dark">Filtrera</button></form>
      ${deals.length ? `<div class="deal-grid">${deals.map(dealCard).join('')}</div>` : `<div class="empty-state compact"><h3>Inga erbjudanden matchar</h3><p>Lägg till ett manuellt erbjudande eller synka från ICA.</p></div>`}
    </div></section>` : ''}`;

  const filterForm = document.querySelector<HTMLFormElement>('#filter-form');
  filterForm?.addEventListener('submit', (event) => {
    event.preventDefault();
    const form = new FormData(filterForm);
    void renderDeals(String(form.get('q') || ''), form.has('include_expired'));
  });
}

async function renderManage(): Promise<void> {
  const params = new URLSearchParams({ include_expired: 'true' });
  deals = await api.deals(params);
  root.innerHTML = `
    <section class="page-hero"><div class="shell split-heading"><div><p class="eyebrow">REST CRUD</p><h1>Hantera butiker<br>och erbjudanden.</h1></div><div class="button-row"><button class="button light" data-action="new-store">+ Lägg till butik</button><button class="button outline-light" data-action="new-deal">+ Lägg till erbjudande</button></div></div></section>
    <section class="shell admin-section"><div class="section-heading"><div><p class="eyebrow dark">Butiker</p><h2>${stores.length} platser</h2></div></div><div class="table-wrap"><table><thead><tr><th>Butik</th><th>Plats</th><th>Account ID</th><th>Erbjudanden</th><th></th></tr></thead><tbody>
      ${stores.map((store) => `<tr><td><strong>${escapeHtml(store.name)}</strong><small>${escapeHtml(store.store_format)}</small></td><td>${escapeHtml(store.city)}<small>${escapeHtml(`${store.street} ${store.postcode}`)}</small></td><td><code>${escapeHtml(store.account_id || '—')}</code></td><td>${store.deal_count} st</td><td class="row-actions"><button data-action="edit-store" data-id="${store.id}">Redigera</button><button class="danger" data-action="delete-store" data-id="${store.id}">Ta bort</button></td></tr>`).join('') || '<tr><td colspan="5" class="table-empty">Inga butiker.</td></tr>'}
    </tbody></table></div></section>
    <section class="shell admin-section last"><div class="section-heading"><div><p class="eyebrow dark">Alla poster</p><h2>Erbjudanden</h2></div></div><div class="table-wrap"><table><thead><tr><th>Produkt</th><th>Butik</th><th>Pris</th><th>Källa</th><th>Giltig till</th><th></th></tr></thead><tbody>
      ${deals.map((deal) => `<tr><td><strong>${escapeHtml(deal.product_name)}</strong><small>${escapeHtml(deal.promotion_text)}</small></td><td>${escapeHtml(deal.store_name)}</td><td>${escapeHtml(deal.price_text || money(deal.deal_price))}</td><td><span class="source-tag source-${deal.source}">${sourceLabel(deal.source)}</span></td><td>${escapeHtml(deal.valid_to || '—')}</td><td class="row-actions"><button data-action="edit-deal" data-id="${deal.id}">Redigera</button><button class="danger" data-action="delete-deal" data-id="${deal.id}">Ta bort</button></td></tr>`).join('') || '<tr><td colspan="6" class="table-empty">Inga erbjudanden.</td></tr>'}
    </tbody></table></div></section>`;
}

async function renderSync(found: IcaStore[] = [], runs?: ImportRun[]): Promise<void> {
  const history = runs ?? await api.importRuns();
  root.innerHTML = `
    <section class="page-hero sync-hero"><div class="shell split-heading"><div><p class="eyebrow">Live ICA-integration</p><h1>Hämta lokala data<br>från ICA.</h1></div><p class="hero-aside">Python-backendens session skickar samma origin-, referer- och CSRF-information som ICA:s webbshop kräver.</p></div></section>
    <section class="shell sync-grid"><article class="panel"><div class="step-number">01</div><p class="eyebrow dark">Butikssökning</p><h2>Hitta via postnummer</h2><p>Sök direkt i ICA:s butikstjänst och importera en plats.</p><form id="postcode-form" class="inline-form"><label class="field grow"><span>Postnummer</span><input name="postcode" inputmode="numeric" pattern="[0-9 ]{5,6}" placeholder="111 22" required></label><button class="button primary">Sök butiker</button></form>
      <div class="found-stores">${found.map((store, index) => `<div class="found-store"><div><strong>${escapeHtml(store.name)}</strong><small>${escapeHtml(`${store.street}, ${store.postcode} ${store.city}`)}</small></div><button class="button mini secondary" data-action="import-store" data-index="${index}">Importera</button></div>`).join('')}</div></article>
      <article class="panel featured"><div class="step-number">02</div><p class="eyebrow dark">Erbjudanden</p><h2>Synka kampanjvaror</h2><p>Hämtar butikens aktuella produktdata och sparar endast varor med erbjudande eller sänkt pris.</p><form id="sync-form" class="stack-form"><label class="field"><span>Butik</span><select name="store_id" required><option value="">Välj butik</option>${stores.map((store) => `<option value="${store.id}" ${store.account_id ? '' : 'disabled'}>${escapeHtml(store.name)}${store.account_id ? '' : ' (saknar accountId)'}</option>`).join('')}</select></label><label class="field"><span>Sökord, separerade med komma</span><textarea name="terms" rows="3">${DEFAULT_TERMS}</textarea><small>Högst tolv sökningar per synkning.</small></label><button class="button dark full">Synka erbjudanden</button></form></article></section>
    <section class="shell admin-section last"><div class="section-heading"><div><p class="eyebrow dark">Historik</p><h2>Senaste synkningar</h2></div></div><div class="table-wrap"><table><thead><tr><th>Tid</th><th>Butik</th><th>Status</th><th>Antal</th><th>Information</th></tr></thead><tbody>${history.map((run) => `<tr><td>${escapeHtml(run.created_at)}</td><td>${escapeHtml(run.store_name || 'Borttagen butik')}</td><td><span class="source-tag ${run.status === 'success' ? 'source-ica_api' : 'status-error'}">${run.status === 'success' ? 'Klar' : 'Misslyckad'}</span></td><td>${run.imported_count}</td><td>${escapeHtml(run.message)}</td></tr>`).join('') || '<tr><td colspan="5" class="table-empty">Ingen synkning körd.</td></tr>'}</tbody></table></div></section>`;

  const postcodeForm = document.querySelector<HTMLFormElement>('#postcode-form');
  postcodeForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const button = postcodeForm.querySelector<HTMLButtonElement>('button');
    if (button) button.disabled = true;
    try {
      const result = await api.findIcaStores(String(new FormData(postcodeForm).get('postcode') || ''));
      await renderSync(result, history);
    } catch (error) { toast(errorMessage(error), 'error'); if (button) button.disabled = false; }
  });
  bindImportButtons(found);
  const syncForm = document.querySelector<HTMLFormElement>('#sync-form');
  syncForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const data = new FormData(syncForm);
    const storeId = Number(data.get('store_id'));
    const button = syncForm.querySelector<HTMLButtonElement>('button');
    if (button) { button.disabled = true; button.textContent = 'Synkar…'; }
    try {
      const result = await api.syncStore(storeId, String(data.get('terms') || ''));
      toast(`${result.imported} ICA-erbjudanden synkades.`);
      await render();
    } catch (error) { toast(errorMessage(error), 'error'); if (button) { button.disabled = false; button.textContent = 'Synka erbjudanden'; } }
  });
}

function bindImportButtons(found: IcaStore[]): void {
  document.querySelectorAll<HTMLButtonElement>('[data-action="import-store"]').forEach((button) => {
    button.addEventListener('click', async () => {
      const store = found[Number(button.dataset.index)];
      if (!store) return;
      button.disabled = true;
      try { await api.importIcaStore(store); toast(`${store.name} importerades.`); await render(); }
      catch (error) { toast(errorMessage(error), 'error'); button.disabled = false; }
    });
  });
}

function showModal(title: string, body: string): HTMLFormElement {
  modalRoot.innerHTML = `<div class="modal-backdrop" data-action="close-modal"><section class="modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}"><button class="modal-close" type="button" data-action="close-modal" aria-label="Stäng">×</button><p class="eyebrow dark">CRUD-formulär</p><h2>${escapeHtml(title)}</h2>${body}</section></div>`;
  const form = modalRoot.querySelector<HTMLFormElement>('form');
  if (!form) throw new Error('Formuläret kunde inte öppnas.');
  form.querySelector<HTMLElement>('input, select, textarea')?.focus();
  return form;
}

function storeModal(store?: Store): void {
  const form = showModal(store ? 'Redigera butik' : 'Lägg till butik', `<form class="data-form">
    <label class="field wide"><span>Butiksnamn *</span><input name="name" required maxlength="120" value="${escapeHtml(store?.name)}"></label>
    <label class="field"><span>Format</span><select name="store_format">${['maxi','kvantum','supermarket','nara','other'].map((value) => `<option ${store?.store_format === value ? 'selected' : ''}>${value}</option>`).join('')}</select></label>
    <label class="field"><span>Ort</span><input name="city" maxlength="100" value="${escapeHtml(store?.city)}"></label>
    <label class="field"><span>Gatuadress</span><input name="street" maxlength="160" value="${escapeHtml(store?.street)}"></label>
    <label class="field"><span>Postnummer</span><input name="postcode" maxlength="12" value="${escapeHtml(store?.postcode)}"></label>
    <label class="field"><span>ICA store id</span><input name="ica_store_id" maxlength="40" value="${escapeHtml(store?.ica_store_id)}"></label>
    <label class="field"><span>ICA account id</span><input name="account_id" maxlength="40" value="${escapeHtml(store?.account_id)}"></label>
    <label class="field"><span>Latitud</span><input name="latitude" type="number" step="any" value="${escapeHtml(store?.latitude)}"></label>
    <label class="field"><span>Longitud</span><input name="longitude" type="number" step="any" value="${escapeHtml(store?.longitude)}"></label>
    <div class="form-actions wide"><button type="button" class="button ghost" data-action="close-modal">Avbryt</button><button class="button primary">Spara butik</button></div></form>`);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(form));
    try { store ? await api.updateStore(store.id, payload) : await api.createStore(payload); closeModal(); toast('Butiken sparades.'); await render(); }
    catch (error) { toast(errorMessage(error), 'error'); }
  });
}

function dealModal(deal?: Deal): void {
  if (!stores.length) { toast('Lägg till en butik först.', 'error'); return; }
  const chosenStore = deal?.store_id ?? selectedStoreId ?? stores[0].id;
  const form = showModal(deal ? 'Redigera erbjudande' : 'Lägg till erbjudande', `<form class="data-form">
    <input type="hidden" name="source" value="${escapeHtml(deal?.source || 'manual')}"><input type="hidden" name="external_id" value="${escapeHtml(deal?.external_id)}">
    <label class="field wide"><span>Butik *</span><select name="store_id" required>${stores.map((store) => `<option value="${store.id}" ${store.id === chosenStore ? 'selected' : ''}>${escapeHtml(store.name)}</option>`).join('')}</select></label>
    <label class="field wide"><span>Produktnamn *</span><input name="product_name" required maxlength="180" value="${escapeHtml(deal?.product_name)}"></label>
    <label class="field"><span>Varumärke</span><input name="brand" maxlength="100" value="${escapeHtml(deal?.brand)}"></label>
    <label class="field"><span>Storlek</span><input name="quantity_text" maxlength="100" value="${escapeHtml(deal?.quantity_text)}"></label>
    <label class="field"><span>Erbjudandepris</span><input name="deal_price" type="number" min="0" step="0.01" value="${escapeHtml(deal?.deal_price)}"></label>
    <label class="field"><span>Ordinarie pris</span><input name="regular_price" type="number" min="0" step="0.01" value="${escapeHtml(deal?.regular_price)}"></label>
    <label class="field"><span>Priset som text</span><input name="price_text" maxlength="80" value="${escapeHtml(deal?.price_text)}"></label>
    <label class="field"><span>Kampanjtext</span><input name="promotion_text" maxlength="250" value="${escapeHtml(deal?.promotion_text)}"></label>
    <label class="field"><span>Kategori</span><input name="category" maxlength="120" value="${escapeHtml(deal?.category)}"></label>
    <label class="field"><span>Ursprungsland</span><input name="country_of_origin" maxlength="100" value="${escapeHtml(deal?.country_of_origin)}"></label>
    <label class="field"><span>Giltig från</span><input name="valid_from" type="date" value="${escapeHtml(deal?.valid_from)}"></label>
    <label class="field"><span>Giltig till</span><input name="valid_to" type="date" value="${escapeHtml(deal?.valid_to)}"></label>
    <label class="field wide"><span>Beskrivning</span><textarea name="description" maxlength="600">${escapeHtml(deal?.description)}</textarea></label>
    <label class="field wide"><span>Bild-URL</span><input name="image_url" type="url" value="${escapeHtml(deal?.image_url)}"></label>
    <label class="field wide"><span>Produkt-URL</span><input name="product_url" type="url" value="${escapeHtml(deal?.product_url)}"></label>
    <div class="form-actions wide"><button type="button" class="button ghost" data-action="close-modal">Avbryt</button><button class="button primary">Spara erbjudande</button></div></form>`);
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = Object.fromEntries(new FormData(form));
    try { deal ? await api.updateDeal(deal.id, payload) : await api.createDeal(payload); closeModal(); toast('Erbjudandet sparades.'); await render(); }
    catch (error) { toast(errorMessage(error), 'error'); }
  });
}

function closeModal(): void { modalRoot.innerHTML = ''; }

root.addEventListener('click', async (event) => {
  const button = (event.target as HTMLElement).closest<HTMLElement>('[data-action]');
  if (!button) return;
  const action = button.dataset.action;
  const id = Number(button.dataset.id);
  if (action === 'retry') await render();
  else if (action === 'select-store') { selectedStoreId = id; localStorage.setItem('selectedStoreId', String(id)); await renderDeals(); }
  else if (action === 'new-store') storeModal();
  else if (action === 'edit-store') storeModal(stores.find((store) => store.id === id));
  else if (action === 'new-deal') dealModal();
  else if (action === 'edit-deal') dealModal(deals.find((deal) => deal.id === id));
  else if (action === 'delete-store' && confirm('Ta bort butiken och alla dess erbjudanden?')) { try { await api.deleteStore(id); toast('Butiken togs bort.'); await render(); } catch (error) { toast(errorMessage(error), 'error'); } }
  else if (action === 'delete-deal' && confirm('Ta bort erbjudandet?')) { try { await api.deleteDeal(id); toast('Erbjudandet togs bort.'); await render(); } catch (error) { toast(errorMessage(error), 'error'); } }
});

modalRoot.addEventListener('click', (event) => {
  const target = event.target as HTMLElement;
  if (target.dataset.action === 'close-modal') closeModal();
});

window.addEventListener('hashchange', () => void render());
if (!location.hash) history.replaceState(null, '', '#deals');
void render();
