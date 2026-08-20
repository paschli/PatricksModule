<?php

// Modul schaltet eine ausgewählte Variable in IP-Symcon.
// Zeigt einen Schalter in der Visualisierung.
// Reagiert auf externe Änderungen der Ziel-Variable (bidirektionale Synchronisation).
// Optional: Bei externem Einschalten des Ziels wird der Countdown-Timer ebenfalls
// aktiviert, auch wenn er zuvor nicht lief ("Ziel überwachen"-Schalter in der App).
// Optionaler Countdown-Timer mit Eingabe in Stunden/Minuten/Sekunden (in eigenem Ordner).
// Konfigurierbare Zeitschalter (manuell oder Solar) über die App.

class AutSw3 extends IPSModule {

    public function Create() {
        parent::Create();

        $this->RegisterPropertyInteger('TargetID', 0);
        $this->RegisterPropertyBoolean('CountdownEnabled', false);
        $this->RegisterPropertyString('TimerList', '[]');
        $this->RegisterPropertyInteger('LocationID', 0);

        $this->RegisterAttributeInteger('RegisteredTargetID', 0);
        $this->RegisterAttributeInteger('CountdownTimerID', 0); // Cleanup alter Versionen
        $this->RegisterAttributeInteger('RegisteredSunriseVarID', 0);
        $this->RegisterAttributeInteger('TimerCount', 0);
        $this->RegisterAttributeInteger('CountdownEndTime', 0);

        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        $this->RegisterVariableBoolean('CDActive', 'Countdown aktiv', '~Switch', 1);
        IPS_SetIcon($this->GetIDForIdent('CDActive'), 'Clock');

        $this->RegisterVariableBoolean('WatchTarget', 'Ziel überwachen', '~Switch', 2);
        IPS_SetIcon($this->GetIDForIdent('WatchTarget'), 'Eye');

        $this->RegisterVariableBoolean('TimerActive', 'Zeitschalter', '~Switch', 4);
        IPS_SetIcon($this->GetIDForIdent('TimerActive'), 'Calendar');

        // Migration: alte Integer-Countdown-Variable löschen falls vorhanden
        $oldID = @IPS_GetObjectIDByIdent('Countdown', $this->InstanceID);
        if ($oldID && IPS_VariableExists($oldID) && IPS_GetVariable($oldID)['VariableType'] !== 3) {
            IPS_DeleteVariable($oldID);
        }
        $this->RegisterVariableString('Countdown', 'Verbleibend', '', 6);

        // Timer registrieren – NUR in Create() erlaubt
        $this->RegisterTimer('CountdownTimer',     0, 'AutSw3_CountdownTick('     . $this->InstanceID . ');');
        $this->RegisterTimer('ScheduleTimer',      0, 'AutSw3_ScheduleTick('      . $this->InstanceID . ');');
        $this->RegisterTimer('ProfileUpdateTimer', 0, 'AutSw3_ProfileUpdateTick(' . $this->InstanceID . ');');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Alten manuell erstellten Timer löschen (von vorheriger Modulversion)
        $legacyTimerID = $this->ReadAttributeInteger('CountdownTimerID');
        if ($legacyTimerID != 0 && IPS_EventExists($legacyTimerID)) {
            IPS_DeleteEvent($legacyTimerID);
        }
        $this->WriteAttributeInteger('CountdownTimerID', 0);

        $this->ensureProfiles();

        $this->EnableAction('State');
        $this->EnableAction('CDActive');
        $this->EnableAction('WatchTarget');
        $this->EnableAction('TimerActive');

        // Gemeinsames Aktions-Script für Timer-Sub-Variablen und Countdown-Kategorie
        $scriptID = $this->ensureActionScript();

        // Countdown-Kategorie mit Stunden/Minuten/Sekunden erstellen
        $this->ensureCountdownTimeCategory($scriptID);

        // Migration: direkte CDHours/CDMinutes/CDSeconds → Kategorie
        $this->migrateCDVarsToCategory();

        // Migration: CDDuration (alte Version) löschen falls vorhanden
        $this->migrateCDToSingleVar();

        // Timer-Elternkategorie erstellen und bestehende TimerCat_N hineinverschieben
        $timersCatID = $this->ensureTimersCat($scriptID);
        $this->migrateTimerCatsToTimersCat($timersCatID);

        // Ziel-Variable registrieren
        $oldTargetID = $this->ReadAttributeInteger('RegisteredTargetID');
        if ($oldTargetID != 0) {
            $this->UnregisterMessage($oldTargetID, VM_UPDATE);
        }
        $targetID = $this->ReadPropertyInteger('TargetID');
        if ($targetID != 0 && IPS_VariableExists($targetID)) {
            $this->RegisterMessage($targetID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredTargetID', $targetID);
        } else {
            $this->WriteAttributeInteger('RegisteredTargetID', 0);
        }

        // Countdown Sichtbarkeit
        $featureEnabled = $this->ReadPropertyBoolean('CountdownEnabled');
        IPS_SetHidden($this->GetIDForIdent('CDActive'), !$featureEnabled);
        IPS_SetHidden($this->GetIDForIdent('WatchTarget'), !$featureEnabled);
        $showCD = $featureEnabled && $this->GetValue('CDActive');
        $cdCatID = @IPS_GetObjectIDByIdent('CountdownTimeCat', $this->InstanceID);
        if ($cdCatID) {
            IPS_SetHidden($cdCatID, !$showCD);
        }
        if (!$showCD) {
            $this->timerStop();
        }
        // Restzeit nur ausblenden, wenn tatsaechlich kein Countdown laeuft.
        // Sonst verschwindet die Anzeige bei jedem ApplyChanges dauerhaft,
        // weil sie nur in timerStart() wieder eingeblendet wird.
        IPS_SetHidden(
            $this->GetIDForIdent('Countdown'),
            $this->ReadAttributeInteger('CountdownEndTime') == 0
        );

        // Timer-Kategorie Sichtbarkeit
        IPS_SetHidden($timersCatID, !$this->GetValue('TimerActive'));

        // Zeitschalter-Kategorien erstellen/aktualisieren
        $this->applyTimers($scriptID, $timersCatID);

        // Location-Subscription für Solar-Modi
        $this->updateLocationSubscription();

        // Profil-Beschriftungen mit aktuellen Solar-Zeiten aktualisieren
        $this->updateTimeModeProfile();
        $this->scheduleProfileUpdate();

        // Nächsten Zeitschalter planen
        $this->scheduleNext();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) {
            return;
        }
        if ($SenderID == $this->ReadPropertyInteger('TargetID')) {
            $this->TargetChanged();
            return;
        }
        $sunriseVarID = $this->ReadAttributeInteger('RegisteredSunriseVarID');
        if ($sunriseVarID != 0 && $SenderID == $sunriseVarID) {
            $this->SendDebug('Schedule', 'Solarzeit aktualisiert', 0);
            $this->updateTimeModeProfile();
            $this->updateAllTimerCategoryNames();
            $this->scheduleNext();
        }
    }

    public function RequestAction($ident, $value) {
        if ($ident === 'State') {
            $this->SetSwitch((bool)$value);
        } elseif ($ident === 'CDActive') {
            $this->SetValue('CDActive', (bool)$value);
            $cdCatID = @IPS_GetObjectIDByIdent('CountdownTimeCat', $this->InstanceID);
            if ($cdCatID) {
                IPS_SetHidden($cdCatID, !$value);
            }
            if ($value && $this->GetValue('State')) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                }
            } else {
                $this->timerStop();
            }
        } elseif ($ident === 'WatchTarget') {
            $this->SetValue('WatchTarget', (bool)$value);
        } elseif (in_array($ident, ['CDHours', 'CDMinutes', 'CDSeconds'])) {
            $this->setCDTimeVar($ident, (int)$value);
            if ($this->GetValue('State') && $this->isCountdownActive()) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                } else {
                    $this->timerStop();
                }
            }
        } elseif ($ident === 'AddTimer') {
            $name = trim((string)$value);
            if ($name === '') {
                return;
            }
            $timers = json_decode($this->ReadPropertyString('TimerList'), true);
            if (!is_array($timers)) {
                $timers = [];
            }
            $timers[] = ['TimerName' => $name];
            IPS_SetProperty($this->InstanceID, 'TimerList', json_encode($timers));
            IPS_ApplyChanges($this->InstanceID);
            // Eingabefeld zurücksetzen
            $timersCatID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
            if ($timersCatID) {
                $addVarID = @IPS_GetObjectIDByIdent('AddTimer', $timersCatID);
                if ($addVarID) {
                    SetValueString($addVarID, '');
                }
            }
        } elseif (preg_match('/^TDelete_(\d+)$/', $ident, $m)) {
            if (!(bool)$value) {
                return;
            }
            $index  = (int)$m[1];
            $timers = json_decode($this->ReadPropertyString('TimerList'), true);
            if (!is_array($timers) || !isset($timers[$index])) {
                return;
            }
            // Konfigurationen aller verbleibenden Timer sichern
            $saved = [];
            for ($i = 0; $i < count($timers); $i++) {
                if ($i !== $index) {
                    $saved[] = $this->readTimerConfig($i);
                }
            }
            array_splice($timers, $index, 1);
            IPS_SetProperty($this->InstanceID, 'TimerList', json_encode($timers));
            IPS_ApplyChanges($this->InstanceID); // baut Kategorien neu auf (Werte = 0)
            // Gesicherte Konfigurationen in die neuen Indizes schreiben
            foreach ($saved as $newIndex => $config) {
                $this->writeTimerConfig($newIndex, $config);
            }
        } elseif ($ident === 'TimerActive') {
            $this->SetValue('TimerActive', (bool)$value);
            $timersCatID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
            if ($timersCatID) {
                IPS_SetHidden($timersCatID, !$value);
            }
            if ($value) {
                $this->scheduleNext();
            } else {
                $this->SetTimerInterval('ScheduleTimer', 0);
            }
        } elseif (preg_match('/^T(Active|State|Mode|Time|Offset)_(\d+)$/', $ident, $m)) {
            $index = (int)$m[2];
            $varID = $this->getTimerVarID($ident, $index);
            if ($varID) {
                if ($m[1] === 'Active' || $m[1] === 'State') {
                    SetValueBoolean($varID, (bool)$value);
                } else {
                    SetValueInteger($varID, (int)$value);
                }
            }
            if ($m[1] === 'Mode') {
                $tTimeID   = $this->getTimerVarID('TTime_'   . $index, $index);
                $tOffsetID = $this->getTimerVarID('TOffset_' . $index, $index);
                if ($tTimeID)   { IPS_SetHidden($tTimeID,   (int)$value !== 0); }
                if ($tOffsetID) { IPS_SetHidden($tOffsetID, (int)$value === 0); }
            }
            $this->updateTimerCategoryName($index);
            if (in_array($m[1], ['Active', 'Mode', 'Time', 'Offset'])) {
                $this->sortTimerCategories();
            }
            if ($m[1] !== 'State') {
                $this->scheduleNext();
            }
        }
    }

    public function SetSwitch(bool $state) {
        if (!IPS_SemaphoreEnter('AutSw3_' . $this->InstanceID, 1000)) {
            $this->SendDebug('SetSwitch', 'Semaphor Timeout', 0);
            return;
        }
        try {
            $targetID = $this->ReadPropertyInteger('TargetID');
            $this->SendDebug('SetSwitch', 'Schalte auf ' . ($state ? 'EIN' : 'AUS') . ', TargetID=' . $targetID, 0);

            if ($targetID != 0 && IPS_VariableExists($targetID)) {
                try {
                    RequestAction($targetID, $state);
                    $this->SendDebug('SetSwitch', 'RequestAction erfolgreich', 0);
                } catch (Exception $e) {
                    $this->SendDebug('SetSwitch', 'RequestAction Fehler: ' . $e->getMessage(), 0);
                }
            }

            $this->SetValue('State', $state);

            if ($state && $this->isCountdownActive()) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                }
            } else {
                $this->timerStop();
            }
        } finally {
            IPS_SemaphoreLeave('AutSw3_' . $this->InstanceID);
        }
    }

    public function Toggle() {
        $this->SetSwitch(!$this->GetValue('State'));
    }

    public function SetOn() {
        $this->SetSwitch(true);
    }

    public function SetOff() {
        $this->SetSwitch(false);
    }

    // Schaltet ein und startet einen Countdown über die übergebene Laufzeit (in Minuten),
    // unabhängig davon, ob die Countdown-Funktion in der Konfiguration aktiviert ist.
    public function Set_Timer(int $Laufzeit) {
        if ($Laufzeit > 0) {
            $seconds = $Laufzeit * 60;
            $this->setCDTimeVar('CDHours',   intdiv($seconds, 3600));
            $this->setCDTimeVar('CDMinutes', intdiv($seconds % 3600, 60));
            $this->setCDTimeVar('CDSeconds', $seconds % 60);
            $this->SetValue('CDActive', true);
            $this->SetSwitch(true);
            $this->timerStart($seconds);
        } else {
            $this->SetSwitch(false);
        }
    }

    public function TargetChanged() {
        $targetID = $this->ReadPropertyInteger('TargetID');
        if ($targetID == 0) {
            return;
        }
        $targetState  = GetValueBoolean($targetID);
        $currentState = $this->GetValue('State');
        if ($targetState === $currentState) {
            return;
        }
        $this->SendDebug('TargetChanged', 'Ziel geändert auf: ' . ($targetState ? 'EIN' : 'AUS'), 0);
        $this->SetValue('State', $targetState);
        if (!$targetState) {
            $this->timerStop();
            return;
        }
        if ($this->isCountdownActive()) {
            // Countdown lief bereits (CDActive an) → für den neuen Einschaltvorgang neu starten
            $total = $this->getCDSeconds();
            if ($total > 0) {
                $this->timerStart($total);
            }
        } elseif ($this->isWatchTargetActive()) {
            // Ziel wurde extern eingeschaltet, ohne dass CDActive aktiv war → Countdown jetzt aktivieren
            $total = $this->getCDSeconds();
            if ($total > 0) {
                $this->SendDebug('TargetChanged', 'Extern eingeschaltet – Countdown wird aktiviert', 0);
                $this->SetValue('CDActive', true);
                $this->timerStart($total);
            }
        }
    }

    public function CountdownTick() {
        $endTime = $this->ReadAttributeInteger('CountdownEndTime');
        if ($endTime == 0) {
            $this->timerStop();
            return;
        }
        $remaining = $endTime - time();
        $this->SendDebug('CountdownTick', 'Verbleibend: ' . $remaining . 's', 0);
        if ($remaining <= 0) {
            $this->SendDebug('CountdownTick', 'Countdown abgelaufen – schalte aus', 0);
            $this->timerStop();
            $this->SetSwitch(false);
        } else {
            $this->SetValue('Countdown', $this->formatDuration($remaining));
        }
    }

    public function ScheduleTick() {
        if (!$this->GetValue('TimerActive')) {
            return;
        }
        $now    = time();
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers)) {
            $this->scheduleNext();
            return;
        }
        foreach ($timers as $i => $timer) {
            if (!$this->getTimerBool('TActive', $i)) {
                continue;
            }
            $scheduledTime = $this->getTimerScheduledTime($i);
            if ($scheduledTime === null) {
                continue;
            }
            // Feuern wenn innerhalb des 90-Sekunden-Fensters
            if ($now >= $scheduledTime && $now < $scheduledTime + 90) {
                $this->fireTimer($i);
            }
        }
        $this->scheduleNext();
    }

    public function ProfileUpdateTick() {
        $this->updateTimeModeProfile();
        $this->updateAllTimerCategoryNames();
        $this->scheduleProfileUpdate();
        // Nach der taeglichen Solar-Aktualisierung muss auch die Schaltplanung
        // neu berechnet werden - sonst haengt sie an der Sunrise-Subscription.
        $this->scheduleNext();
    }

    // ===== ZEITSCHALTER – SCHEDULING =====

    private function scheduleNext() {
        if (!$this->GetValue('TimerActive')) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            return;
        }
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers) || empty($timers)) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            return;
        }
        $now      = time();
        $nextTime = null;
        foreach ($timers as $i => $timer) {
            if (!$this->getTimerBool('TActive', $i)) {
                continue;
            }
            $scheduledTime = $this->getTimerScheduledTime($i);
            if ($scheduledTime === null) {
                continue;
            }
            if ($scheduledTime <= $now) {
                $scheduledTime += 86400; // morgen
            }
            if ($nextTime === null || $scheduledTime < $nextTime) {
                $nextTime = $scheduledTime;
            }
        }
        if ($nextTime === null) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            return;
        }
        $intervalMs = ($nextTime - $now) * 1000;
        $this->SetTimerInterval('ScheduleTimer', max(1000, $intervalMs));
        $this->SendDebug('Schedule', 'Nächster Timer: ' . date('H:i:s', $nextTime), 0);
    }

    private function fireTimer(int $index) {
        $state = $this->getTimerBool('TState', $index);
        $this->SendDebug('Timer', 'Timer ' . $index . ' feuert → ' . ($state ? 'EIN' : 'AUS'), 0);
        $this->SetSwitch($state);
    }

    private function getTimerScheduledTime(int $index): ?int {
        $mode   = $this->getTimerInt('TMode',   $index);
        $offset = $this->getTimerInt('TOffset', $index) * 60; // → Sekunden
        if ($mode === 0) {
            $timeVal = $this->getTimerInt('TTime', $index);
            $hour    = (int)date('G', $timeVal);
            $min     = (int)date('i', $timeVal);
            return mktime($hour, $min, 0);
        }
        $solarTime = $this->getSolarTime($mode);
        if ($solarTime === null) {
            return null;
        }
        return $solarTime + $offset;
    }

    private function getSolarTime(int $mode): ?int {
        $keyMap = [
            1 => 'Sunrise',
            2 => 'Sunset',
            3 => 'CivilTwilightStart',
            4 => 'CivilTwilightEnd',
            5 => 'NauticTwilightStart',
            6 => 'NauticTwilightEnd',
            7 => 'AstronomicTwilightStart',
            8 => 'AstronomicTwilightEnd',
        ];
        if (!isset($keyMap[$mode])) {
            return null;
        }
        $locationID = $this->ReadPropertyInteger('LocationID');
        if ($locationID == 0 || !IPS_InstanceExists($locationID)) {
            $this->SendDebug('Solar', 'Location Control nicht konfiguriert (Instanz in der Form auswählen)', 0);
            return null;
        }
        $ident = $keyMap[$mode];
        $varID = @IPS_GetObjectIDByIdent($ident, $locationID);
        if (!$varID || !IPS_VariableExists($varID)) {
            $this->SendDebug('Solar', 'Variable "' . $ident . '" nicht gefunden', 0);
            return null;
        }
        $timestamp = GetValueInteger($varID);
        $this->SendDebug('Solar', 'Modus ' . $mode . ' (' . $ident . '): ' . date('H:i', $timestamp), 0);
        return $timestamp;
    }

    private function scheduleProfileUpdate() {
        // Täglich um 00:05 Uhr Solar-Zeiten im Profil aktualisieren
        $nextUpdate = mktime(0, 5, 0, (int)date('n'), (int)date('j') + 1);
        $intervalMs = ($nextUpdate - time()) * 1000;
        $this->SetTimerInterval('ProfileUpdateTimer', max(60000, $intervalMs));
        $this->SendDebug('Solar', 'Profilaktualisierung geplant: ' . date('d.m.Y H:i', $nextUpdate), 0);
    }

    private function updateLocationSubscription() {
        $oldVarID = $this->ReadAttributeInteger('RegisteredSunriseVarID');
        if ($oldVarID != 0) {
            $this->UnregisterMessage($oldVarID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredSunriseVarID', 0);
        }
        $locationID = $this->ReadPropertyInteger('LocationID');
        if ($locationID == 0 || !IPS_InstanceExists($locationID)) {
            return;
        }
        $varID = @IPS_GetObjectIDByIdent('Sunrise', $locationID);
        if ($varID && IPS_VariableExists($varID)) {
            $this->RegisterMessage($varID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredSunriseVarID', $varID);
        }
    }

    private function updateTimeModeProfile() {
        if (!IPS_VariableProfileExists('AutSw3.TimeMode')) {
            return;
        }
        $modes = [
            0 => ['name' => 'Manuell',                  'icon' => 'Clock', 'solar' => false],
            1 => ['name' => 'Sonnenaufgang',             'icon' => 'Sun',   'solar' => true],
            2 => ['name' => 'Sonnenuntergang',           'icon' => 'Moon',  'solar' => true],
            3 => ['name' => 'Bürgerl. Sonnenaufgang',   'icon' => 'Sun',   'solar' => true],
            4 => ['name' => 'Bürgerl. Sonnenuntergang', 'icon' => 'Moon',  'solar' => true],
            5 => ['name' => 'Naut. Sonnenaufgang',      'icon' => 'Sun',   'solar' => true],
            6 => ['name' => 'Naut. Sonnenuntergang',    'icon' => 'Moon',  'solar' => true],
            7 => ['name' => 'Astron. Sonnenaufgang',    'icon' => 'Sun',   'solar' => true],
            8 => ['name' => 'Astron. Sonnenuntergang',  'icon' => 'Moon',  'solar' => true],
        ];
        foreach ($modes as $value => $info) {
            $caption = $info['name'];
            if ($info['solar']) {
                $solarTime = $this->getSolarTime($value);
                if ($solarTime !== null) {
                    $caption .= ' (' . date('H:i', $solarTime) . ')';
                }
            }
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', $value, $caption, $info['icon'], -1);
        }
    }

    // ===== ZEITSCHALTER – KATEGORIEN & VARIABLEN =====

    private function applyTimers(int $scriptID, int $timersCatID) {
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers)) {
            $timers = [];
        }
        foreach ($timers as $i => $timer) {
            $name  = !empty($timer['TimerName']) ? $timer['TimerName'] : ('Timer ' . ($i + 1));
            $catID = $this->ensureTimerCategory($i, $name, $timersCatID);
            $this->ensureTimerVars($i, $catID, $scriptID);
            $this->updateTimerCategoryName($i);
        }
        // Überschüssige Kategorien aus alter Konfiguration löschen
        $oldCount = $this->ReadAttributeInteger('TimerCount');
        for ($i = count($timers); $i < $oldCount; $i++) {
            $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $i, $timersCatID);
            if (!$catID) {
                $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $i, $this->InstanceID);
            }
            if ($catID) {
                foreach (IPS_GetChildrenIDs($catID) as $childID) {
                    if (IPS_VariableExists($childID)) {
                        IPS_DeleteVariable($childID);
                    }
                }
                IPS_DeleteCategory($catID);
            }
        }
        $this->WriteAttributeInteger('TimerCount', count($timers));
        $this->sortTimerCategories();
    }

    private function ensureTimerCategory(int $index, string $name, int $timersCatID): int {
        $ident = 'TimerCat_' . $index;
        $catID = @IPS_GetObjectIDByIdent($ident, $timersCatID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $timersCatID);
            IPS_SetIdent($catID, $ident);
            IPS_SetIcon($catID, 'Calendar');
        }
        IPS_SetName($catID, $name);
        IPS_SetPosition($catID, $index);
        return $catID;
    }

    private function ensureTimerVars(int $index, int $catID, int $scriptID) {
        $s = '_' . $index;
        $this->ensureTimerVar($catID, 'TActive' . $s, 0, 'Aktiv',          '~Switch',              0, $scriptID);
        $this->ensureTimerVar($catID, 'TState'  . $s, 0, 'Schaltziel',     '~Switch',              1, $scriptID);
        $this->ensureTimerVar($catID, 'TMode'   . $s, 1, 'Zeitmodus',      'AutSw3.TimeMode',      2, $scriptID);
        $this->ensureTimerVar($catID, 'TTime'   . $s, 1, 'Uhrzeit',        '~UnixTimestampTime',   3, $scriptID);
        $this->ensureTimerVar($catID, 'TOffset' . $s, 1, 'Versatz (min)',  'AutSw3.Offset',        4, $scriptID);
        $this->ensureTimerVar($catID, 'TDelete' . $s, 0, 'Timer löschen',  '~Switch',              5, $scriptID);

        // TTime nur bei Manuell-Modus sichtbar, TOffset nur bei Solar-Modi
        $mode     = $this->getTimerInt('TMode', $index);
        $tTimeID  = @IPS_GetObjectIDByIdent('TTime'   . $s, $catID);
        $tOffsetID = @IPS_GetObjectIDByIdent('TOffset' . $s, $catID);
        if ($tTimeID)   { IPS_SetHidden($tTimeID,   $mode !== 0); }
        if ($tOffsetID) { IPS_SetHidden($tOffsetID, $mode === 0); }

        // Migration: alte THour/TMin-Variablen in TTime überführen und löschen
        $oldHourID = @IPS_GetObjectIDByIdent('THour' . $s, $catID);
        $oldMinID  = @IPS_GetObjectIDByIdent('TMin'  . $s, $catID);
        if ($oldHourID || $oldMinID) {
            $oldHour = $oldHourID ? GetValueInteger($oldHourID) : 0;
            $oldMin  = $oldMinID  ? GetValueInteger($oldMinID)  : 0;
            $tTimeID = @IPS_GetObjectIDByIdent('TTime' . $s, $catID);
            if ($tTimeID && GetValueInteger($tTimeID) == 0 && ($oldHour > 0 || $oldMin > 0)) {
                SetValueInteger($tTimeID, $oldHour * 3600 + $oldMin * 60);
            }
            if ($oldHourID) { IPS_DeleteVariable($oldHourID); }
            if ($oldMinID)  { IPS_DeleteVariable($oldMinID);  }
        }
    }

    private function ensureTimerVar(int $catID, string $ident, int $type, string $name, string $profile, int $position, int $scriptID) {
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if (!$varID) {
            $varID = IPS_CreateVariable($type);
            IPS_SetParent($varID, $catID);
            IPS_SetIdent($varID, $ident);
        }
        IPS_SetName($varID, $name);
        IPS_SetPosition($varID, $position);
        IPS_SetVariableCustomProfile($varID, $profile);
        IPS_SetVariableCustomAction($varID, $scriptID);
    }

    private function getTimerVarID(string $ident, int $index): int {
        // TimersCat suchen (neue Position), Fallback direkt unter Instanz (vor Migration)
        $timersCatID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
        $catID = $timersCatID
            ? @IPS_GetObjectIDByIdent('TimerCat_' . $index, $timersCatID)
            : 0;
        if (!$catID) {
            $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $index, $this->InstanceID);
        }
        if (!$catID) {
            return 0;
        }
        return (int)@IPS_GetObjectIDByIdent($ident, $catID);
    }

    private function getTimerBool(string $prefix, int $index): bool {
        $varID = $this->getTimerVarID($prefix . '_' . $index, $index);
        return $varID ? GetValueBoolean($varID) : false;
    }

    private function getTimerInt(string $prefix, int $index): int {
        $varID = $this->getTimerVarID($prefix . '_' . $index, $index);
        return $varID ? GetValueInteger($varID) : 0;
    }

    // ===== COUNTDOWN =====

    private function isCountdownActive(): bool {
        return $this->ReadPropertyBoolean('CountdownEnabled') && $this->GetValue('CDActive');
    }

    private function isWatchTargetActive(): bool {
        return $this->ReadPropertyBoolean('CountdownEnabled') && $this->GetValue('WatchTarget');
    }

    private function getCDTimeVar(string $ident): int {
        $catID = @IPS_GetObjectIDByIdent('CountdownTimeCat', $this->InstanceID);
        if (!$catID) {
            return 0;
        }
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        return $varID ? GetValueInteger($varID) : 0;
    }

    private function setCDTimeVar(string $ident, int $value) {
        $catID = @IPS_GetObjectIDByIdent('CountdownTimeCat', $this->InstanceID);
        if (!$catID) {
            return;
        }
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if ($varID) {
            SetValueInteger($varID, $value);
        }
    }

    private function getCDSeconds(): int {
        return $this->getCDTimeVar('CDHours')   * 3600
             + $this->getCDTimeVar('CDMinutes') * 60
             + $this->getCDTimeVar('CDSeconds');
    }

    private function formatDuration(int $seconds): string {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function timerStart(int $seconds) {
        $this->WriteAttributeInteger('CountdownEndTime', time() + $seconds);
        $this->SetValue('Countdown', $this->formatDuration($seconds));
        IPS_SetHidden($this->GetIDForIdent('Countdown'), false);
        $this->SetTimerInterval('CountdownTimer', 1000);
        $this->SendDebug('Timer', 'Gestartet: ' . $this->formatDuration($seconds), 0);
    }

    private function timerStop() {
        $this->WriteAttributeInteger('CountdownEndTime', 0);
        $this->SetTimerInterval('CountdownTimer', 0);
        $this->SetValue('Countdown', '');
        IPS_SetHidden($this->GetIDForIdent('Countdown'), true);
    }

    // ===== HILFSMETHODEN =====

    private function migrateCDVarsToCategory() {
        // CDHours/CDMinutes/CDSeconds direkt am Modul (alte Version) → Kategorie
        foreach (['CDHours', 'CDMinutes', 'CDSeconds'] as $ident) {
            $oldID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($oldID && IPS_VariableExists($oldID)) {
                $value = GetValueInteger($oldID);
                if ($value > 0 && $this->getCDTimeVar($ident) == 0) {
                    $this->setCDTimeVar($ident, $value);
                }
                IPS_DeleteVariable($oldID);
            }
        }
    }

    private function migrateCDToSingleVar() {
        // CDDuration (älteste Version) löschen falls vorhanden
        $durID = @IPS_GetObjectIDByIdent('CDDuration', $this->InstanceID);
        if ($durID && IPS_VariableExists($durID)) {
            $durVal = GetValueInteger($durID);
            if ($durVal > 0 && $this->getCDTimeVar('CDHours') == 0
                            && $this->getCDTimeVar('CDMinutes') == 0
                            && $this->getCDTimeVar('CDSeconds') == 0) {
                $this->setCDTimeVar('CDHours',   intdiv($durVal, 3600));
                $this->setCDTimeVar('CDMinutes', intdiv($durVal % 3600, 60));
                $this->setCDTimeVar('CDSeconds', $durVal % 60);
            }
            IPS_DeleteVariable($durID);
        }

        // Leere CountdownCat (alte Version) entfernen
        $catID = @IPS_GetObjectIDByIdent('CountdownCat', $this->InstanceID);
        if ($catID && empty(IPS_GetChildrenIDs($catID))) {
            IPS_DeleteCategory($catID);
        }
    }

    private function ensureActionScript(): int {
        $scriptID = @IPS_GetObjectIDByIdent('CDActionScript', $this->InstanceID);
        if (!$scriptID) {
            $scriptID = IPS_CreateScript(0);
            IPS_SetParent($scriptID, $this->InstanceID);
            IPS_SetIdent($scriptID, 'CDActionScript');
            IPS_SetName($scriptID, 'Aktion');
            IPS_SetHidden($scriptID, true);
        }
        IPS_SetScriptContent($scriptID,
            '<?php' . "\n" .
            'IPS_RequestAction(' . $this->InstanceID . ', IPS_GetObject($_IPS[\'VARIABLE\'])[\'ObjectIdent\'], $_IPS[\'VALUE\']);'
        );
        return $scriptID;
    }

    private function updateTimerCategoryName(int $index) {
        $timersCatID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
        if (!$timersCatID) {
            return;
        }
        $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $index, $timersCatID);
        if (!$catID) {
            return;
        }
        $timers   = json_decode($this->ReadPropertyString('TimerList'), true);
        $baseName = (is_array($timers) && !empty($timers[$index]['TimerName']))
            ? $timers[$index]['TimerName']
            : ('Timer ' . ($index + 1));

        $scheduledTime = $this->getTimerScheduledTime($index);
        $name = $baseName;
        $active = $this->getTimerBool('TActive', $index);
        if (!$active) {
            $name .= ' (inaktiv)';
        } elseif ($scheduledTime !== null) {
            $state  = $this->getTimerBool('TState', $index);
            $mode   = $this->getTimerInt('TMode', $index);
            $prefix = ($mode === 0) ? 'M' : 'S';
            $name  .= ' (' . $prefix . ' ' . date('H:i', $scheduledTime) . ' / ' . ($state ? 'An' : 'Aus') . ')';
        }
        IPS_SetName($catID, $name);
    }

    private function updateAllTimerCategoryNames() {
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers)) {
            return;
        }
        for ($i = 0; $i < count($timers); $i++) {
            $this->updateTimerCategoryName($i);
        }
        $this->sortTimerCategories();
    }

    private function sortTimerCategories() {
        $timersCatID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
        if (!$timersCatID) {
            return;
        }
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers) || empty($timers)) {
            return;
        }
        $entries = [];
        for ($i = 0; $i < count($timers); $i++) {
            $active        = $this->getTimerBool('TActive', $i);
            $scheduledTime = $this->getTimerScheduledTime($i);
            if ($scheduledTime !== null) {
                $tod     = (int)date('G', $scheduledTime) * 3600 + (int)date('i', $scheduledTime) * 60;
                $timeKey = ($tod - 60 + 86400) % 86400;
            } else {
                $timeKey = PHP_INT_MAX;
            }
            $entries[] = ['index' => $i, 'active' => $active, 'timeKey' => $timeKey];
        }
        usort($entries, function ($a, $b) {
            if ($a['active'] !== $b['active']) {
                return $a['active'] ? -1 : 1; // aktive zuerst
            }
            return $a['timeKey'] <=> $b['timeKey'];
        });
        foreach ($entries as $pos => $entry) {
            $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $entry['index'], $timersCatID);
            if ($catID) {
                IPS_SetPosition($catID, $pos + 1); // +1: AddTimer liegt auf Position 0
            }
        }
    }

    private function ensureTimersCat(int $scriptID): int {
        $catID = @IPS_GetObjectIDByIdent('TimersCat', $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'TimersCat');
            IPS_SetIcon($catID, 'Calendar');
        }
        IPS_SetName($catID, 'Zeitschalter');
        IPS_SetPosition($catID, 5);

        // Migration: alten Bool-AddTimer + NewTimerName löschen falls vorhanden
        foreach (['NewTimerName', 'AddTimer'] as $ident) {
            $oldID = @IPS_GetObjectIDByIdent($ident, $catID);
            if ($oldID && IPS_VariableExists($oldID) && IPS_GetVariable($oldID)['VariableType'] !== 3) {
                IPS_DeleteVariable($oldID);
            }
        }

        // String-Eingabe: Name → Erstellen
        $this->ensureTimerVar($catID, 'AddTimer', 3, 'Timer hinzufügen', '~String', 0, $scriptID);

        return $catID;
    }

    private function readTimerConfig(int $index): array {
        return [
            'TActive' => $this->getTimerBool('TActive', $index),
            'TState'  => $this->getTimerBool('TState',  $index),
            'TMode'   => $this->getTimerInt('TMode',    $index),
            'TTime'   => $this->getTimerInt('TTime',    $index),
            'TOffset' => $this->getTimerInt('TOffset',  $index),
        ];
    }

    private function writeTimerConfig(int $index, array $config) {
        $varID = $this->getTimerVarID('TActive_' . $index, $index);
        if ($varID) { SetValueBoolean($varID, $config['TActive']); }
        $varID = $this->getTimerVarID('TState_' . $index, $index);
        if ($varID) { SetValueBoolean($varID, $config['TState']); }
        $varID = $this->getTimerVarID('TMode_' . $index, $index);
        if ($varID) { SetValueInteger($varID, $config['TMode']); }
        $varID = $this->getTimerVarID('TTime_' . $index, $index);
        if ($varID) { SetValueInteger($varID, $config['TTime']); }
        $varID = $this->getTimerVarID('TOffset_' . $index, $index);
        if ($varID) { SetValueInteger($varID, $config['TOffset']); }
    }

    private function migrateTimerCatsToTimersCat(int $timersCatID) {
        $count = $this->ReadAttributeInteger('TimerCount');
        for ($i = 0; $i < $count; $i++) {
            $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $i, $this->InstanceID);
            if ($catID) {
                IPS_SetParent($catID, $timersCatID);
                IPS_SetPosition($catID, $i);
            }
        }
    }

    private function ensureCountdownTimeCategory(int $scriptID): int {
        $catID = @IPS_GetObjectIDByIdent('CountdownTimeCat', $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'CountdownTimeCat');
            IPS_SetIcon($catID, 'Clock');
        }
        IPS_SetName($catID, 'Countdown-Zeit');
        IPS_SetPosition($catID, 3);
        $this->ensureTimerVar($catID, 'CDHours',   1, 'Stunden',  'AutSw3.Hours',   0, $scriptID);
        $this->ensureTimerVar($catID, 'CDMinutes', 1, 'Minuten',  'AutSw3.Minutes', 1, $scriptID);
        $this->ensureTimerVar($catID, 'CDSeconds', 1, 'Sekunden', 'AutSw3.Seconds', 2, $scriptID);
        return $catID;
    }

    private function ensureProfiles() {
        if (!IPS_VariableProfileExists('AutSw3.Hours')) {
            IPS_CreateVariableProfile('AutSw3.Hours', 1);
            IPS_SetVariableProfileValues('AutSw3.Hours', 0, 23, 1);
            IPS_SetVariableProfileText('AutSw3.Hours', '', ' h');
        }
        if (!IPS_VariableProfileExists('AutSw3.Minutes')) {
            IPS_CreateVariableProfile('AutSw3.Minutes', 1);
            IPS_SetVariableProfileValues('AutSw3.Minutes', 0, 59, 1);
            IPS_SetVariableProfileText('AutSw3.Minutes', '', ' min');
        }
        if (!IPS_VariableProfileExists('AutSw3.Seconds')) {
            IPS_CreateVariableProfile('AutSw3.Seconds', 1);
            IPS_SetVariableProfileValues('AutSw3.Seconds', 0, 59, 1);
            IPS_SetVariableProfileText('AutSw3.Seconds', '', ' s');
        }
        if (!IPS_VariableProfileExists('AutSw3.TimeMode')) {
            IPS_CreateVariableProfile('AutSw3.TimeMode', 1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 0, 'Manuell',                  'Clock', -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 1, 'Sonnenaufgang',             'Sun',   -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 2, 'Sonnenuntergang',           'Moon',  -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 3, 'Bürgerl. Sonnenaufgang',    'Sun',   -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 4, 'Bürgerl. Sonnenuntergang',  'Moon',  -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 5, 'Naut. Sonnenaufgang',       'Sun',   -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 6, 'Naut. Sonnenuntergang',     'Moon',  -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 7, 'Astron. Sonnenaufgang',     'Sun',   -1);
            IPS_SetVariableProfileAssociation('AutSw3.TimeMode', 8, 'Astron. Sonnenuntergang',   'Moon',  -1);
        }
        if (!IPS_VariableProfileExists('AutSw3.Offset')) {
            IPS_CreateVariableProfile('AutSw3.Offset', 1);
            IPS_SetVariableProfileValues('AutSw3.Offset', -120, 120, 5);
            IPS_SetVariableProfileText('AutSw3.Offset', '', ' min');
        }
    }
}
