<?php

// ===========================================================================
// StrukturHub — macht die BESTEHENDE Objektbaum-Struktur einer IP-Symcon-
// Installation (Etagen → Räume → Geräte-Instanzen) maschinenlesbar, damit
// andere NRG-Stack-Module Geräte zuverlässig eingruppieren können, ohne
// jedes Mal selbst zu raten ("jedesmal anders Chaos", Auftraggeber-Zitat).
//
// STATUS v0.1: reines Auskunfts-Modul (read-only). Legt nichts an, baut
// nichts um — der Nutzer zeigt einmal im Formular, wo seine Struktur liegt,
// StrukturHub liefert das als Vertrag (STRUKT_GetStructure). Der Gerüst-
// Generator (v0.2, Kategorien+Links anlegen) baut später darauf auf.
//
// Kein Modul im Verbund wird vorausgesetzt — StrukturHub hat keine
// Partnermodul-Abhängigkeit und funktioniert komplett eigenständig.
// ===========================================================================

class StrukturHub extends IPSModule
{
    public function Create()
    {
        parent::Create();

        // Wurzelkategorie, unter der die Struktur beginnt (z. B. "Neues
        // Zuhause (Räume)"). Beispielwert, keine Vorgabe — jede Installation
        // hat eine andere Kategorie/Namen.
        $this->RegisterPropertyInteger('RootCategoryID', 0);

        // Welche direkten Kinder der Wurzelkategorie Etagen sind (statt z. B.
        // Gewerke-Kategorien wie "Energie"/"Heizung", die auf derselben Ebene
        // liegen können). Muss der Nutzer einmal bestätigen — automatische
        // Erkennung wäre Raterei, siehe CLAUDE.md. Reihen: CategoryID/Name
        // (Anzeige, wird bei jedem Formularaufbau frisch befüllt, siehe
        // SUITE.md Store-Review Punkt 3) + IsLevel (die eigentliche Eingabe).
        $this->RegisterPropertyString('Levels', '[]');

        // v0.2 Gerüst-Generator: Eingabe-/Planungszustand für Etagen/Räume,
        // die noch angelegt werden sollen (kein Lesevertrag, keine
        // Stabilitätsgarantie wie beim key in buildStructure() nötig).
        $this->RegisterPropertyString('GenLevels', '[]'); // [{Label:string}]
        $this->RegisterPropertyString('GenRooms', '[]');  // [{Label:string, LevelLabel:string}]

        $this->RegisterAttributeInteger('LastRefreshTs', 0);

        // Persistente Key-Zuordnung categoryID -> key (EIN gemeinsamer
        // Namensraum über levels UND rooms hinweg, MeterHub-Anforderung
        // 28.08.2026). Ein Key wird beim ersten Erfassen einer Kategorie aus
        // ihrem damaligen Namen abgeleitet und danach NIE mehr neu berechnet
        // — Konsumenten leiten daraus stabile Idents/Variablen ab (Archiv-
        // Historie), eine spätere Umbenennung im Objektbaum darf den Key
        // nicht ändern. Siehe resolveKey().
        $this->RegisterAttributeString('KeyRegistry', '{}');

        // Änderungserkennung ohne volles JSON-Diffing beim Konsumenten
        // (MeterHub/EMS-Wunsch 28.08.2026): Hash der zuletzt gelieferten
        // levels/rooms-Struktur + Zeitpunkt der letzten tatsächlichen
        // Änderung. Siehe touchChangeTimestamp().
        $this->RegisterAttributeString('LastStructureHash', '');
        $this->RegisterAttributeInteger('StructureChangedAt', 0);

        // Dismiss-Zustände der Formular-Hinweise (Verbund-Konvention, siehe
        // SUITE.md "Einheitliche Formular-Optik").
        $this->RegisterAttributeString('NewsAckVersion', '');
        $this->RegisterAttributeBoolean('ForumHintDismissed', false);

        // v0.3 Formular-Tresor — eigenständig, KEINE Berührung mit dem
        // STRUKT_GetStructure()-Vertrag. Bewusst Attribut statt Property
        // (Verbund-Konvention Zugangsdaten) — Kaveat: MC_DeleteModule()+
        // MC_CreateModule()-Resyncs löschen Attribute (SUITE.md
        // Stolperstein 5). Fail-open per Design: leerer Hash bedeutet IMMER
        // volles Formular, nie "gesperrt ohne bekanntes Passwort" — ein
        // Resync sperrt Dietmar dadurch nie versehentlich aus.
        $this->RegisterAttributeString('VaultPasswordHash', '');
        $this->RegisterAttributeBoolean('VaultUnlocked', false);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            $this->SetStatus(104);
            return;
        }
        $this->SetStatus(102);
    }

    // -----------------------------------------------------------------
    // Öffentlicher Vertrag
    // -----------------------------------------------------------------

    /**
     * STRUKT_GetStructure($id): string
     *
     * WICHTIG: Die Rückgabe ist ein JSON-STRING, kein PHP-Array (Verbund-
     * Konvention) — beim Konsumenten json_decode(STRUKT_GetStructure($id), true)
     * aufrufen, niemals is_array() direkt auf das Ergebnis prüfen.
     *
     * {
     *   "contractVersion": "1.1",
     *   "instanceID": 12345,
     *   "structureChangedAt": 1787900000,
     *   "levels": [ {"key":"eg","label":"Erdgeschoss","categoryID":23050,"order":0,"number":null}, ... ],
     *   "rooms":  [ {"key":"kueche","label":"101 Küche","level":"eg","categoryID":51304,
     *                "order":0,"roomType":"kueche","number":"101",
     *                "deviceInstanceIDs":[30131,27898,48294]}, ... ]
     * }
     *
     * - "levels" ist leer, wenn keine Etagen-Ebene bestätigt wurde — "rooms[].level"
     *   ist dann ebenfalls "" (Räume liegen direkt unter der Wurzelkategorie).
     *   Existieren Etagen, kann "rooms[].level" TROTZDEM "" sein für Räume, die
     *   der v0.2-Gerüst-Generator bewusst ohne Etagen-Zuordnung direkt unter
     *   der Wurzel angelegt hat (gemischte Struktur) — erkennbar am
     *   generator-eigenen Ident-Präfix, nicht jede unflagged Kategorie wird
     *   automatisch zum Raum (sonst kämen Gewerke-Kategorien wieder rein).
     * - "key" (levels UND rooms) ist GARANTIERT: nur Zeichen aus [a-z0-9_]
     *   (Umlaute transliteriert), eindeutig über levels UND rooms HINWEG (ein
     *   gemeinsamer Namensraum, nicht zwei getrennte), und STABIL über eine
     *   Umbenennung im Objektbaum hinweg — der Key wird beim ersten Erfassen
     *   einer categoryID aus deren damaligem Namen abgeleitet und danach
     *   dauerhaft an dieser categoryID festgemacht (siehe resolveKey()), nie
     *   bei jedem Aufruf neu aus dem aktuellen Label berechnet. Konsumenten
     *   dürfen daraus abgeleitete Idents/Variablen dauerhaft anlegen, ohne
     *   dass eine spätere Umbenennung sie verwaist.
     * - "deviceInstanceIDs" ist bereits dedupliziert und um tote/namenlose Links
     *   bereinigt (siehe resolveRoomDevices()) — Konsumenten müssen das nicht
     *   selbst nochmal lösen. Ein Link, dessen Ziel eine VARIABLE (statt einer
     *   Instanz) ist, wird auf deren Elterninstanz aufgelöst (z. B. ein
     *   "Licht"-Link direkt auf eine Schalter-Variable eines Aktors) — nur ein
     *   direkter Link/Variable ohne Instanz-Elternteil wird ignoriert.
     * - "order" ist die vom Nutzer im Symcon-Objektbaum gesetzte Reihenfolge
     *   (Konsolen-Drag&Drop, IPS-Objekt-Position) — verlässlicher als
     *   Array-Reihenfolge oder alphabetisches Sortieren nach "key"/"label"
     *   (sonst würde z. B. "Dachgeschoss" vor "Erdgeschoss" einsortieren).
     *   Aufsteigend sortieren, bei Gleichstand ist die Reihenfolge beliebig.
     * - "roomType" ist eine HEURISTISCHE Best-Effort-Ableitung aus dem
     *   Raumnamen (z. B. für eine Icon-Auswahl) aus einem festen Vokabular
     *   (siehe inferRoomType()) — "null", wenn nicht erkannt. Kein
     *   verlässlicher Fachwert, nur eine Anzeige-Hilfe.
     * - "number" (levels UND rooms, seit contractVersion 1.1) ist eine
     *   HEURISTISCHE Best-Effort-Ableitung einer Geschoss-/Raumnummer aus dem
     *   Namen (siehe extractNumber()) — Zahl kann vor ODER nach dem Namen
     *   stehen ("101 Küche"/"Küche 101"), mit/ohne Trenner. String, nicht
     *   int (führende Nullen bleiben erhalten), "null" wenn keine Nummer
     *   erkennbar. Funktioniert für JEDE Kategorie, nicht nur über den
     *   Gerüst-Generator erzeugte. Kein Fachwert — z. B. für Konsumenten
     *   gedacht, die Geräte-Idents aus der Raumnummer ableiten wollen.
     * - "structureChangedAt" (Unix-Zeitstempel) ändert sich NUR, wenn sich
     *   levels/rooms inhaltlich seit dem letzten Aufruf tatsächlich geändert
     *   haben (Hash-Vergleich intern) — Konsumenten können das als billigen
     *   Änderungs-Check pollen, statt das komplette JSON zu diffen. Es gibt
     *   KEINEN Push-Mechanismus (kein Event/keine Nachricht bei Änderung).
     * - "instanceID" ist die ID dieser StrukturHub-Instanz (Debugging/Logging).
     * - Ohne konfigurierte Wurzelkategorie liefert dies leere levels/rooms-Arrays
     *   und "structureChangedAt": 0, kein Fehler.
     * - MEHRERE StrukturHub-Instanzen sind ausdrücklich zulässig (z. B. Haupthaus
     *   + Nebengebäude mit getrennter Wurzelkategorie) — KEINE Singleton-Annahme.
     *   Konsumenten iterieren über ALLE Instanzen von
     *   IPS_GetInstanceListByModuleID('{CA700334-0982-F356-0617-6952868137E9}'),
     *   nicht nur die erste gefundene.
     */
    public function GetStructure(): string
    {
        return json_encode($this->buildStructure(), JSON_UNESCAPED_UNICODE);
    }

    // -----------------------------------------------------------------
    // Formular-Aktion (Muster 1 aus SUITE.md: echo-Rückgabe + zusätzlich
    // Muster 2: persistente Statuszeile per UpdateFormField)
    // -----------------------------------------------------------------

    public function RefreshStructure(): string
    {
        $structure = $this->buildStructure();
        $this->WriteAttributeInteger('LastRefreshTs', time());

        $this->UpdateFormField('StatusLine', 'caption', $this->statusLineText($structure));
        // 'values' erwartet einen JSON-STRING, kein PHP-Array (RPC-Layer
        // konvertiert verschachtelte Arrays nicht automatisch) — Live-Fund
        // Dietmar 28.08.2026: "Cannot auto-convert value for parameter Value".
        $this->UpdateFormField('StructurePreview', 'values', json_encode($this->previewRows($structure)));

        $roomCount = count($structure['rooms']);
        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            return 'ℹ️ Noch keine Wurzelkategorie ausgewählt.';
        }
        if ($roomCount === 0) {
            return 'ℹ️ Keine Räume gefunden — Wurzelkategorie und Etagen-Auswahl prüfen.';
        }

        $deviceCount = array_sum(array_map(fn($r) => count($r['deviceInstanceIDs']), $structure['rooms']));
        $levelCount  = count($structure['levels']);
        $levelTxt    = $levelCount > 0 ? "$levelCount Etage(n), " : '';

        return "✅ {$levelTxt}{$roomCount} Raum/Räume, {$deviceCount} Geräte-Instanz(en) eingelesen.";
    }

    // Formular-Hinweise ausblenden (Muster: EMS' AckNews()/DismissForumHint(),
    // siehe SUITE.md "Einheitliche Formular-Optik").
    public function AckNews(): void
    {
        $lib = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $this->WriteAttributeString('NewsAckVersion', is_array($lib) ? ($lib['Version'] ?? '') : '');
        $this->UpdateFormField('NewsPanel', 'visible', false);
    }

    public function DismissForumHint(): void
    {
        $this->WriteAttributeBoolean('ForumHintDismissed', true);
        $this->UpdateFormField('ForumHint', 'visible', false);
    }

    // -----------------------------------------------------------------
    // v0.2 Gerüst-Generator — legt NUR Kategorien an (Etagen/Räume), keine
    // Geräte-Instanzen/Links. Massen-Hilfe-Felder (Präfix/Start/Ende) haben
    // bewusst keine eigene Property, sondern werden als Formularfeld-Namen
    // direkt an den Button übergeben (Muster MeterHub VirtualPartners/
    // VirtualRole) — Vorschau/Anlegen arbeiten auf der GERADE OFFENEN Maske,
    // ein "Übernehmen" dazwischen ist nicht nötig.
    // WICHTIG: Listen-Parameter (rows/levelRows/roomRows) sind bewusst als
    // "string" typisiert, NICHT als Array — der IPS-Kernel unterstützt für
    // öffentliche PREFIX_-Funktionen nur bool/int/float/string (Live-Fund
    // 28.08.2026: "hat keinen Datentyp oder einen nicht unterstützten
    // Datentyp"-Warnung im Log bei ungetyptem/array-typisiertem Parameter).
    // Aufrufer übergeben daher immer einen JSON-String (normalizeFormList()
    // dekodiert ihn intern), analog zur GetStructure()-Konvention "Rückgabe
    // ist JSON-String, kein Array" — hier gilt dasselbe für Eingabeparameter.
    // -----------------------------------------------------------------

    public function AddLevelRows(string $rows, string $prefix, int $start, int $end, string $numberPos = 'hinten'): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '⛔ Bitte zuerst ein Präfix eintragen (z. B. "Etage").';
        }
        if ($start > $end) {
            return '⛔ „von" muss kleiner oder gleich „bis" sein.';
        }
        if ($end - $start > 500) {
            return '⛔ Maximal 500 Zeilen auf einmal — Bereich eingrenzen.';
        }

        $list = $this->normalizeFormList($rows);
        for ($n = $start; $n <= $end; $n++) {
            $list[] = ['Label' => $this->composeGeneratedLabel($prefix, $n, $numberPos)];
        }
        $this->UpdateFormField('GenLevels', 'values', json_encode($list));

        return '✅ ' . ($end - $start + 1) . ' Etagen-Zeile(n) eingefügt.';
    }

    public function AddRoomRows(string $rows, string $prefix, int $start, int $end, string $levelLabel, string $numberPos = 'hinten'): string
    {
        $prefix = trim($prefix);
        if ($prefix === '') {
            return '⛔ Bitte zuerst ein Präfix eintragen (z. B. "Büro").';
        }
        if ($start > $end) {
            return '⛔ „von" muss kleiner oder gleich „bis" sein.';
        }
        if ($end - $start > 500) {
            return '⛔ Maximal 500 Zeilen auf einmal — Bereich eingrenzen.';
        }

        $list = $this->normalizeFormList($rows);
        for ($n = $start; $n <= $end; $n++) {
            $list[] = ['Label' => $this->composeGeneratedLabel($prefix, $n, $numberPos), 'LevelLabel' => trim($levelLabel)];
        }
        $this->UpdateFormField('GenRooms', 'values', json_encode($list));

        return '✅ ' . ($end - $start + 1) . ' Raum-Zeile(n) eingefügt.';
    }

    // "vorne": "101 Büro" (Nummer zuerst) — "hinten" (Default, bisheriges
    // Verhalten): "Büro 101". Dietmar-Wunsch 28.08.2026: Gebäude-Konventionen
    // setzen die Nummer mal vor, mal nach dem Namen.
    private function composeGeneratedLabel(string $prefix, int $n, string $numberPos): string
    {
        return $numberPos === 'vorne' ? ($n . ' ' . $prefix) : ($prefix . ' ' . $n);
    }

    public function PreviewSkeleton(string $levelRows, string $roomRows): string
    {
        $result = $this->planSkeleton($this->normalizeFormList($levelRows), $this->normalizeFormList($roomRows), true);
        if ($result['error'] !== null) {
            $this->UpdateFormField('GenPreview', 'values', json_encode([]));
            return '⛔ ' . $result['error'];
        }

        $entries = array_merge($result['levelEntries'], $result['roomEntries']);
        $this->UpdateFormField('GenPreview', 'values', json_encode($entries));

        if (!$entries) {
            return 'ℹ️ Nichts einzufügen — zuerst Etagen/Räume eintragen oder die Massen-Hilfe nutzen.';
        }

        return $this->skeletonSummary($result, 'Würde anlegen');
    }

    public function BuildSkeleton(bool $confirmed, string $levelRows, string $roomRows): string
    {
        if (!$confirmed) {
            return '⛔ Bitte zuerst das Kästchen „Ich habe die Vorschau geprüft" bestätigen.';
        }

        $result = $this->planSkeleton($this->normalizeFormList($levelRows), $this->normalizeFormList($roomRows), false);
        if ($result['error'] !== null) {
            return '⛔ ' . $result['error'];
        }

        $entries = array_merge($result['levelEntries'], $result['roomEntries']);
        $this->UpdateFormField('GenPreview', 'values', json_encode($entries));

        // Komfort-Verzahnung mit v0.1: neu angelegte Etagen-Kategorien direkt
        // in der bestehenden Levels-Tabelle vorhäkeln — NUR in der offenen
        // Maske (UpdateFormField), keine Property-Selbstpersistenz (Store-
        // Review Punkt 1). Der Nutzer bestätigt weiterhin selbst über das
        // normale Formular-"Übernehmen".
        if ($result['levelIDs']) {
            $this->UpdateFormField('Levels', 'values', json_encode($this->buildLevelsRows($result['levelIDs'])));
        }

        $summary = $entries
            ? $this->skeletonSummary($result, 'Angelegt')
            : 'ℹ️ Nichts angelegt — keine Etagen/Räume eingetragen.';
        $this->UpdateFormField('GenStatusLine', 'caption', $summary);

        return $summary;
    }

    private function skeletonSummary(array $result, string $verb): string
    {
        $lc = count($result['levelEntries']);
        $ln = count(array_filter($result['levelEntries'], fn($e) => $e['Status'] === 'neu'));
        $rc = count($result['roomEntries']);
        $rn = count(array_filter($result['roomEntries'], fn($e) => $e['Status'] === 'neu'));

        $parts = [];
        if ($lc > 0) {
            $parts[] = "$lc Etage(n) ($ln neu)";
        }
        if ($rc > 0) {
            $parts[] = "$rc Raum/Räume ($rn neu)";
        }

        return '✅ ' . $verb . ': ' . implode(', ', $parts) . '.';
    }

    private function planSkeleton(array $levelRows, array $roomRows, bool $dryRun): array
    {
        $root = $this->ReadPropertyInteger('RootCategoryID');
        if ($root <= 0 || !IPS_ObjectExists($root)) {
            return [
                'error'        => 'Keine Wurzelkategorie konfiguriert — zuerst oben im Formular festlegen.',
                'levelEntries' => [],
                'roomEntries'  => [],
                'levelIDs'     => [],
            ];
        }

        $levelEntries   = [];
        $levelIDByLabel = [];
        $levelIDs       = [];
        $pos            = 0;
        foreach ($levelRows as $row) {
            $label = trim((string) ($row['Label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $ident      = 'strukt_' . $this->slugify($label, 'etage');
            $existingID = $this->findChildByIdent($root, $ident);
            $catID      = $dryRun ? $existingID : $this->ensureCategory($root, $ident, $label, $pos);
            $status     = $existingID !== null ? 'vorhanden' : 'neu';

            $levelEntries[] = ['Pfad' => $label, 'Status' => $status, 'CategoryID' => $catID];
            $levelIDByLabel[mb_strtolower($label)] = $catID;
            if ($catID !== null) {
                $levelIDs[] = $catID;
            }
            $pos++;
        }

        $roomEntries = [];
        $posByParent = [];
        foreach ($roomRows as $row) {
            $label = trim((string) ($row['Label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $levelLabel = trim((string) ($row['LevelLabel'] ?? ''));
            $parentID   = $levelLabel !== '' ? ($levelIDByLabel[mb_strtolower($levelLabel)] ?? null) : $root;
            $pathPrefix = $levelLabel !== '' ? $levelLabel . ' / ' : '';

            if ($parentID === null) {
                // Zugehörige Etage existiert (noch) nicht (z. B. Vorschau vor
                // der ersten echten Anlage) — Raum kann noch nicht real
                // geprüft werden, gilt als "neu".
                $roomEntries[] = ['Pfad' => $pathPrefix . $label, 'Status' => 'neu', 'CategoryID' => null];
                continue;
            }

            $ident      = 'strukt_' . $this->slugify($label, 'raum');
            $existingID = $this->findChildByIdent($parentID, $ident);
            if ($dryRun) {
                $catID = $existingID;
            } else {
                $roomPos = $posByParent[$parentID] ?? 0;
                $catID   = $this->ensureCategory($parentID, $ident, $label, $roomPos);
                $posByParent[$parentID] = $roomPos + 1;
            }
            $status = $existingID !== null ? 'vorhanden' : 'neu';

            $roomEntries[] = ['Pfad' => $pathPrefix . $label, 'Status' => $status, 'CategoryID' => $catID];
        }

        return ['error' => null, 'levelEntries' => $levelEntries, 'roomEntries' => $roomEntries, 'levelIDs' => $levelIDs];
    }

    // Sucht ein direktes Kind mit gegebenem Ident (NICHT rekursiv — genau
    // die Suchtiefe, die für "hat DIESER Parent dieses Kind schon" richtig
    // ist). Bewusst manuell über IPS_GetChildrenIDs() statt
    // IPS_GetObjectIDByIdent() (dessen Verhalten bei Nichtfund uneindeutig
    // dokumentiert ist) — kein @-Unterdrücker vor einer IPS-API-Funktion,
    // deren Erfolg wir auswerten (SUITE.md Stolperstein 13).
    private function findChildByIdent(int $parentID, string $ident): ?int
    {
        if (!IPS_ObjectExists($parentID)) {
            return null;
        }
        foreach (IPS_GetChildrenIDs($parentID) as $cid) {
            if (IPS_GetObject($cid)['ObjectIdent'] === $ident) {
                return $cid;
            }
        }
        return null;
    }

    // Idempotent: legt nur an, wenn unter $parentID noch kein Kind mit
    // diesem Ident existiert; Name wird bei jedem Aufruf neu gesetzt
    // (Relabeling ohne Neuanlage), Position nur bei echter Neuanlage
    // (Vorbild InverterHub/MeterHub EnsureCategory()).
    private function ensureCategory(int $parentID, string $ident, string $name, int $position): int
    {
        $catID = $this->findChildByIdent($parentID, $ident);
        if ($catID === null) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $parentID);
            IPS_SetIdent($catID, $ident);
            IPS_SetPosition($catID, $position);
        }
        IPS_SetName($catID, $name);
        return $catID;
    }

    // Formularfeld-Listen können als Array ODER als JSON-String hereinkommen
    // (abhängig vom Aufrufkontext) — analog MigrationsHubs NormalizeFormList().
    private function normalizeFormList($rows): array
    {
        if (is_string($rows)) {
            $decoded = json_decode($rows, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($rows) ? $rows : [];
    }

    // -----------------------------------------------------------------
    // v0.3 Formular-Tresor — eigenständig, KEINE Berührung mit
    // buildStructure()/STRUKT_GetStructure(). Schützt NUR Formular-Klicks
    // in der Konsole, NICHT direkten Skript-/API-Zugriff auf die
    // Konfiguration (IPS_GetConfiguration()/IPS_SetProperty() gehen daran
    // komplett vorbei) — kein Ersatz für echte Zugriffskontrolle, nur eine
    // Hürde gegen Mitbenutzer, die nicht in dieser Instanz herumklicken
    // sollen (Dietmars Integrator/Kunde-Szenario, 30.08.2026).
    // -----------------------------------------------------------------

    public function UnlockVault(string $password): string
    {
        $hash = $this->ReadAttributeString('VaultPasswordHash');
        if ($hash === '' || !password_verify($password, $hash)) {
            return '⛔ Falsches Passwort.';
        }
        $this->WriteAttributeBoolean('VaultUnlocked', true);
        // Im gesperrten Zustand ist das Passwortfeld das EINZIGE Feld im
        // gesamten Formular — ReloadForm() ist hier unbedenklich (SUITE.md
        // Stolperstein 12, "Faustregel": nur riskant, wenn ANDERE Felder
        // gerade unbeachtete Eingaben haben könnten).
        $this->ReloadForm();
        return '✅ Entsperrt.';
    }

    public function LockVaultNow(): string
    {
        $this->WriteAttributeBoolean('VaultUnlocked', false);
        // Bewusst KEIN ReloadForm(): wird aus dem VOLLEN Formular heraus
        // geklickt, das z. B. in GenLevels/GenRooms gerade unbeachtete,
        // noch nicht übernommene Eingaben haben könnte (SUITE.md
        // Stolperstein 12, MeterHub-Kaveat gegen ReloadForm()). Die Sperre
        // gilt deshalb erst ab dem nächsten Öffnen dieses Formulars, nicht
        // rückwirkend auf die gerade offene Maske.
        return 'ℹ️ Gesperrt — wirkt beim nächsten Öffnen dieses Formulars.';
    }

    public function SetVaultPassword(string $newPassword): string
    {
        if ($newPassword === '') {
            $this->WriteAttributeString('VaultPasswordHash', '');
            $this->WriteAttributeBoolean('VaultUnlocked', false);
            return 'ℹ️ Formular-Tresor deaktiviert.';
        }
        $this->WriteAttributeString('VaultPasswordHash', password_hash($newPassword, PASSWORD_DEFAULT));
        $this->WriteAttributeBoolean('VaultUnlocked', true);
        return '✅ Passwort gesetzt.';
    }

    /**
     * STRUKT_CheckVaultAccess($id, $password): bool
     *
     * Zustandsloser Vertrag für ANDERE Module, die denselben Tresor für ihr
     * EIGENES Formular nutzen wollen (hinter function_exists()). Verändert
     * NICHTS an dieser Instanz — kein Unlock hier, nur ein Passwort-
     * Vergleich. Ein Modul kann das Formular eines anderen Moduls nicht von
     * außen sperren; jedes Modul baut seine eigene Sperr-UI nach diesem
     * Muster selbst nach (siehe GetConfigurationForm()/lockedForm() hier
     * als Referenz).
     *
     * WICHTIG, wie beim gesamten Tresor: kein Ersatz für echte
     * Zugriffskontrolle — nur ein Passwort, keine Nutzeridentität; jeder,
     * der es kennt, hat Zugriff. Passwort niemals im Klartext loggen.
     */
    public function CheckVaultAccess(string $password): bool
    {
        $hash = $this->ReadAttributeString('VaultPasswordHash');
        return $hash !== '' && password_verify($password, $hash);
    }

    private function lockedForm(): array
    {
        return [
            'elements' => [
                ['type' => 'Label', 'caption' => '🔒 Formular gesperrt — Passwort eingeben, um fortzufahren.'],
                ['type' => 'PasswordTextBox', 'name' => 'VaultPasswordInput', 'caption' => 'Passwort'],
                ['type' => 'Button', 'caption' => '🔓 Entsperren', 'onClick' => 'echo STRUKT_UnlockVault($id, $VaultPasswordInput);'],
            ],
        ];
    }

    private function vaultActivatePanel(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => '🔒 Formular-Tresor aktivieren',
            'expanded' => false,
            'items'    => [
                ['type' => 'Label', 'caption' => 'Versteckt dieses Formular hinter einem Passwort — schützt nur vor Klicks in der Konsole, NICHT vor direktem Skript-/API-Zugriff auf die Konfiguration. Sinnvoll z. B. wenn ein Kunde/Mitbenutzer ebenfalls Konsolen-Zugriff hat, aber nicht in dieser Instanz herumklicken soll.'],
                ['type' => 'PasswordTextBox', 'name' => 'VaultNewPassword', 'caption' => 'Neues Passwort'],
                ['type' => 'Button', 'caption' => 'Aktivieren', 'onClick' => 'echo STRUKT_SetVaultPassword($id, $VaultNewPassword);'],
            ],
        ];
    }

    private function vaultUnlockedPanel(): array
    {
        return [
            'type'     => 'ExpansionPanel',
            'caption'  => '🔒 Tresor',
            'expanded' => false,
            'items'    => [
                ['type' => 'Label', 'caption' => 'Formular ist entsperrt.'],
                ['type' => 'Button', 'caption' => 'Jetzt sperren', 'onClick' => 'echo STRUKT_LockVaultNow($id);'],
                ['type' => 'Label', 'caption' => 'Passwort ändern (leer lassen zum Deaktivieren):'],
                ['type' => 'PasswordTextBox', 'name' => 'VaultNewPassword', 'caption' => 'Neues Passwort'],
                ['type' => 'Button', 'caption' => 'Übernehmen', 'onClick' => 'echo STRUKT_SetVaultPassword($id, $VaultNewPassword);'],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // GetConfigurationForm — live berechnete Felder (Levels-Auswahl,
    // Statuszeile, Vorschau-Liste), Basisgerüst aus form.json.
    // -----------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $hasPassword = $this->ReadAttributeString('VaultPasswordHash') !== '';
        $unlocked    = $this->ReadAttributeBoolean('VaultUnlocked');

        if ($hasPassword && !$unlocked) {
            return json_encode($this->lockedForm());
        }

        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->injectVersionIntoDocPanel($form);
        $this->injectNewsVisibility($form);
        $this->injectForumHintVisibility($form);
        $this->injectLevelsValues($form);
        $this->injectStatusLine($form);
        $this->injectPreview($form);

        array_unshift($form['elements'], $hasPassword ? $this->vaultUnlockedPanel() : $this->vaultActivatePanel());

        return json_encode($form);
    }

    private function injectNewsVisibility(array &$form): void
    {
        $lib = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $cur = is_array($lib) ? ($lib['Version'] ?? '') : '';
        $ack = $this->ReadAttributeString('NewsAckVersion');
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'NewsPanel') {
                $el['visible'] = ($cur === '' || $ack !== $cur);
                break;
            }
        }
    }

    private function injectForumHintVisibility(array &$form): void
    {
        $dismissed = $this->ReadAttributeBoolean('ForumHintDismissed');
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'ForumHint') {
                $el['visible'] = !$dismissed;
                break;
            }
        }
    }

    private function injectVersionIntoDocPanel(array &$form): void
    {
        $lib    = @IPS_GetLibrary('{5E0A988D-0222-B254-88BE-61112640BBD5}');
        $verTxt = (is_array($lib) && isset($lib['Version']))
            ? 'ℹ️ StrukturHub Version ' . $lib['Version'] . ' (Build ' . ($lib['Build'] ?? '?') . ')'
            : 'ℹ️ StrukturHub';
        foreach ($form['elements'] as &$el) {
            if (($el['type'] ?? '') === 'ExpansionPanel' && str_contains($el['caption'] ?? '', 'Dokumentation')) {
                array_unshift($el['items'], ['type' => 'Label', 'caption' => $verTxt]);
                break;
            }
        }
    }

    // Direkte Kinder der Wurzelkategorie live einsammeln, bisherige IsLevel-
    // Auswahl (per CategoryID) aus der gespeicherten Property übernehmen,
    // Name/Anzahl-Spalten aber immer frisch anzeigen (Store-Review Punkt 3 —
    // berechnete Anzeigespalten nie aus der Konfiguration nachladen).
    // $forceLevelIDs erzwingt IsLevel=true für bestimmte categoryIDs, unabhängig
    // von der gespeicherten Auswahl — genutzt vom v0.2-Gerüst-Generator, um
    // frisch angelegte Etagen-Kategorien direkt vorzuhäkeln.
    private function buildLevelsRows(array $forceLevelIDs = []): array
    {
        $root  = $this->ReadPropertyInteger('RootCategoryID');
        $prev  = $this->levelFlags();
        $force = array_flip($forceLevelIDs);

        $rows = [];
        if ($root > 0 && IPS_ObjectExists($root)) {
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (!$this->isCategory($cid)) {
                    continue;
                }
                $rows[] = [
                    'IsLevel'    => isset($force[$cid]) ? true : ($prev[$cid] ?? false),
                    'CategoryID' => $cid,
                    'Name'       => IPS_GetName($cid),
                    'Kinder'     => count(IPS_GetChildrenIDs($cid)),
                ];
            }
        }
        return $rows;
    }

    private function injectLevelsValues(array &$form): void
    {
        $rows = $this->buildLevelsRows();
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'Levels') {
                $el['values'] = $rows;
                break;
            }
        }
    }

    private function injectStatusLine(array &$form): void
    {
        $structure = $this->buildStructure();
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'StatusLine') {
                $el['caption'] = $this->statusLineText($structure);
                break;
            }
        }
    }

    private function injectPreview(array &$form): void
    {
        $structure = $this->buildStructure();
        foreach ($form['elements'] as &$el) {
            if (($el['name'] ?? '') === 'StructurePreview') {
                $el['values'] = $this->previewRows($structure);
                break;
            }
        }
    }

    private function statusLineText(array $structure): string
    {
        $ts = $this->ReadAttributeInteger('LastRefreshTs');
        if ($this->ReadPropertyInteger('RootCategoryID') <= 0) {
            return 'ℹ️ Noch keine Wurzelkategorie ausgewählt.';
        }
        if ($ts === 0) {
            return 'ℹ️ Noch nicht eingelesen — Button „Struktur jetzt einlesen“ nutzen.';
        }

        $roomCount   = count($structure['rooms']);
        $levelCount  = count($structure['levels']);
        $deviceCount = array_sum(array_map(fn($r) => count($r['deviceInstanceIDs']), $structure['rooms']));
        $icon        = $roomCount > 0 ? '✅' : '⚠️';
        $levelTxt    = $levelCount > 0 ? "$levelCount Etage(n), " : '';

        return "$icon {$levelTxt}{$roomCount} Raum/Räume, {$deviceCount} Geräte gefunden (zuletzt " . date('H:i:s', $ts) . ' Uhr).';
    }

    private function previewRows(array $structure): array
    {
        $rows = [];
        foreach ($structure['rooms'] as $room) {
            $rows[] = [
                'Raum'     => $room['label'],
                'Etage'    => $room['level'] !== '' ? $this->levelLabel($structure, $room['level']) : '—',
                'Typ'      => $room['roomType'] ?? '—',
                'Nummer'   => $room['number'] ?? '—',
                'Reihe'    => $room['order'],
                'Geraete'  => count($room['deviceInstanceIDs']),
            ];
        }
        return $rows;
    }

    private function levelLabel(array $structure, string $key): string
    {
        foreach ($structure['levels'] as $level) {
            if ($level['key'] === $key) {
                return $level['label'];
            }
        }
        return $key;
    }

    // -----------------------------------------------------------------
    // Struktur-Aufbau
    // -----------------------------------------------------------------

    private function buildStructure(): array
    {
        $root = $this->ReadPropertyInteger('RootCategoryID');
        if ($root <= 0 || !IPS_ObjectExists($root)) {
            return [
                'contractVersion'    => '1.1',
                'instanceID'         => $this->InstanceID,
                'structureChangedAt' => 0,
                'levels'             => [],
                'rooms'              => [],
            ];
        }

        $levelFlags = $this->levelFlags();
        $levelCatIDs = array_filter(
            IPS_GetChildrenIDs($root),
            fn($cid) => $this->isCategory($cid) && !empty($levelFlags[$cid])
        );

        // EIN gemeinsamer Key-Namensraum über levels UND rooms hinweg,
        // persistiert je categoryID (MeterHub-Anforderung 28.08.2026) —
        // siehe resolveKey().
        $registry = $this->loadKeyRegistry();

        $levels = [];
        $rooms  = [];

        if (empty($levelCatIDs)) {
            // Keine Etagen-Ebene bestätigt: Kinder der Wurzelkategorie sind
            // direkt Räume.
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (!$this->isCategory($cid)) {
                    continue;
                }
                $rooms[] = $this->buildRoom($cid, '', $registry);
            }
        } else {
            foreach ($levelCatIDs as $lcid) {
                $key = $this->resolveKey($registry, $lcid, IPS_GetName($lcid));
                $levelLabel = IPS_GetName($lcid);
                $levels[] = [
                    'key'        => $key,
                    'label'      => $levelLabel,
                    'categoryID' => $lcid,
                    'order'      => $this->objectOrder($lcid),
                    'number'     => $this->extractNumber($levelLabel),
                ];
                foreach (IPS_GetChildrenIDs($lcid) as $rcid) {
                    if (!$this->isCategory($rcid)) {
                        continue;
                    }
                    $rooms[] = $this->buildRoom($rcid, $key, $registry);
                }
            }

            // Räume, die der v0.2-Gerüst-Generator BEWUSST ohne Etagen-
            // Zuordnung direkt unter der Wurzel angelegt hat (auch wenn
            // andere Räume derselben Struktur Etagen haben) — Live-Fund
            // 29.08.2026: wurden bislang komplett übersehen, weil dieser
            // Zweig nur die Kinder JE Etage durchsucht. Erkennbar am
            // strukt_-Ident-Präfix des Generators, NICHT einfach jede
            // unflagged Kategorie — sonst kämen hier wieder Gewerke-
            // Kategorien (Energie/Heizung/Test) als Räume rein, genau das
            // Problem, das die Etagen-Häkchen lösen sollen.
            $levelCatIDSet = array_flip($levelCatIDs);
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (isset($levelCatIDSet[$cid]) || !$this->isCategory($cid)) {
                    continue;
                }
                if (strpos(IPS_GetObject($cid)['ObjectIdent'], 'strukt_') === 0) {
                    $rooms[] = $this->buildRoom($cid, '', $registry);
                }
            }
        }

        $this->pruneAndSaveKeyRegistry($registry);

        return [
            'contractVersion'    => '1.1',
            'instanceID'         => $this->InstanceID,
            'structureChangedAt' => $this->touchChangeTimestamp($levels, $rooms),
            'levels'             => $levels,
            'rooms'              => $rooms,
        ];
    }

    private function buildRoom(int $categoryID, string $levelKey, array &$registry): array
    {
        $label = IPS_GetName($categoryID);
        return [
            'key'               => $this->resolveKey($registry, $categoryID, $label),
            'label'             => $label,
            'level'             => $levelKey,
            'categoryID'        => $categoryID,
            'order'             => $this->objectOrder($categoryID),
            'roomType'          => $this->inferRoomType($label),
            'number'            => $this->extractNumber($label),
            'deviceInstanceIDs' => $this->resolveRoomDevices($categoryID),
        ];
    }

    // -----------------------------------------------------------------
    // Persistente Keys (categoryID -> key), Änderungserkennung
    // -----------------------------------------------------------------

    private function loadKeyRegistry(): array
    {
        $data = json_decode($this->ReadAttributeString('KeyRegistry'), true);
        return is_array($data) ? $data : [];
    }

    // Liefert den persistierten Key einer categoryID; erzeugt und persistiert
    // beim ERSTEN Erfassen einen neuen (aus dem damaligen Label abgeleitet,
    // eindeutig gegen ALLE bereits vergebenen Keys — levels UND rooms teilen
    // sich einen Namensraum). Eine spätere Umbenennung der Kategorie ändert
    // den bereits vergebenen Key NICHT mehr.
    private function resolveKey(array &$registry, int $categoryID, string $label): string
    {
        if (isset($registry[$categoryID])) {
            return $registry[$categoryID];
        }
        $used = array_values($registry);
        $key  = $this->uniqueKey($label, $used);
        $registry[$categoryID] = $key;
        return $key;
    }

    // Entfernt nur Einträge, deren Kategorie in Symcon tatsächlich gelöscht
    // wurde — NICHT, wenn eine Kategorie nur gerade nicht Teil der aktuell
    // gewählten Struktur ist (z. B. Etagen-Häkchen kurzzeitig entfernt), damit
    // der Key bei Rückkehr stabil bleibt.
    private function pruneAndSaveKeyRegistry(array $registry): void
    {
        foreach (array_keys($registry) as $cid) {
            if (!IPS_ObjectExists((int) $cid)) {
                unset($registry[$cid]);
            }
        }
        $this->WriteAttributeString('KeyRegistry', json_encode($registry));
    }

    // Aktualisiert structureChangedAt nur, wenn sich levels/rooms inhaltlich
    // seit dem letzten Aufruf wirklich geändert haben (Hash-Vergleich) —
    // spart Konsumenten das Diffen des kompletten JSON (MeterHub/EMS-Wunsch
    // 28.08.2026).
    private function touchChangeTimestamp(array $levels, array $rooms): int
    {
        $hash = md5(json_encode(['levels' => $levels, 'rooms' => $rooms], JSON_UNESCAPED_UNICODE));
        if ($hash !== $this->ReadAttributeString('LastStructureHash')) {
            $this->WriteAttributeString('LastStructureHash', $hash);
            $this->WriteAttributeInteger('StructureChangedAt', time());
        }
        return $this->ReadAttributeInteger('StructureChangedAt');
    }

    // Vom Nutzer im Objektbaum gesetzte Sortierposition (Konsolen-Drag&Drop) —
    // robuster für Konsumenten als Array-Reihenfolge oder alphabetisches
    // Sortieren nach key/label (Dashboard-Wunsch, 28.08.2026: sonst würde
    // z. B. "Dachgeschoss" alphabetisch vor "Erdgeschoss" einsortieren).
    private function objectOrder(int $id): int
    {
        // IPS_GetObject() liefert den Schlüssel "ObjectPosition", NICHT
        // "Position" — Live-Fund 28.08.2026 beim v0.2-Testlauf: order war
        // seit Einführung immer 0, weil der falsche Array-Schlüssel gelesen
        // wurde ("Position" existiert im Rückgabe-Array schlicht nicht, "??"
        // fing das lautlos ab, ohne Fehler oder Warnung).
        return (int) (IPS_GetObject($id)['ObjectPosition'] ?? 0);
    }

    // Heuristische Best-Effort-Ableitung einer Geschoss-/Raumnummer aus dem
    // Namen — NUR eine Anzeige-/Ableitungs-Hilfe (z. B. für Konsumenten, die
    // Geräte-Idents aus der Raumnummer bilden wollen, Dietmar-Wunsch
    // 28.08.2026), kein garantierter Fachwert. Nummer kann vor ODER nach dem
    // Namen stehen ("101 Büro" / "Büro 101"), mit oder ohne Trenner
    // (Leerzeichen/Punkt/Bindestrich) — funktioniert unabhängig davon, ob die
    // Kategorie über den v0.2-Generator oder manuell entstanden ist. Rein
    // numerische Namen ("101") zuerst behandeln, sonst würde die
    // Nachgestellt-Regel sie fälschlich in z. B. "10"+"1" zerlegen.
    private function extractNumber(string $label): ?string
    {
        $label = trim($label);
        if ($label === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $label)) {
            return $label;
        }
        // Nachgestellte Nummer zuerst prüfen (Standard-Konvention "Name 101").
        if (preg_match('/(\d+)$/', $label, $m)) {
            return $m[1];
        }
        // Vorangestellte Nummer ("101 Name").
        if (preg_match('/^(\d+)/', $label, $m)) {
            return $m[1];
        }
        return null;
    }

    // Heuristische Best-Effort-Ableitung des Raumtyps aus dem Kategorienamen,
    // NUR für Anzeige-Zwecke (z. B. Icon-Auswahl, Dashboard-Wunsch
    // 28.08.2026) — kein Fachwert, keine Garantie. Token-Abgleich (nicht
    // Substring) gegen ein festes deutsches Vokabular, damit z. B. "wc" nicht
    // versehentlich mitten in einem anderen Wort matcht. Unbekannt -> null,
    // niemals raten/erfinden.
    private function inferRoomType(string $label): ?string
    {
        // Deckt bewusst sowohl privaten als auch gewerblichen Sprachgebrauch ab
        // (Grundregel "keine eigene Anlage als Norm" — nicht nur Wohnhaus-
        // Vokabular). Weiterhin rein heuristisch/optional, siehe Docblock.
        static $synonyms = [
            'kueche'           => ['kueche'],
            'bad'              => ['bad', 'badezimmer', 'dusche'],
            'wc'               => ['wc', 'toilette', 'sanitaer'],
            'wohnzimmer'       => ['wohnzimmer', 'wohnen'],
            'schlafzimmer'     => ['schlafzimmer', 'schlafen'],
            'kinderzimmer'     => ['kinderzimmer'],
            'buero'            => ['buero', 'office', 'arbeitszimmer'],
            'besprechungsraum' => ['besprechungsraum', 'konferenzraum', 'meetingraum', 'seminarraum', 'schulungsraum'],
            'empfang'          => ['empfang', 'rezeption', 'lobby', 'eingang'],
            'esszimmer'        => ['esszimmer', 'essen', 'kantine', 'pausenraum', 'personalraum'],
            'flur'             => ['flur', 'diele', 'windfang', 'gang', 'korridor'],
            'keller'           => ['keller'],
            'dachboden'        => ['dachboden', 'spitzboden'],
            'hwr'              => ['hwr', 'hauswirtschaftsraum', 'hauswirtschaft'],
            'vorrat'           => ['vorrat', 'vorratsraum', 'speisekammer'],
            'lager'            => ['lager', 'lagerraum', 'lagerhalle', 'archiv'],
            'garage'           => ['garage', 'tiefgarage', 'parkhaus'],
            'carport'          => ['carport'],
            'schuppen'         => ['schuppen', 'geraeteschuppen'],
            'terrasse'         => ['terrasse'],
            'balkon'           => ['balkon'],
            'garten'           => ['garten'],
            'treppe'           => ['treppe', 'treppenhaus'],
            'technik'          => ['technik', 'technikraum', 'hausanschlussraum', 'serverraum', 'edv'],
            'sauna'            => ['sauna'],
            'pool'             => ['pool', 'schwimmbad'],
            'gaestezimmer'     => ['gaestezimmer', 'gaeste'],
            'abstellraum'      => ['abstellraum', 'abstellkammer', 'nebenraum'],
            'umkleide'         => ['umkleide', 'umkleideraum'],
            'werkstatt'        => ['werkstatt', 'produktionshalle', 'fertigung'],
            'versand'          => ['versand', 'wareneingang', 'warenausgang'],
            'labor'            => ['labor'],
        ];

        $slug   = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $tokens = preg_split('/[^a-z0-9]+/', $slug, -1, PREG_SPLIT_NO_EMPTY);

        foreach ($synonyms as $type => $words) {
            if (array_intersect($tokens, $words)) {
                return $type;
            }
        }
        return null;
    }

    // Sammelt die Geräte-Instanzen eines Raums: direkte Instanz-Kinder, Links
    // auf Instanzen, sowie Variablen (direkt oder per Link) — dort wird die
    // ELTERNINSTANZ der Variable aufgenommen (MeterHub-Fund 28.08.2026: ein
    // "Licht"-Link zeigt bei Dietmar direkt auf eine Schalter-Variable eines
    // Aktors, nicht auf dessen Instanz; ohne Auflösung ginge der Messpunkt
    // still verloren). Dedupliziert (doppelte Links auf dieselbe Instanz,
    // live an Dietmars Anlage beobachtet: Geschirrspüler 2x in der Küche
    // verlinkt) und filtert tote/namenlose Links (Ziel existiert nicht mehr
    // — IPS zeigt die dann als "Unnamed Object") sowie Variablen ohne
    // Instanz-Elternteil (kein sinnvolles Ziel, wird ignoriert).
    private function resolveRoomDevices(int $categoryID): array
    {
        $ids = [];
        foreach (IPS_GetChildrenIDs($categoryID) as $cid) {
            $obj = IPS_GetObject($cid);
            switch ($obj['ObjectType']) {
                case OBJECTTYPE_INSTANCE:
                    $ids[] = $cid;
                    break;
                case OBJECTTYPE_VARIABLE:
                    $this->addInstanceOfVariable($cid, $ids);
                    break;
                case OBJECTTYPE_LINK:
                    $target = IPS_GetLink($cid)['TargetID'];
                    if ($target <= 0 || !IPS_ObjectExists($target)) {
                        break; // toter/namenloser Link, bewusst übersprungen.
                    }
                    $targetObj = IPS_GetObject($target);
                    if ($targetObj['ObjectType'] === OBJECTTYPE_INSTANCE) {
                        $ids[] = $target;
                    } elseif ($targetObj['ObjectType'] === OBJECTTYPE_VARIABLE) {
                        $this->addInstanceOfVariable($target, $ids);
                    }
                    break;
            }
        }
        return array_values(array_unique($ids));
    }

    private function addInstanceOfVariable(int $variableID, array &$ids): void
    {
        $parent = IPS_GetParent($variableID);
        if ($parent > 0 && IPS_ObjectExists($parent) && IPS_GetObject($parent)['ObjectType'] === OBJECTTYPE_INSTANCE) {
            $ids[] = $parent;
        }
    }

    private function isCategory(int $id): bool
    {
        return IPS_ObjectExists($id) && IPS_GetObject($id)['ObjectType'] === OBJECTTYPE_CATEGORY;
    }

    // Liest die zuletzt gespeicherte Levels-Property als [CategoryID => IsLevel]-Map.
    private function levelFlags(): array
    {
        $rows = json_decode($this->ReadPropertyString('Levels'), true);
        if (!is_array($rows)) {
            return [];
        }
        $map = [];
        foreach ($rows as $row) {
            if (isset($row['CategoryID'])) {
                $map[(int) $row['CategoryID']] = !empty($row['IsLevel']);
            }
        }
        return $map;
    }

    // Deutscher Slug aus einem Kategorienamen (Umlaute umschreiben, Rest auf
    // [a-z0-9] reduzieren), innerhalb von $used eindeutig gemacht.
    private function uniqueKey(string $label, array &$used): string
    {
        $slug = $this->slugify($label, 'raum');

        $key = $slug;
        $n   = 2;
        while (in_array($key, $used, true)) {
            $key = $slug . '_' . $n;
            $n++;
        }
        $used[] = $key;

        return $key;
    }

    // Deutscher Slug (Umlaute umschreiben, Rest auf [a-z0-9] reduzieren) —
    // genutzt für den v0.1-Lesevertrag-Key (uniqueKey()) UND für die v0.2-
    // Gerüst-Generator-Idents (planSkeleton()).
    private function slugify(string $label, string $fallback): string
    {
        $slug = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        return $slug === '' ? $fallback : $slug;
    }
}
