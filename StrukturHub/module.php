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
     *   "contractVersion": "1.0",
     *   "instanceID": 12345,
     *   "structureChangedAt": 1787900000,
     *   "levels": [ {"key":"eg","label":"Erdgeschoss","categoryID":23050,"order":0}, ... ],
     *   "rooms":  [ {"key":"kueche","label":"Küche","level":"eg","categoryID":51304,
     *                "order":0,"roomType":"kueche",
     *                "deviceInstanceIDs":[30131,27898,48294]}, ... ]
     * }
     *
     * - "levels" ist leer, wenn keine Etagen-Ebene bestätigt wurde — "rooms[].level"
     *   ist dann ebenfalls "" (Räume liegen direkt unter der Wurzelkategorie).
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
        $this->UpdateFormField('StructurePreview', 'values', $this->previewRows($structure));

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
    // GetConfigurationForm — live berechnete Felder (Levels-Auswahl,
    // Statuszeile, Vorschau-Liste), Basisgerüst aus form.json.
    // -----------------------------------------------------------------

    public function GetConfigurationForm()
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);

        $this->injectVersionIntoDocPanel($form);
        $this->injectNewsVisibility($form);
        $this->injectForumHintVisibility($form);
        $this->injectLevelsValues($form);
        $this->injectStatusLine($form);
        $this->injectPreview($form);

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
    private function injectLevelsValues(array &$form): void
    {
        $root = $this->ReadPropertyInteger('RootCategoryID');
        $prev = $this->levelFlags();

        $rows = [];
        if ($root > 0 && IPS_ObjectExists($root)) {
            foreach (IPS_GetChildrenIDs($root) as $cid) {
                if (!$this->isCategory($cid)) {
                    continue;
                }
                $rows[] = [
                    'IsLevel'    => $prev[$cid] ?? false,
                    'CategoryID' => $cid,
                    'Name'       => IPS_GetName($cid),
                    'Kinder'     => count(IPS_GetChildrenIDs($cid)),
                ];
            }
        }

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
                'contractVersion'    => '1.0',
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
                $levels[] = [
                    'key'        => $key,
                    'label'      => IPS_GetName($lcid),
                    'categoryID' => $lcid,
                    'order'      => $this->objectOrder($lcid),
                ];
                foreach (IPS_GetChildrenIDs($lcid) as $rcid) {
                    if (!$this->isCategory($rcid)) {
                        continue;
                    }
                    $rooms[] = $this->buildRoom($rcid, $key, $registry);
                }
            }
        }

        $this->pruneAndSaveKeyRegistry($registry);

        return [
            'contractVersion'    => '1.0',
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
        return (int) (IPS_GetObject($id)['Position'] ?? 0);
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
        $slug = strtr(mb_strtolower($label), ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');
        if ($slug === '') {
            $slug = 'raum';
        }

        $key = $slug;
        $n   = 2;
        while (in_array($key, $used, true)) {
            $key = $slug . '_' . $n;
            $n++;
        }
        $used[] = $key;

        return $key;
    }
}
