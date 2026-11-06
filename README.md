# Picshare

Kleines, passwortgeschütztes Image Sharing für PHP 8.4 und einfaches Webhosting. Uploads und Galerie sind nur nach Anmeldung sichtbar. Freigabelinks funktionieren ebenfalls nur für angemeldete Nutzer.

## Anforderungen

- PHP 8.4 mit den Erweiterungen `fileinfo` und `session`
- Webserver mit HTTPS
- Ein beschreibbarer Datenordner **außerhalb** des öffentlichen Webroots

## Installation

1. Lade den Inhalt dieses Repositories in ein Verzeichnis, das dein Hoster mit PHP 8.4 ausführt.
2. Lege außerhalb dieses öffentlichen Verzeichnisses einen Ordner für Bilder an, zum Beispiel `~/private/picshare-data`, und gib dem PHP-Prozess Schreibrechte.
3. Kopiere `config.example.php` nach `config.php`. Trage in `data_dir` den absoluten Pfad des Datenordners ein.
4. Erzeuge lokal einen Passwort-Hash mit `php -r "echo password_hash('DEIN_LANGES_PASSWORT', PASSWORD_DEFAULT), PHP_EOL;"` und trage ihn in `password_hash` ein. Verwende ein langes, einzigartiges Passwort.
5. Öffne die Website über HTTPS und melde dich mit dem zugehörigen Passwort an.

`config.php` wird von Git ignoriert und darf nicht veröffentlicht werden. Falls dein Hoster keinen Speicher außerhalb des Webroots erlaubt, setze dich mit dessen Support in Verbindung; direkte Webzugriffe auf Uploads müssen zuverlässig gesperrt sein. Die Anwendung liefert Bilder nur über einen authentifizierten PHP-Endpunkt aus.

## Betrieb

Unterstützt werden JPEG, PNG, GIF und WebP. Standardmäßig sind Uploads auf 12 MiB begrenzt. Das PHP-Limit `upload_max_filesize` und `post_max_size` muss mindestens so hoch sein wie der eingestellte Wert. Größere Dateien oder Videos sind derzeit absichtlich nicht aktiviert.

Setze regelmäßig Sicherheitsupdates für PHP und den Webserver ein. Lege Backups des Datenordners an; darin liegen sowohl die Bilder als auch deren Metadaten. Wer das gemeinsame Passwort kennt, kann alle Bilder ansehen, hochladen und löschen. Teile es daher nur mit den gewünschten Personen.
