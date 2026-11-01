# Changelog

Alle wichtigen Änderungen am Theme. Ältere Versionen (vor 1.0) sind auf der [Releases-Seite](https://github.com/digital-workshop/bricks-child-theme/releases) dokumentiert.

## [1.1.0] – unveröffentlicht

### Neu

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
