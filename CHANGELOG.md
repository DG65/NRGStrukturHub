# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

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
