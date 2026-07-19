# Bricks Child Theme

Dieses Child-Theme ist ein Fork des snn brx Themes. Die Codebasis wurde gezielt erweitert und präzise an meine eigenen Anforderungen sowie Workflows angepasst.

## Credits

Basiert auf [SNN-BRX](https://github.com/sinanisler/snn-brx-child-theme) von [sinanisler](https://github.com/sinanisler). Vielen Dank für die großartige Grundlage. Lizenziert unter der [GNU General Public License (GPL)](https://github.com/sinanisler/snn-brx-child-theme/blob/main/license.txt), wie im Original.


## Neue Features

*   **Automatische Bildoptimierung bei Upload:** Neu hochgeladene JPG/PNG-Bilder werden automatisch im Hintergrund zu WebP oder AVIF konvertiert und ersetzen das Original in-place (gleiche URL, gleiche Attachment-ID) — funktioniert unabhängig vom Upload-Weg (Mediathek, Block-Editor, REST-API, Bricks Builder). Ersetzt das Plugin [CompressX](https://de.wordpress.org/plugins/compressx/). Standardmäßig deaktiviert, einstellbar unter *Medien → Optimize Media → History & Settings*. Original bleibt für Restore erhalten.
*   **Zwei-Faktor-Authentifizierung (E-Mail-Code):** Ersetzt das Plugin [Two-Factor](https://wordpress.org/plugins/two-factor/). Anders als das Original ist es kein Opt-in pro Nutzer, sondern ein globaler Schalter unter *Security Settings*, der 2FA für alle Benutzerkonten erzwingt (per E-Mail zugestellter Einmalcode, 15 Minuten gültig). Ausnahme: Konten, deren einzige Rolle(n) WooCommerce „Customer" und/oder „Subscriber" sind, bleiben ausgenommen — jede zusätzliche Rolle (Administrator, Redakteur, Autor, Shop-Manager, ...) erzwingt 2FA trotzdem. Standardmäßig deaktiviert. Das bisherige Captcha-Schutz-Setting (Math-Captcha / Cloudflare Turnstile) wurde dafür entfernt.
    *   **IP-Whitelist:** Im selben Settings-Bereich können IP-Adressen oder CIDR-Bereiche (z. B. `203.0.113.0/24`) hinterlegt werden, die 2FA komplett überspringen — z. B. für Büro- oder VPN-IPs. Die Seite zeigt die eigene aktuelle IP an und fügt sie per Klick hinzu.
    *   **Notausschalter:** Falls man sich mal aussperrt (z. B. weil die Code-Mail nicht ankommt), schaltet folgende Zeile in der `wp-config.php` die 2FA-Pflicht sofort aus, ganz ohne Datenbank- oder Admin-Zugriff:
        ```php
        define( 'SNN_2FA_DISABLE', true );
        ```
*   **Code Snippets neu gebaut (FluentSnippets-Stil):** Ersetzt das bisherige starre 4-Slot-Modell (Frontend Head/Footer, Admin Head, "Sofort") durch eine beliebige Anzahl benannter, einzeln umschaltbarer Snippets in einer durchsuchbaren Tabelle — mit Ein/Aus-Schalter pro Zeile, Typ (PHP/CSS/JS/HTML, farbige Badges), Ort, Tags, Priorität und "Aktualisiert am". CSS/JS/HTML werden ohne `eval()` als reine `<style>`/`<script>`/HTML-Ausgabe gerendert (kein PHP-Risiko). Verursacht ein PHP-Snippet einen fatalen Fehler, wird **nur dieses eine** Snippet automatisch deaktiviert (nicht mehr die ganze Funktion wie zuvor). Inklusive JSON-Export/Import, Revisionsverlauf pro Snippet und Download-Link. Bestehende Snippets werden beim ersten Laden automatisch ins neue Modell übernommen. `SNN_CODE_DISABLE`-Notausschalter für die wp-config.php bleibt bestehen.
    *   **Datei-basierte Ausführung (wie FluentSnippets):** Aktive Snippets werden bei jeder Änderung zusätzlich als reine PHP-Dateien unter `wp-content/uploads/snn-code-snippets/` kompiliert (geschützt per `.htaccess`). Normale Seitenaufrufe laden diese Dateien nur noch per `include()` — **keine Datenbank-Abfrage mehr** zur Laufzeit. Jedes PHP-Snippet wird vor dem Kompilieren einzeln validiert; ein fehlerhaftes Snippet wird automatisch deaktiviert und **nicht** in die Datei aufgenommen, sodass ein kaputtes Snippet nie die anderen an diesem Ort mit ausschalten kann. Ist das Verzeichnis nicht beschreibbar, greift automatisch die bisherige Datenbank-Logik als Fallback.

## Key Features (Stripped)


*   **White Labeling:** Brand the theme as your own.
*   **Custom Post Types:** Easily create and manage custom post types.
*   **Custom Fields & Repeaters:** Build custom fields and repeater fields with an intuitive interface.
*   **Custom Taxonomies:** Register and manage custom taxonomies like categories and tags.
*   **Security Settings:** Enhance your website's security.
*   **Block Editor Settings:** Optimize the WordPress Block Editor.
*   **Disable File Editing:** Prevent direct file editing in the WordPress dashboard.
*   **Disable WP JSON (if not logged in):** Improve security by disabling JSON endpoints for non-logged-in users.
*   **SMTP Settings & Mail Logs:** Configure transactional emails and track mail activity.
*   **Custom Login/Register Page:** Create branded login and registration experiences.
*   **Remove WP Version:** Obscure your WordPress version for added security.
*   **WP Revision Limit:** Control the number of post revisions to optimize database performance.
*   **GSAP Animations:** Integrate powerful GreenSock Animation Platform (GSAP) animations with dedicated elements and easy-to-use controls for various animation types (Scroll Trigger, SplitText, Entrance, Loop) and responsive options.
*   **OSM Map Element:** Add OpenStreetMap elements to your designs.
*   **SNN Settings Panel:** A centralized hub for managing all theme features.
*   **GPL Licensed:** Distributed under the GNU General Public License (GPL).
*   **Auto Update:** Update the theme directly from GitHub with a single click.
*   **Cookie Banner:** Implement a cookie consent banner.
*   **Code Snippets:** Add custom code snippets easily.
