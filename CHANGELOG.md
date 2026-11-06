# Changelog

Alle wichtigen Änderungen am Theme. Ältere Versionen (vor 1.0) sind auf der [Releases-Seite](https://github.com/digital-workshop/bricks-child-theme/releases) dokumentiert.

## [1.1.1] – 2026-11-06

### Behoben

- **Analytics zählt weniger Bots:** Seitenaufrufe werden jetzt erst gezählt, wenn die Seite im Browser geladen ist (kleines JavaScript im Footer). Bots, die kein JavaScript ausführen, zählen nicht mehr. 404-Seiten werden ebenfalls nicht mehr gezählt. Das wirkt auch hinter einem Seiten-Cache. Bereits gespeicherte Zahlen bleiben unverändert.

## [1.1.0] – 2026-11-06

### Neu

- **Link-Klicks im Analytics** statt Track The Click. Neue Karte „Link-Klicks“ auf der Analytics-Seite: Diagramm pro Tag und Tabelle mit Link-URL, Anchor, Seite und Klicks.
  - Zählt Klicks auf Links, auch `tel:`, `mailto:` und WhatsApp.
  - Filter nach Gerät: Desktop (über 1024 px), Tablet (768–1024 px), Mobil (unter 768 px). Gemessen wird die Browserbreite beim Klick.
  - Links auf die eigene Domain werden ausgeblendet. Weitere Domains trägst du in den Analytics-Einstellungen ein.
  - Bots, ausgeschlossene Rollen und IPs sowie die Aufbewahrungsdauer gelten wie bei den Seitenaufrufen.
- **KI-Verbindung (MCP)** statt Easy MCP AI. Unter *SNN Settings → KI-Verbindung (MCP)* kannst du Claude mit der Seite verbinden (claude.ai, Desktop-App, Claude Code).
  - Standardmäßig aus. Die Server-URL ist `https://deine-seite/dw-mcp`.
  - Anmeldung per OAuth mit „Erlauben“-Seite. Nur Benutzer, die du anhakst, dürfen eine Verbindung erlauben.
  - Nur Claude darf sich anmelden (claude.ai, claude.com, localhost).
  - Jedes Tool hat einen eigenen Schalter. Neue Tools (z. B. nach einem Plugin-Update) sind erst mal aus.
  - Tools für Beiträge, Seiten, eigene Inhaltstypen, Medien, Kategorien/Tags, Menüs, Kommentare, ACF-Felder und Bricks-Layouts (nur lesen). Dazu alle Fähigkeiten, die SEOPress selbst mitbringt.
  - Immer gesperrt: Plugins, Themes, Benutzer und Rollen, Einstellungen, Dateien und Code. Löschen verschiebt nur in den Papierkorb.
  - Verbindungen enden nach 30 Tagen ohne Nutzung. Jede Verbindung lässt sich einzeln trennen.
  - Jede Aktion der KI landet in einem Protokoll (90 Tage).
  - Die KI kann die globalen SEOPress-Einstellungen ändern: Titel & Metas, Social Media und Sitemaps. Das geht nur mit Admin-Rechten. Instant Indexing und Analytics bleiben gesperrt.

### Behoben

- **Analytics:** Die Reihenfolge der Karten und das Einklappen werden jetzt gespeichert. Vorher sprangen die Karten bei jedem Laden zurück.

## [1.0.1] – 2026-10-20

### Behoben

- **Theme-Aktivierung:** Beim Aktivieren des Themes kam ein Fatal Error (`Call to undefined function wp_tempnam()`). Die Code Snippets laden die nötige WordPress-Datei jetzt selbst.

## [1.0] – 2026-10-09

Mit diesem Release ist das Theme stabil. Die eigenen Funktionen ersetzen jetzt mehrere Plugins:

- **Analytics** statt Independent Analytics: Seitenaufrufe, Besucher, Top-Seiten, Referrer, Social-Media-Traffic, Markierungen im Diagramm und Import der alten IAWP-Daten
- **Weiterleitungen & 404-Log** statt Redirection
- **Code Snippets** statt FluentSnippets

### Neu

- **Analytics: Wer darf die Statistiken sehen.** Unter *SNN Settings → Analytics* legst du jetzt fest, welche Benutzerrollen das Analytics-Dashboard öffnen dürfen.
  - Administratoren haben immer Zugriff.
  - Einstellungen ändern sowie Markierungen hinzufügen oder löschen dürfen weiterhin nur Administratoren.
  - Ohne Änderung der Einstellung bleibt alles wie bisher, also nur Administratoren.

[1.1.0]: https://github.com/digital-workshop/bricks-child-theme/releases/tag/v1.1.0
[1.0.1]: https://github.com/digital-workshop/bricks-child-theme/releases/tag/v1.0.1
[1.0]: https://github.com/digital-workshop/bricks-child-theme/releases/tag/v1.0
