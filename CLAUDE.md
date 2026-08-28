# Hinweise für die Arbeit an diesem Repository

## Verwandte Repositories

Teil desselben Modul-Verbunds (NRG-Stack, DG65), an mehreren wird teils gleichzeitig in
getrennten Sitzungen gearbeitet:

- **StrukturHub** (dieses Repo): Objektbaum-Struktur maschinenlesbar machen —
  https://github.com/DG65/NRGStrukturHub
- **EMS**: koordinierende Instanz, Verbund-Manifest (SUITE.md) — https://github.com/DG65/NRGEMS
- **MeterHub**: erster geplanter Konsument (Raumzähler-Assistent: kaskadierte virtuelle
  Raumzähler gegen `STRUKT_GetStructure()`)
- **NRGDashboard**: geplanter Konsument (Gruppierung nach Räumen/Etagen)

## Rolle und Grundregeln

1. **Reine Auskunft, kein Regler.** StrukturHub liest die bestehende Struktur und publiziert
   sie über `STRUKT_GetStructure`. v0.1 legt nichts an, ändert nichts — v0.2 (Gerüst-Generator)
   ist ein separater, späterer Schritt.
2. **Eigenständigkeit.** StrukturHub setzt kein anderes Modul voraus und wird selbst von
   keinem vorausgesetzt — jeder Fremdaufruf (sollte in v0.2+ nötig werden) hinter
   `function_exists()`. Prüfwerkzeug: `.tools/check-standalone.php`.
3. **Vertrag ist öffentliche API.** Einmal veröffentlichte Feldnamen in
   `STRUKT_GetStructure()` werden nie umbenannt, nur additiv erweitert (Minor hoch), Bruch
   nur mit Major + Ankündigung.
4. **Rückgabe ist ein JSON-STRING, kein Array** — genau an dieser Stelle sind Dashboard und
   ChargerHub beim MeterHub-Vertrag schon je einmal gestolpert (`is_array()` auf einem String
   schlägt still fehl). Bei jeder Erweiterung diesen Hinweis in Doku-Kommentar/README
   mitführen.
8. **`key` ist ein garantiert stabiler Ident-Bestandteil** (MeterHub-Anforderung 28.08.2026,
   siehe `resolveKey()`/`KeyRegistry`-Attribut in `module.php`) — NIEMALS zurückbauen auf
   "live aus dem aktuellen Label berechnen". Ein Konsument leitet daraus dauerhafte
   Variablen-Idents ab; ein sich änderender Key würde bei jeder Umbenennung im Objektbaum
   Archiv-Historie verwaisen lassen. Neue Kategorien bekommen ihren Key beim ersten Erfassen,
   danach ist er an der `categoryID` fixiert, bis die Kategorie in Symcon gelöscht wird.
5. **Keine automatische Etagen-Erkennung.** Auf derselben Objektbaum-Ebene wie Etagen können
   Gewerke-Kategorien liegen (Energie, Heizung, Test, …) — das ist bei Dietmars eigener Anlage
   live beobachtet. Der Nutzer bestätigt einmal im Formular, welche Kategorien Etagen sind;
   raten wäre hier Grundregel-Verstoß ("keine eigene Anlage als Norm", siehe SUITE.md).
6. **Sprachregel:** alles Nutzersichtbare auf Deutsch, keine vermeidbaren Anglizismen; Idents,
   Property-/Methodennamen und feststehende Fachbegriffe ausgenommen.
7. **Store-/Stable-Regeln von Anfang an**: Formular-Buttons nur per `UpdateFormField`/`echo`
   (nie `IPS_SetProperty`+`ApplyChanges` selbst), `vendor=""` (reines Software-Modul),
   `library.json` nur mit den erlaubten Schlüsseln, keine Emojis in `Translate()`-Strings,
   Klassenname = Modulname.

## Eigenständigkeit prüfen: `.tools/check-standalone.php`

```
php .tools/check-standalone.php    # 0 = sauber, 1 = ungesicherter Fremdaufruf
```

Herkunft und Funktionsweise wie in den übrigen Hub-Repos — Prüflogik gleich halten.

## Verbund-Manifest SUITE.md — Bezugsquelle

Primärquelle für alle Verbund-Konventionen ist `SUITE.md` im EMS-Repo
(https://github.com/DG65/NRGEMS — während der EMS-Integrationsphase ist der Branch
`ems-integration` der aktuellste Stand, nicht `main`). Bei Konventionsfragen zuerst dort
nachlesen, nicht Code zwischen Modulen vergleichen.

## Roadmap

- **v0.1 (aktuell):** Read-only-Auskunft über bestehende Struktur.
- **v0.2 (geplant):** Gerüst-Generator für Einsteiger — Kategorien + Links nach Konvention
  anlegen. Profitiert vom in v0.1 etablierten Vertrag (das Erzeugte ist per Definition
  vertragskonform).
