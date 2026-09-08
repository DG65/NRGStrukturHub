# Changelog

Alle nennenswerten Änderungen an diesem Modul werden hier dokumentiert.
Format angelehnt an [Keep a Changelog](https://keepachangelog.com/de/1.0.0/).

## [0.4.2] - 2026-09-09

### Added
- `extractNumber()`/`numberPosition()` erkennen jetzt **zusammengesetzte
  Geschoss.Raum-Nummern** ("1.11 Büro" = Geschoss 1, Raum 11) als EINE Einheit statt nur die
  Ziffern vor dem Punkt zu erfassen — verbreitete Raumnummerierungs-Konvention bei
  öffentlichen Gebäuden/Institutionen (Hochschulen, Verwaltungsgebäude), von Dietmar
  eingebracht, per Internetrecherche bestätigt (u. a. PH Ludwigsburg: Raumnummer "1.016" =
  Gebäude 1, Stockwerk 0, Raum 16). Bisher hätte `extractNumber("1.11 Büro")` fälschlich nur
  `"1"` geliefert (die "11" ging verloren), `numberPosition()` behandelte ein reines "1.11"
  ohne Namenszusatz zusätzlich fälschlich als "hinten" statt als reinen Nummern-Fall ohne
  Position (analog zu "101" pur). Wirkt sich auf den `number`-Wert im
  `STRUKT_GetStructure()`-Vertrag UND auf die Standesamt-Zahlenposition-Konsistenzprüfung aus.
  Reine Erkennungs-Erweiterung — der Baumeister generiert solche zusammengesetzten Nummern
  (noch) nicht selbst, das war nicht Teil dieser Änderung.
- Baumeister-Massen-Hilfe: dritte Option bei „Nummer" — **„vorne mit Punkt"** (z. B. "1.
  Obergeschoss", "100. Obergeschoss") zusätzlich zu den bisherigen "hinten"/"vorne"
  (Live-Rückmeldung Dietmar: die bisherigen zwei Optionen deckten die übliche deutsche
  Ordinalschreibweise bei vorangestellten Etagen-Nummern nicht ab, "1 Etage" statt "1. Etage").
  Bewusst als dritte, eigene Auswahl statt fest an "vorne" gekoppelt — Raumnummern ("101 Büro")
  und Etagen-Ordinalzahlen ("1. Etage") folgen in der Praxis unterschiedlichen Konventionen,
  keine davon wird vorausgesetzt. Gilt identisch für Etagen UND Räume (dieselbe interne
  Formatierungsfunktion).

### Fixed
- **Fatal Error beim Baumeister-Button „➕ Einfügen" und beim Standesamt-Button „✅ Ausgewählte
  übernehmen"** (Live-Fund von Dietmar über die echte Konsole, nicht durch `php_eval`-Tests
  reproduzierbar): `Uncaught TypeError: Katasteramt::AddLevelRows(): Argument #1 ($rows) must
  be of type string, IPSList given`. Ursache: Wird ein List-Formularfeld im `onClick` direkt
  per Feldname referenziert (z. B. `$GenLevels` in
  `STRUKT_AddLevelRows($id, $GenLevels, ...)`), übergibt der IPS-Kernel zur Laufzeit ein
  `IPSList`-Objekt, keinen JSON-String — die bisherige `string`-Typisierung (Fix vom
  28.08.2026 gegen "hat keinen Datentyp"-Warnungen) war dafür zu eng. Betroffene Parameter
  (`AddLevelRows`/`AddRoomRows`/`PreviewSkeleton`/`BuildSkeleton`/`ApplyNamingFixes`) jetzt als
  `mixed` typisiert — analog zu Symcons eigenem EnergyManager-Modul
  (`UIUpdateNameAndStatus(mixed $Values, ...)`, dort mit demselben Grund kommentiert).
  `normalizeFormList()` verarbeitet jetzt JSON-String, PHP-Array UND `IPSList`/`Traversable`
  gleichermaßen. Betraf nur den Formular-Button-Aufruf — `STRUKT_GetStructure()` und alle
  bisherigen `php_eval`-Live-Tests (die immer einen fertigen JSON-String übergaben) waren nie
  betroffen, weshalb der Fehler bei der Verifikation nach der Symcon-Neuinstallation zunächst
  unentdeckt blieb.

## [0.4.1] - 2026-09-09

### Changed
- PHP-Klasse von `StrukturHub` auf `Katasteramt` umbenannt (`module.json→name` entsprechend
  angepasst) — Modulverwaltung zeigte nach der reinen Anzeigenamen-Umstellung (0.4.0) noch
  den technischen Namen `StrukturHub` in der Instanzzeile an. Sicher, weil Dietmars komplette
  Symcon-Neuinstallation keine laufende Instanz mehr übrig ließ, die dadurch hätte brechen
  können (siehe TOOLKIT.md-Faustregel). **Der Funktions-Präfix `STRUKT_` bleibt unverändert**
  (eigenständiges `module.json`-Feld) — `STRUKT_GetStructure()` funktioniert für
  MeterHub/Dashboard/EMS ohne jede Codeänderung weiter. `module.json→aliases` enthält
  `StrukturHub` als Legacy-Alias.

## [0.4.0] - 2026-09-09

### Added
- Standesamt (Panel „🏛️ Standesamt"): Namenskonventions-Berater. Prüft Etagen-/Raumnamen auf
  vier Arten von Auffälligkeiten (Zahlenposition-Konsistenz, Groß-/Kleinschreibung-Konsistenz,
  doppelte Labels, sehr kurze/kryptische Labels) — rein deskriptiv, die Mehrheit der eigenen
  Namen bestimmt die Konvention, nichts wird hartkodiert vorgegeben. Neue Methoden
  `STRUKT_RunNamingCheck`/`STRUKT_ApplyNamingFixes`. Umbenennen (`IPS_SetName()`) nur für vom
  Nutzer angehakte Zeilen mit Korrekturvorschlag, nie automatisch.
- Aus der DG65-Toolkit-Ideenrecherche als Kandidat "Namenskonventions-/Struktur-Berater"
  identifiziert, von Dietmar als Katasteramt-Feature (nicht eigenes Modul) entschieden, da es
  auf demselben Baum arbeitet, den `buildStructure()` bereits vollständig kennt.

### Fixed
- `injectPreview()` durchsuchte das Formular nur auf oberster Ebene — `StructurePreview` liegt
  aber in einem `ExpansionPanel` verschachtelt und wurde deshalb beim ERSTEN Öffnen des
  Formulars nie live befüllt (nur nachträglich per `UpdateFormField()` nach einem Klick auf
  „Struktur jetzt einlesen"). Neue rekursive Hilfsfunktion `findFormElementByName()` behebt
  das und wird auch vom neuen Standesamt-Panel genutzt.

## [0.2.2] - 2026-08-30

### Removed
- **Formular-Tresor (kurzzeitig als 0.3.0 veröffentlicht) wieder entfernt.** Dietmars
  Entscheidung nach Rückfrage: StrukturHub soll sich auf Objektbaum-Struktur konzentrieren,
  Zugriffsschutz für Konfigurationsformulare bekommt ein eigenständiges, neues Modul statt hier
  mitzulaufen — sauberer Schnitt statt zweier fachfremder Aufgaben in einem Modul. Reiner
  Revert (Commit ae8b88f), keine Restspuren im Code. Der `STRUKT_GetStructure()`-Vertrag war
  ohnehin nie betroffen (bewusst getrennt gebaut) — für MeterHub/Dashboard/EMS ändert sich
  nichts.

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
- `buildStructure()`: Räume, die der v0.2-Generator bewusst OHNE Etagen-Zuordnung anlegt,
  während in derselben Struktur auch Etagen existieren (gemischte Struktur), wurden bisher
  komplett übersehen — der "Etagen existieren"-Zweig durchsuchte nur die Kinder je Etage,
  nicht die sonstigen Kinder der Wurzelkategorie. Jetzt werden generator-eigene Räume (erkennbar
  am Ident-Präfix) zusätzlich erfasst, ohne die Gewerke-Ausschluss-Logik für organisch
  gewachsene, nicht geflaggte Kategorien zu verändern.
- Generator-Methoden-Parameter (`rows`/`levelRows`/`roomRows`) explizit als `string` typisiert
  (IPS-Kernel unterstützt nur bool/int/float/string für PREFIX_-Funktionsparameter).
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
