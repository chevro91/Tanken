const $ = id => document.getElementById(id);
const status = (msg, err = false) => { $('status').textContent = msg; $('status').className = err ? 'error' : ''; };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function api(params) {
  const r = await fetch('api.php?' + new URLSearchParams(params));
  const d = await r.json();
  if (!d.ok) throw new Error(d.error || 'Fehler');
  return d;
}

let map, layer;
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
    $('results').innerHTML = d.stations.map(s => {
      const p = s.price.toFixed(3);
      const addr = `${esc(s.street)} ${esc(s.houseNumber)}, ${esc(s.postCode)} ${esc(s.place)}`;
      const map = `https://www.google.com/maps/dir/?api=1&destination=${s.lat},${s.lng}`;
      return `<li><div><div class="name">${esc(s.brand || s.name)}</div><div class="addr">${addr}</div>
        <div class="meta">${s.dist} km · <span class="${s.isOpen ? 'open' : 'closed'}">${s.isOpen ? 'geöffnet' : 'geschlossen'}</span> · <a href="${map}" target="_blank" rel="noopener">Route</a></div></div>
        <div class="price">${p.slice(0, -1)}<sup>${p.slice(-1)}</sup> €</div></li>`;
    }).join('');
    const markers = showMap(lat, lng, d.stations);
    document.querySelectorAll('#results li').forEach((li, i) => li.addEventListener('click', ev => {
      if (ev.target.closest('a')) return;
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
