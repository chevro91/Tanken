<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Spritpreise in der Nähe</title>
<link rel="stylesheet" href="style.css">
</head>
<body>
<main>
  <h1>⛽ Spritpreise in der Nähe</h1>
  <form id="form">
    <div class="row">
      <input id="q" type="text" placeholder="Ort oder PLZ, z. B. 58095 Hagen" autocomplete="postal-code">
      <button type="button" id="geo" title="Meinen Standort verwenden">📍 Standort</button>
    </div>
    <div class="row">
      <label>Kraftstoff
        <select id="type"><option value="e5">Super E5</option><option value="e10">Super E10</option><option value="diesel">Diesel</option></select>
      </label>
      <label>Umkreis
        <select id="rad"><option>2</option><option selected>5</option><option>10</option><option>25</option></select> km
      </label>
      <label>Sortierung
        <select id="sort"><option value="price">Preis</option><option value="dist">Entfernung</option></select>
      </label>
    </div>
    <button type="submit" class="primary">Tankstellen suchen</button>
  </form>
  <p id="status" role="status"></p>
  <ul id="results"></ul>
  <footer>Daten: <a href="https://creativecommons.tankerkoenig.de/">Tankerkönig</a> (CC BY 4.0), Geocoding: OpenStreetMap/Nominatim</footer>
</main>
<script src="app.js"></script>
</body>
</html>
