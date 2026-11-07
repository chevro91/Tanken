const $ = id => document.getElementById(id);
const status = (msg, err = false) => { $('status').textContent = msg; $('status').className = err ? 'error' : ''; };
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function api(params) {
  const r = await fetch('api.php?' + new URLSearchParams(params));
  const d = await r.json();
  if (!d.ok) throw new Error(d.error || 'Fehler');
  return d;
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
