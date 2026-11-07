const $ = id => document.getElementById(id);
const status = (msg, err = false) => { $('status').textContent = msg; $('status').className = err ? 'error' : ''; };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

let csrf = '', user = null, favs = new Map();
async function api(params, post = false) {
  const r = post
    ? await fetch('api.php?action=' + params.action, { method: 'POST', body: new URLSearchParams({ ...params, csrf }) })
    : await fetch('api.php?' + new URLSearchParams(params));
  const d = await r.json();
  if (!d.ok) throw new Error(d.error || 'Fehler');
  return d;
}

let map, layer, stations = [];
function showMap(lat, lng, stations) {
  $('map').hidden = false;
  if (!map) {
    map = L.map('map');
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '© OpenStreetMap' }).addTo(map);
    layer = L.layerGroup().addTo(map);
  }
  layer.clearLayers();
  const pts = [[lat, lng]];
  L.marker([lat, lng], { icon: L.divIcon({ className: '', html: '<div class="pin me"></div>', iconSize: [14, 14] }) }).addTo(layer);
  const markers = stations.map((s, i) => {
    pts.push([s.lat, s.lng]);
    const m = L.marker([s.lat, s.lng], { icon: L.divIcon({ className: '', html: `<div class="pin${i ? '' : ' best'}">${s.price.toFixed(2)}</div>`, iconSize: [46, 22], iconAnchor: [23, 11] }) })
      .bindPopup(`<b>${esc(s.brand || s.name)}</b><br>${esc(s.street)} ${esc(s.houseNumber)}<br>${s.price.toFixed(3)} €`).addTo(layer);
    return m;
  });
  map.invalidateSize();
  map.fitBounds(pts, { padding: [30, 30], maxZoom: 15 });
  return markers;
}

async function search(lat, lng) {
  status('Suche Tankstellen …');
  $('results').innerHTML = '';
  try {
    const d = await api({ action: 'search', lat, lng, rad: $('rad').value, type: $('type').value, sort: $('sort').value });
    if (!d.stations.length) return status('Keine Tankstellen mit Preis im Umkreis gefunden.');
    status(`${d.stations.length} Tankstellen gefunden.` + (d.demo ? ' (Demo-Daten – API-Key fehlt)' : ''));
    stations = d.stations;
    $('results').innerHTML = d.stations.map(s => {
      const p = s.price.toFixed(3);
      const addr = `${esc(s.street)} ${esc(s.houseNumber)}, ${esc(s.postCode)} ${esc(s.place)}`;
      const map = `https://www.google.com/maps/dir/?api=1&destination=${s.lat},${s.lng}`;
      const star = user ? `<button class="star${favs.has(s.id) ? ' on' : ''}" data-id="${esc(s.id)}" title="Favorit">★</button>` : '';
      return `<li data-i="${stations.indexOf(s)}"><div><div class="name">${star}${esc(s.brand || s.name)}</div><div class="addr">${addr}</div>
        <div class="meta">${s.dist} km · <span class="${s.isOpen ? 'open' : 'closed'}">${s.isOpen ? 'geöffnet' : 'geschlossen'}</span> · <a href="${map}" target="_blank" rel="noopener">Route</a></div></div>
        <div class="price">${p.slice(0, -1)}<sup>${p.slice(-1)}</sup> €</div></li>`;
    }).join('');
    const markers = showMap(lat, lng, d.stations);
    document.querySelectorAll('#results li').forEach((li, i) => li.addEventListener('click', ev => {
      if (ev.target.closest('a, .star')) return;
      $('map').scrollIntoView({ behavior: 'smooth', block: 'center' });
      map.setView(markers[i].getLatLng(), 15);
      markers[i].openPopup();
    }));
  } catch (e) { status(e.message, true); }
}

$('form').addEventListener('submit', async e => {
  e.preventDefault();
  const q = $('q').value.trim();
  if (!q) return status('Bitte Ort/PLZ eingeben oder Standort verwenden.', true);
  try {
    status('Suche Ort …');
    const g = await api({ action: 'geocode', q });
    status(g.label);
    await search(g.lat, g.lng);
  } catch (e) { status(e.message, true); }
});

$('geo').addEventListener('click', () => {
  if (!navigator.geolocation) return status('Standortabfrage wird nicht unterstützt.', true);
  status('Ermittle Standort …');
  navigator.geolocation.getCurrentPosition(
    p => search(p.coords.latitude, p.coords.longitude),
    () => status('Standort nicht verfügbar – bitte Ort/PLZ eingeben.', true),
    { timeout: 10000 });
});

// ---- Login & Favoriten ----
function renderAuth() {
  $('auth').innerHTML = user
    ? `Angemeldet als <b>${esc(user)}</b> <button id="logout">Abmelden</button>`
    : '<button id="showlogin">Anmelden</button>';
  $('favsec').hidden = !user;
  if (user) $('loginform').hidden = true;
}

async function loadFavs() {
  if (!user) { favs = new Map(); $('favs').innerHTML = ''; return; }
  try {
    const d = await api({ action: 'fav_list' });
    favs = new Map(d.favorites.map(f => [f.id, f]));
    const t = $('type').value;
    $('favs').innerHTML = d.favorites.length ? d.favorites.map(f => {
      const pr = f.prices && f.prices[t];
      const price = pr ? pr.toFixed(3) : null;
      const map = `https://www.google.com/maps/dir/?api=1&destination=${f.lat},${f.lng}`;
      return `<li><div><div class="name"><button class="star on" data-id="${esc(f.id)}" title="Entfernen">★</button>${esc(f.brand || f.name)}</div>
        <div class="addr">${esc(f.street)} ${esc(f.houseNumber)}, ${esc(f.postCode)} ${esc(f.place)}</div>
        <div class="meta">${f.prices ? `<span class="${f.prices.open ? 'open' : 'closed'}">${f.prices.open ? 'geöffnet' : 'geschlossen'}</span> · ` : ''}<a href="${map}" target="_blank" rel="noopener">Route</a></div></div>
        <div class="price">${price ? `${price.slice(0, -1)}<sup>${price.slice(-1)}</sup> €` : '–'}</div></li>`;
    }).join('') : '<li>Noch keine Favoriten – tippe bei einer Tankstelle auf ★.</li>';
    document.querySelectorAll('#results .star').forEach(b => b.classList.toggle('on', favs.has(b.dataset.id)));
  } catch (e) { status(e.message, true); }
}

async function toggleFav(id) {
  try {
    if (favs.has(id)) await api({ action: 'fav_del', id }, true);
    else {
      const s = stations.find(x => x.id === id) || {};
      await api({ action: 'fav_add', id, name: s.name ?? '', brand: s.brand ?? '', street: s.street ?? '',
        houseNumber: s.houseNumber ?? '', postCode: s.postCode ?? '', place: s.place ?? '', lat: s.lat ?? 0, lng: s.lng ?? 0 }, true);
    }
    await loadFavs();
  } catch (e) { status(e.message, true); }
}

document.addEventListener('click', async e => {
  const star = e.target.closest('.star');
  if (star) return toggleFav(star.dataset.id);
  if (e.target.id === 'showlogin') $('loginform').hidden = !$('loginform').hidden;
  if (e.target.id === 'logout') {
    try { csrf = (await api({ action: 'logout' }, true)).csrf; user = null; renderAuth(); await loadFavs();
      document.querySelectorAll('#results .star').forEach(b => b.remove()); status('Abgemeldet.'); }
    catch (err) { status(err.message, true); }
  }
});

$('loginform').addEventListener('submit', async e => {
  e.preventDefault();
  const action = e.submitter?.dataset.act || 'login';
  try {
    const d = await api({ action, username: $('lu').value, password: $('lp').value }, true);
    user = d.user; csrf = d.csrf; $('lp').value = '';
    renderAuth(); status(''); await loadFavs();
  } catch (err) { status(err.message, true); }
});

$('type').addEventListener('change', loadFavs);

(async () => {
  try {
    const d = await api({ action: 'me' });
    user = d.user; csrf = d.csrf;
    if (!d.allow_register) $('regbtn').hidden = true;
    renderAuth(); await loadFavs();
  } catch (e) { status('Login nicht verfügbar: ' + e.message, true); }
})();
