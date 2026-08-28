# StrukturHub

![Symcon](https://img.shields.io/badge/Symcon-PHPModul-blue)
![Modul Version](https://img.shields.io/badge/Modul_Version-0.1.0-blue)
![Symcon Version](https://img.shields.io/badge/Symcon_Version-9.0%2B-blue)
![License](https://img.shields.io/badge/License-PolyForm_Noncommercial_1.0.0-lightgrey)
![Check Style](https://github.com/DG65/NRGStrukturHub/actions/workflows/check-style.yml/badge.svg)
[![PayPal](https://img.shields.io/badge/PayPal-Me-blue?logo=paypal)](https://paypal.me/DietmarGureth)

IP-Symcon bietet keinen Konfigurationsrahmen für den Objektbaum — jede Installation
strukturiert Räume/Etagen/Geräte anders, und andere Module können Geräte deshalb nicht
zuverlässig eingruppieren. **StrukturHub** macht eine bestehende Objektbaum-Struktur
maschinenlesbar, damit Partnermodule (Raumzähler-Zuordnung, Dashboard-Gruppierung,
Lastmanagement-Eingruppierung, …) sie automatisch abfragen können, statt jeweils eigene
Heuristiken zu bauen.

## Zwei Funktionen in fester Reihenfolge

- **v0.1 (dieses Release) — Auskunft über Bestehendes, read-only.** Der Nutzer zeigt einmal
  im Formular, wo seine Struktur liegt (Wurzelkategorie der Räume, welche Unterkategorien
  Etagen sind). StrukturHub liefert das als Vertrag. Legt nichts an, baut nichts um —
  gefahrlos installierbar.
- **v0.2 (geplant) — Gerüst-Generator.** Legt für Einsteiger Kategorien + Links nach der
  Verbund-Konvention an. Baut auf dem in v0.1 etablierten Vertrag auf.

## Vertrag `STRUKT_GetStructure($id): string`

Rückgabe ist ein **JSON-STRING** (kein PHP-Array) — beim Aufrufer
`json_decode(STRUKT_GetStructure($id), true)` verwenden.

```json
{
  "contractVersion": "1.0",
  "instanceID": 12345,
  "structureChangedAt": 1787900000,
  "levels": [ { "key": "eg", "label": "Erdgeschoss", "categoryID": 23050, "order": 0 } ],
  "rooms": [
    {
      "key": "kueche",
      "label": "Küche",
      "level": "eg",
      "categoryID": 51304,
      "order": 0,
      "roomType": "kueche",
      "deviceInstanceIDs": [30131, 27898, 48294]
    }
  ]
}
```

- **`key`** (levels UND rooms) ist garantiert: nur Zeichen aus `[a-z0-9_]` (Umlaute
  transliteriert), **eindeutig über levels UND rooms hinweg** (ein gemeinsamer Namensraum) und
  **stabil über eine Umbenennung hinweg** — der Key wird beim ersten Erfassen einer Kategorie
  aus ihrem damaligen Namen abgeleitet und danach dauerhaft an der `categoryID` festgemacht,
  nie bei jedem Aufruf neu aus dem aktuellen Label berechnet. Konsumenten dürfen daraus
  abgeleitete Idents/Variablen dauerhaft anlegen (z. B. `kueche_sum_power`), ohne dass eine
  spätere Umbenennung im Objektbaum sie verwaist.
- `deviceInstanceIDs` ist bereits **dedupliziert und um tote/namenlose Links bereinigt** —
  Konsumenten müssen das nicht selbst nochmal lösen. Ein Link, dessen Ziel eine **Variable**
  (statt einer Instanz) ist, wird auf deren **Elterninstanz** aufgelöst (z. B. ein "Licht"-Link
  direkt auf eine Schalter-Variable eines Aktors) — nur ein Link/eine Variable ganz ohne
  Instanz-Elternteil wird ignoriert. Wird bei jedem Aufruf live aus dem aktuellen Objektbaum
  berechnet (keine Zwischenspeicherung) — bei unverändertem Objektbaum und unveränderter
  Etagen-Auswahl liefert die Methode deterministisch dasselbe Ergebnis.
- `levels` ist leer, wenn keine Etagen-Ebene bestätigt wurde; `rooms[].level` ist dann `""`
  (Räume liegen direkt unter der Wurzelkategorie).
- `order` ist die vom Nutzer im Symcon-Objektbaum gesetzte Reihenfolge (Konsolen-Drag&Drop) —
  robuster als Array-Reihenfolge oder alphabetisches Sortieren nach `key`/`label`.
- `roomType` ist eine **heuristische Best-Effort-Ableitung** aus dem Raumnamen (z. B. für eine
  Icon-Auswahl), `null` wenn nicht erkannt — kein Fachwert, nur eine Anzeige-Hilfe.
- `structureChangedAt` ändert sich **nur**, wenn sich `levels`/`rooms` inhaltlich seit dem
  letzten Aufruf tatsächlich geändert haben (interner Hash-Vergleich) — Konsumenten können
  diesen einen Wert pollen/vergleichen, statt das komplette JSON zu diffen. Es gibt **keinen**
  Push-Mechanismus (kein Event/keine Nachricht bei Änderung).
- `instanceID` ist die ID dieser StrukturHub-Instanz (Debugging/Logging).
- Immer hinter `function_exists('STRUKT_GetStructure')` aufrufen — jedes Partnermodul ist
  optional, ohne StrukturHub installiert bleibt jedes andere Modul unverändert funktionsfähig.

## Mehrere Instanzen

Mehrere StrukturHub-Instanzen sind ausdrücklich zulässig (z. B. Haupthaus + Nebengebäude mit
getrennter Wurzelkategorie) — **keine Singleton-Annahme**. Konsumenten iterieren über ALLE
Instanzen von `IPS_GetInstanceListByModuleID('{CA700334-0982-F356-0617-6952868137E9}')`, nicht
nur die erste gefundene.

## Was StrukturHub NICHT tut (v0.1)

Keine automatische Etagen-Erkennung — auf derselben Ebene wie Etagen können auch
Gewerke-Kategorien liegen (Energie, Heizung, Test, …), das lässt sich nicht zuverlässig
raten. Der Nutzer bestätigt einmal im Formular, welche Kategorien Etagen sind.

## Lizenz

PolyForm Noncommercial 1.0.0, siehe [LICENSE](LICENSE). Spenden willkommen:
[paypal.me/DietmarGureth](https://paypal.me/DietmarGureth).

---

Teil des **NRG-Stack** — welche Modulstände zusammenpassen, steht im
[Kompatibilitäts-Manifest](https://github.com/DG65/NRGEMS/blob/main/SUITE.md).
