# Picshare – Produktplan

Ziel: ein kleines, verlässliches privates Medienalbum für einen Freundeskreis, das auf gewöhnlichem PHP‑8.4-Webspace läuft und ohne laufende Dienste oder komplizierte Wartung auskommt.

## Leitplanken

- Eine Installation, eine kleine Oberfläche, wenige Einstellungen.
- Galerie und Originale bleiben hinter der Anmeldung. Freigaben sind bewusst erzeugte, widerrufbare Links mit klar gewähltem Ablauf.
- PHP 8.4 mit PDO SQLite, HTTPS und ein Datenordner außerhalb des Webroots als Basis; keine Pflicht zu Node, Docker, Queue oder externem Dienst.
- Erst Bilder und ein guter Alltagsablauf. Videos und weitere Dateitypen folgen über denselben Medienablauf, wenn Speicherplatz, Limits und Auslieferung geklärt sind.
- Jede Funktion muss leicht verständlich, sicher betreibbar und auf Shared Hosting deploybar sein.
- Die Oberfläche bleibt schlicht und flach, mit wenigen zurückhaltenden Farben und ohne dekorative Effekte.

## Design-Leitbild

Gewählte Richtung: **C · Album** aus `design-varianten.html` — warmes, zurückhaltendes Farbschema, Serifenschrift für Seitentitel und größere Bildflächen.

- [ ] Helles und dunkles Farbschema mit CSS-Variablen umsetzen; beim ersten Besuch die Systemeinstellung (`prefers-color-scheme`) übernehmen.
- [ ] Einen gut auffindbaren Hell/Dunkel/System-Umschalter anbieten und die manuelle Wahl lokal im Browser speichern.
- [ ] Neutrale Flächen und Textfarben verwenden; eine einzige dezente Akzentfarbe für primäre Aktionen, Warnungen und Fehler sparsam einsetzen.
- [ ] Flache Gestaltung ohne Verläufe, starke Schatten oder unnötige Rahmen; klare Abstände, einfache Typografie und wenige konsistente Komponenten.
- [ ] Mobile zuerst gestalten: Upload, Link kopieren und Galerie müssen auf schmalen Bildschirmen ohne horizontales Scrollen funktionieren.
- [ ] Tastaturbedienung, sichtbare Fokusmarkierung, ausreichende Kontraste in beiden Modi und verständliche Beschriftungen sicherstellen.
- [ ] Zustände für Laden, leere Galerie, Upload-Fortschritt, Erfolg, Fehler und abgelaufene Freigaben visuell einheitlich definieren.

**Fertig, wenn:** die Oberfläche in beiden Modi ruhig und gut lesbar bleibt und die häufigsten Aktionen auf dem Handy direkt erreichbar sind.

## Beschlossene Speicherstruktur

```text
data_dir/
  picshare.sqlite
  users/
    <interne-benutzer-id>/
      <zufällige-medien-id>.<endung>
  tmp/
```

- [ ] SQLite speichert Benutzer, Medien-Metadaten, Freigabe-Token-Hashes und Ablaufzeiten; Mediendateien bleiben normale Dateien.
- [ ] Für Ordnernamen eine unveränderliche zufällige interne Benutzer-ID verwenden, niemals Loginname oder Anzeigename.
- [ ] Datenbank und Benutzerordner außerhalb des Webroots halten. Medien nur nach passender Berechtigungsprüfung durch PHP ausliefern.
- [ ] Öffentliche Share-IDs und interne Medien-IDs getrennt und kryptografisch zufällig erzeugen; für gut teilbare URLs Crockford Base32 ohne Padding verwenden (128 Bit Zufall ergeben 26 Zeichen).
- [ ] Keine Originaldateinamen oder echten Speicherpfade in URLs verwenden. Base32 ist nur eine kompakte Kodierung, kein Schutz durch Verschlüsselung.
- [ ] SQLite-Journalmodus auf dem Hetzner-Webspace prüfen und bewusst festlegen; bei WAL müssen Datenbank und Journaldateien lokal auf demselben Host und Dateisystem liegen.
- [ ] Schreibtransaktionen kurz halten, `busy_timeout` setzen und bei einer vorübergehenden Schreibsperre verständlich reagieren.
- [ ] Backups umfassen die SQLite-Datenbank und alle Medienordner als konsistentes Set; Wiederherstellung gemeinsam dokumentieren.

## 0. Solide Grundlage

- [ ] Projektstart vereinfachen: klare Setup-Schritte, Konfigurationsprüfung und verständliche Fehlermeldungen für PHP-Version, Erweiterungen, Schreibrechte, HTTPS und Upload-Limits.
- [ ] Geheimnisse und private Daten absichern: `config.php`, Datenordner und Backups außerhalb des Webroots halten; Git-Ignore und Beispiele prüfen.
- [ ] Anmeldung härten: Login-Versuche begrenzen, Sitzungs-Cookies vor dem Start korrekt setzen, Session-ID nach Anmeldung wechseln, Abmeldung und CSRF-Schutz konsistent halten.
- [ ] Uploads sicher behandeln: Größenlimits serverseitig prüfen, Dateityp anhand des Inhalts ermitteln, fehlerhafte/überdimensionierte Bilder zurückweisen und Originalnamen nur als bereinigte Anzeige-Metadaten verwenden.
- [ ] Einheitliche Fehlerbehandlung und Protokollierung einführen, ohne Passwörter, Session-IDs oder private Freigabetokens in Logs aufzunehmen.
- [ ] Wartungsfunktionen ergänzen: Konfiguration und Speicherbedarf im Adminbereich anzeigen; Backup- und Wiederherstellungsanleitung dokumentieren.

**Fertig, wenn:** eine frische Installation sicher eingerichtet werden kann und typische Hosting-Fehler mit einer konkreten Lösung erklärt werden.

## 1. Angenehmer Upload-Alltag

- [ ] Upload per Dateiauswahl und Drag-and-drop ermöglichen.
- [ ] Mehrere Bilder in einem Vorgang hochladen; Fortschritt, Fehler und erfolgreiche Dateien einzeln anzeigen.
- [ ] Bilder direkt nach dem Upload in der Galerie zeigen und nach Upload-Zeit sortieren.
- [ ] Nach erfolgreichem Upload eine gut sichtbare Aktion „Link kopieren“ anbieten; Kopieren bestätigen und einen normalen Link als Fallback zeigen.
- [ ] Vorschau vor dem Upload sowie verständliche Hinweise zu unterstützten Formaten und Größenlimit ergänzen.
- [ ] Upload nach Abbruch oder Verbindungsfehler erneut versuchen können, ohne erfolgreiche Dateien doppelt hochzuladen.

**Fertig, wenn:** ein Freund mehrere Bilder vom Handy oder Desktop hochladen und die Links ohne manuelles Markieren kopieren kann.

## 2. Kleine, nützliche Galerie

- [ ] Responsive Rasteransicht mit Vorschaubildern, Ladezustand und Tastatur-bedienbarer Detailansicht bauen.
- [ ] Detailansicht mit nächstem/vorherigem Bild, Original öffnen/herunterladen und Link kopieren.
- [ ] Galerie nach neuestem/ältestem Upload sortieren; optional einfache Suche nach Dateiname anbieten.
- [ ] Mehrfachauswahl mit wenigen Sammelaktionen: Links kopieren und ausgewählte Bilder löschen.
- [ ] Löschdialog mit Dateiname und klarer Bestätigung; Erfolg oder Fehler sichtbar melden.
- [ ] Leeren Zustand und Pagination oder „Mehr laden“ für größere Sammlungen ergänzen.

**Fertig, wenn:** die Galerie mit einigen hundert Dateien übersichtlich bleibt und zentrale Aktionen mobil wie am Desktop erreichbar sind.

## 3. Freigaben, die verständlich und sicher sind

- [ ] Für einzelne Medien explizite Freigabelinks erzeugen; Galerie bleibt weiterhin nur nach Anmeldung sichtbar.
- [ ] Einen neuen Link für ein Medium erzeugen können. Neuer Link ersetzt den bisherigen sofort (Token-Rotation); alte Links sind damit ungültig.
- [ ] Kurze Freigabe-URL im Format `/s/<token>` verwenden; den Token-Hash in SQLite speichern und beim Aufruf sicher vergleichen.
- [ ] Apache `mod_rewrite` nutzt wenige explizite Regeln, um `/s/<token>` und erlaubte Medienrouten an `index.php` weiterzugeben. PHP prüft weiterhin Anmeldung/Freigabe, Ablaufzeit und Medienberechtigung vor jeder Auslieferung.
- [ ] Falls Medienrouten eine Endung tragen, nur ausdrücklich erlaubte Bild-/Video-Endungen matchen und mit dem Medientyp aus SQLite abgleichen; nie einen URL-Parameter als Dateipfad verwenden.
- [ ] Freigabeoptionen minimal halten: Link deaktivieren/löschen und optional Ablaufdatum setzen.
- [ ] Freigabe-Token kryptografisch zufällig erzeugen und getrennt von Dateinamen speichern; Tokens nie in Logs oder Fehlermeldungen ausgeben.
- [ ] Teilen-Ansicht ohne Anmeldung nur für genau das freigegebene Medium zugänglich machen; keine Galerie, Dateiliste oder Metadaten anderer Medien offenlegen.
- [ ] Unbekannte, widerrufene oder abgelaufene Share-Links auf das vom Betreiber gewählte Rickroll-YouTube-Video weiterleiten. Diese Weiterleitung nur für Share-Link-Aufrufe verwenden; Login, Galerie und normale Fehler bleiben davon unberührt.

**Fertig, wenn:** ein Link gezielt geteilt, neu erzeugt, optional befristet und jederzeit widerrufen werden kann, ohne den privaten Galeriezugriff zu öffnen.

## 4. Eigene Konten für Freunde

- [ ] Gemeinsames Passwort durch individuelle Benutzerkonten ersetzen; jeder Freund erhält ein eigenes Login.
- [ ] Einfache Admin-Funktionen ergänzen: Benutzer anlegen, deaktivieren und Passwort zurücksetzen; öffentliche Selbstregistrierung standardmäßig ausschalten.
- [ ] Passwort-Hashes mit PHP `password_hash` speichern, Login-Versuche begrenzen und Sessions bei Kontowechsel/Deaktivierung widerrufen.
- [ ] In der Galerie standardmäßig alle privaten Medien der Gruppe zeigen; Ersteller und Upload-Zeit sichtbar zuordnen.
- [ ] Nur zwei einfache Berechtigungen vorsehen: Administrator und Mitglied. Administratoren verwalten Konten und alle Medien; Mitglieder laden hoch und verwalten ihre eigenen Medien.
- [ ] Lösch- und Freigaberechte eindeutig durchsetzen, damit Mitglieder keine fremden Medien oder Konten verändern können.

**Fertig, wenn:** jeder Freund ein eigenes Konto hat, Konten einzeln gesperrt werden können und Aktionen einer Person zugeordnet sind.

## 5. Automatisches Löschen

- [ ] Beim Upload eine optionale Aufbewahrungsdauer anbieten, zum Beispiel „nie“, 1 Tag, 7 Tage, 30 Tage oder ein eigenes Datum.
- [ ] Ablaufzeit serverseitig speichern und vor Anzeige sowie Auslieferung abgelaufene Medien konsequent sperren.
- [ ] Abgelaufene Datei und Metadaten automatisch entfernen; auf Hosting ohne Cron regelmäßig beim Seitenaufruf in kleinen, begrenzten Schritten aufräumen.
- [ ] Optional dokumentieren, wie ein Hoster-Cronjob denselben Aufräumvorgang regelmäßig ausführt.
- [ ] Ablaufzeit in Galerie und Detailansicht anzeigen und für berechtigte Benutzer änderbar machen.
- [ ] Klären und dokumentieren, dass Löschung aus Picshare keine externen Kopien oder Backups entfernt; Backups müssen eine eigene Aufbewahrungsfrist haben.

**Fertig, wenn:** ein Medium nach seiner festgelegten Frist nicht mehr abrufbar ist und zeitnah auch aus dem aktiven Speicher entfernt wird, unabhängig davon, ob Cron verfügbar ist.

## 6. Betrieb auf einfachem Webspace

- [ ] Installation als kurze Checkliste dokumentieren: Dateien hochladen, Datenordner anlegen, Konfiguration setzen, HTTPS aktivieren.
- [ ] Setup-Seite oder Kommandozeilenfreiheit für Hoster berücksichtigen; notwendige Werte ohne SSH direkt in der Konfigurationsdatei setzen können.
- [ ] Upload-Limits mit `upload_max_filesize`, `post_max_size`, Laufzeit und verfügbarem Speicherplatz abgleichen und konkret erklären.
- [ ] Export/Backup und Wiederherstellung mit Dateien plus Metadaten beschreiben; optional einfacher ZIP-Export nur für Administratoren.
- [ ] Upgrade-Hinweise und sichere Standardwerte festlegen; Konfigurationsbeispiele bei neuen Optionen synchron halten.
- [ ] GitHub-Veröffentlichung vorbereiten: verständliche README, MIT-Lizenz, Screenshots, Versionshinweise und Issues-Vorlage für Fehlerberichte.

**Fertig, wenn:** eine technisch interessierte Person die Anwendung auf PHP-Webspace ohne separaten Datenbankserver installieren und im Fehlerfall wiederherstellen kann.

## 7. Medien erweitern – erst nach stabilen Bildern

- [ ] Medien-Metadaten und Auslieferung so strukturieren, dass Typ, Größe, Dauer und Vorschaubild später ergänzt werden können, ohne die Galerie neu zu bauen.
- [ ] Vor Videos Limits für Dateigröße, Speicherverbrauch und unterstützte Container festlegen; keine serverseitige Transkodierung voraussetzen.
- [ ] Video-Upload und Browser-Wiedergabe ergänzen; HTTP-Range-Auslieferung für zuverlässiges Springen prüfen.
- [ ] Nicht unterstützte Formate als Download anbieten oder beim Upload verständlich zurückweisen.
- [ ] Speicherverbrauch je Medium und insgesamt anzeigen; einfacher Ablauf zur manuellen Bereinigung.

**Fertig, wenn:** ein Video innerhalb klarer Hosting-Limits hochgeladen und im Browser abgespielt oder eindeutig als Download bereitgestellt werden kann.

## Reihenfolge für die erste nützliche Version

1. **Grundlage:** Setup-Prüfung, Upload-Sicherheit und individuelles Login.
2. **Konten:** Admin kann Freunde anlegen, sperren und Passwörter zurücksetzen.
3. **Komfort:** Mehrfach-Upload mit Fortschritt und Link kopieren.
4. **Galerie:** Detailansicht, Sortierung, Suche und sichere Sammelaktionen.
5. **Freigaben:** neue Links erzeugen, alte Links ungültig machen, Ablaufdatum setzen und Rickroll-Weiterleitung für ungültige Share-Links.
6. **Aufbewahrung:** wählbare Löschfrist plus Aufräumen ohne dauerhaften Hintergrunddienst.
7. **Webspace-Betrieb:** Backup, Wiederherstellung und klare Upgrade-Anleitung.
8. **Videos:** erst nach echten Tests auf dem Zielhosting.

## Bewusst nicht Teil der ersten Version

- Kommentare, Likes, öffentliche Profile oder eine öffentliche Entdecken-Seite.
- Föderation, Cloud-Synchronisierung, mobile Apps oder externe Bild-CDNs.
- Automatische Videokonvertierung, KI-Funktionen oder umfangreiche Rollenverwaltung.
- Komplexer Docker-Deploy für gewöhnlichen Webspace.
