# Tanken – Spritpreise in der Nähe

Kleine PHP-Webapp (PHP ≥ 8.1, cURL) zur Suche nach Tankstellen und Preisen im Umkreis.

## Start
```bash
cp config.example.php config.php   # API-Key von https://creativecommons.tankerkoenig.de/ eintragen
php -S localhost:8080 -t public
```
Ohne API-Key läuft die App im Demo-Modus mit Beispieldaten.

Daten: Tankerkönig (CC BY 4.0); Geocoding: OpenStreetMap Nominatim.
