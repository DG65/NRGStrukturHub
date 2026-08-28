# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.2.1] - 2026-08-28

### Added
- `contractVersion` → **1.1**: neues additives Feld `number` (levels UND rooms) — heuristische
  Best-Effort-Ableitung einer Geschoss-/Raumnummer aus dem Namen (vor oder nach dem Namen,
  mit/ohne Trenner), String statt Zahl (führende Nullen bleiben erhalten). Funktioniert für
  jede Kategorie, nicht nur über den Generator erzeugte. Gedacht für Konsumenten, die
  Geräte-Idents aus der Raumnummer ableiten wollen (Dietmar-Anforderung 28.08.2026).
- Gerüst-Generator: Nummer-Position (vorne/hinten) bei der Massen-Erzeugung wählbar
  (`GenLevelNumberPos`/`GenRoomNumberPos`) — z. B. "101 Büro" statt "Büro 101".

### Fixed
- `order` lieferte seit Einführung immer `0` (`IPS_GetObject()['Position']` statt
  `'ObjectPosition'` gelesen) — live beim v0.2-Testlauf gefunden.

## [0.2.0] - 2026-08-28

### Added
- Gerüst-Generator (Panel „🏗️ Struktur-Gerüst anlegen"): legt Etagen-/Raum-Kategorien nach
  der Verbund-Konvention an (`STRUKT_AddLevelRows`/`AddRoomRows`/`PreviewSkeleton`/
  `BuildSkeleton`). Alle Begriffe frei wählbar (Etage/Stockwerk/Geschoss, Raum/Büro/Zimmer),
  per Präfix+Nummernbereich auch in Masse — deckt sowohl privaten als auch gewerblichen
  Gebrauch ab (Dietmars ausdrückliche Anforderung vor Baubeginn, siehe `CLAUDE.md`).
- Vorschau vor jeder echten Anlage (kein Schreibzugriff), Bestätigungs-Checkbox + nativer
  Bestätigungsdialog vor „Jetzt anlegen“. Idempotent über einen stabilen Ident je Kategorie
  (`strukt_<slug>`) — mehrfaches Anlegen mit denselben Zeilen erzeugt keine Duplikate,
  Umbenennung im Formular relabelt statt neu anzulegen.
- Legt ausschließlich Kategorien an, keine Geräte-Instanzen/Links (bewusste Abgrenzung).
- Frisch angelegte Etagen-Kategorien werden im v0.1-Formular direkt vorgehäkelt (nur in der
  offenen Maske, keine Selbstpersistenz) — spart das manuelle Wiederfinden nach dem Anlegen.

## [0.1.0] - 2026-08-28

### Added
- Erste lauffähige Version: `STRUKT_GetStructure($id): string` liest die bestehende
  Objektbaum-Struktur (Wurzelkategorie → optionale Etagen-Ebene → Räume → Geräte-Instanzen)
  ein und liefert sie als JSON-String-Vertrag (`contractVersion` 1.0).
- Formular: Wurzelkategorie wählen, Etagen-Kategorien per Häkchen bestätigen (automatische
  Erkennung wäre Raterei, da Gewerke-Kategorien auf derselben Ebene liegen können),
  Button „Struktur jetzt einlesen“ mit sichtbarer Rückmeldung + Vorschau-Tabelle.
- `deviceInstanceIDs` je Raum bereits dedupliziert (doppelte Links auf dieselbe Instanz)
  und um tote/namenlose Links bereinigt (Ziel existiert nicht mehr).
- `order` (Objektbaum-Position) und optionales `roomType` (heuristische Best-Effort-Ableitung
  aus dem Raumnamen) je Level/Raum — auf Wunsch von Dashboard vor dem Einfrieren ergänzt.
- Auf Wunsch von MeterHub vor dem Einfrieren ergänzt: `key` wird jetzt persistent je
  `categoryID` vergeben (ein gemeinsamer Namensraum über levels+rooms) und bleibt bei
  Umbenennung stabil, statt bei jedem Aufruf neu aus dem Label berechnet zu werden; Links auf
  Variablen (statt Instanzen) werden auf ihre Elterninstanz aufgelöst statt verworfen; neue
  Top-Level-Felder `structureChangedAt` (Hash-basierte Änderungserkennung ohne JSON-Diff) und
  `instanceID`; Mehrinstanz-Semantik (kein Singleton) dokumentiert.
- Repo-Gerüst nach Verbund-Konvention: `library.json`, `LICENSE` (PolyForm Noncommercial
  1.0.0), README mit Vertrags-Dokumentation, `.github/workflows/check-style.yml`,
  `.tools/check-standalone.php`.
- Kein Gerüst-Generator (v0.2) enthalten — reines Auskunfts-Modul.
