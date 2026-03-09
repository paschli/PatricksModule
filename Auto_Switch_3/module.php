<?php

// Modul schaltet eine ausgewählte Variable in IP-Symcon.
// Zeigt einen Schalter in der Visualisierung.
// Reagiert auf externe Änderungen der Ziel-Variable (bidirektionale Synchronisation).
// Optionaler Countdown-Timer mit Eingabe in Stunden/Minuten/Sekunden (in eigenem Ordner).
// Konfigurierbare Zeitschalter (manuell oder Solar) über die App.

class AutSw3 extends IPSModule {

    public function Create() {
        parent::Create();

        $this->RegisterPropertyInteger('TargetID', 0);
        $this->RegisterPropertyBoolean('CountdownEnabled', false);
        $this->RegisterPropertyString('TimerList', '[]');

        $this->RegisterAttributeInteger('RegisteredTargetID', 0);
        $this->RegisterAttributeInteger('CountdownTimerID', 0); // Cleanup alter Versionen
        $this->RegisterAttributeInteger('RegisteredSunriseVarID', 0);
        $this->RegisterAttributeInteger('TimerCount', 0);

        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        $this->RegisterVariableBoolean('CDActive', 'Countdown aktiv', '~Switch', 1);
        IPS_SetIcon($this->GetIDForIdent('CDActive'), 'Clock');

        // Profile sicherstellen bevor Variablen damit registriert werden
        $this->ensureProfiles();

        $this->RegisterVariableInteger('CDHours',   'Stunden',  'AutSw3.Hours',   1);
        $this->RegisterVariableInteger('CDMinutes', 'Minuten',  'AutSw3.Minutes', 2);
        $this->RegisterVariableInteger('CDSeconds', 'Sekunden', 'AutSw3.Seconds', 3);

        // Migration: alte Integer-Countdown-Variable löschen falls vorhanden
        $oldID = @IPS_GetObjectIDByIdent('Countdown', $this->InstanceID);
        if ($oldID && IPS_VariableExists($oldID) && IPS_GetVariable($oldID)['VariableType'] !== 3) {
            IPS_DeleteVariable($oldID);
        }
        $this->RegisterVariableString('Countdown', 'Verbleibend', '', 2);

        // Timer registrieren – NUR in Create() erlaubt
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick(' . $this->InstanceID . ');');
        $this->RegisterTimer('ScheduleTimer',  0, 'AutSw3_ScheduleTick('  . $this->InstanceID . ');');
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

        // Countdown-Kategorie
        $catID = $this->ensureCountdownCategory();
        foreach (['CDHours', 'CDMinutes', 'CDSeconds'] as $ident) {
            if (@IPS_GetObjectIDByIdent($ident, $catID)) {
                // Schon in der Kategorie – Duplikat als direktes Kind löschen falls vorhanden
                $dupID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
                if ($dupID) {
                    IPS_DeleteVariable($dupID);
                }
                continue;
            }
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varID) {
                IPS_SetParent($varID, $catID);
            }
        }

        // Gemeinsames Aktions-Script für alle Sub-Variablen
        $scriptID = $this->ensureActionScript();
        foreach (['CDHours', 'CDMinutes', 'CDSeconds'] as $ident) {
            $varID = $this->getCDVarID($ident);
            if ($varID) {
                IPS_SetVariableCustomAction($varID, $scriptID);
            }
        }

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

        // Countdown sichtbarkeit
        $featureEnabled = $this->ReadPropertyBoolean('CountdownEnabled');
        IPS_SetHidden($this->GetIDForIdent('CDActive'), !$featureEnabled);
        $cdActive = $this->GetValue('CDActive');
        IPS_SetHidden($catID, !($featureEnabled && $cdActive));
        IPS_SetHidden($this->GetIDForIdent('Countdown'), true);
        if (!$featureEnabled || !$cdActive) {
            $this->timerStop();
        }

        // Zeitschalter-Kategorien erstellen/aktualisieren
        $this->applyTimers($scriptID);

        // Location-Subscription für Solar-Modi
        $this->updateLocationSubscription();

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
            $this->SendDebug('Schedule', 'Solarzeit aktualisiert – scheduleNext', 0);
            $this->scheduleNext();
        }
    }

    public function RequestAction($ident, $value) {
        if ($ident === 'State') {
            $this->SetSwitch((bool)$value);
        } elseif ($ident === 'CDActive') {
            $this->SetValue('CDActive', (bool)$value);
            $catID = $this->ensureCountdownCategory();
            IPS_SetHidden($catID, !$value);
            if ($value && $this->GetValue('State')) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                }
            } else {
                $this->timerStop();
            }
        } elseif (in_array($ident, ['CDHours', 'CDMinutes', 'CDSeconds'])) {
            $varID = $this->getCDVarID($ident);
            if ($varID) {
                SetValueInteger($varID, (int)$value);
            }
            if ($this->GetValue('State') && $this->isCountdownActive()) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                } else {
                    $this->timerStop();
                }
            }
        } elseif (preg_match('/^T(Active|State)_(\d+)$/', $ident, $m)) {
            $index = (int)$m[2];
            $varID = $this->getTimerVarID($ident, $index);
            if ($varID) {
                SetValueBoolean($varID, (bool)$value);
            }
            if ($m[1] === 'Active') {
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
        } elseif ($this->isCountdownActive()) {
            $total = $this->getCDSeconds();
            if ($total > 0) {
                $this->timerStart($total);
            }
        }
    }

    public function CountdownTick() {
        $remaining = $this->getCountdownRemaining() - 1;
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

    // ===== ZEITSCHALTER – SCHEDULING =====

    private function scheduleNext() {
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
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!isset($timers[$index])) {
            return null;
        }
        $timer  = $timers[$index];
        $mode   = (int)($timer['TimerMode']   ?? 0);
        $offset = (int)($timer['TimerOffset'] ?? 0) * 60; // → Sekunden
        if ($mode === 0) {
            $parts = explode(':', (string)($timer['TimerTime'] ?? '00:00'));
            $hour  = (int)($parts[0] ?? 0);
            $min   = (int)($parts[1] ?? 0);
            return mktime($hour, $min, 0);
        }
        $solarTime = $this->getSolarTime($mode);
        if ($solarTime === null) {
            return null;
        }
        return $solarTime + $offset;
    }

    private function getSolarTime(int $mode): ?int {
        $locationIDs = @IPS_GetInstanceListByModuleID('{45AE3035-AEF6-4A4B-876D-A2DF51BB4E6E}');
        if (empty($locationIDs)) {
            $this->SendDebug('Solar', 'Location Control nicht gefunden', 0);
            return null;
        }
        // Mehrere mögliche Ident-Namen je IPS-Version
        $identMap = [
            1 => ['Sunrise',             'Sonnenaufgang'],
            2 => ['Sunset',              'Sonnenuntergang'],
            3 => ['CivilSunrise',        'BuergerSonnenaufgang'],
            4 => ['CivilSunset',         'BuergerSonnenuntergang'],
            5 => ['NauticalSunrise',     'NautSonnenaufgang'],
            6 => ['NauticalSunset',      'NautSonnenuntergang'],
            7 => ['AstronomicalSunrise', 'AstroSonnenaufgang'],
            8 => ['AstronomicalSunset',  'AstroSonnenuntergang'],
        ];
        if (!isset($identMap[$mode])) {
            return null;
        }
        foreach ($locationIDs as $locID) {
            foreach ($identMap[$mode] as $ident) {
                $varID = @IPS_GetObjectIDByIdent($ident, $locID);
                if ($varID && IPS_VariableExists($varID)) {
                    $val = GetValueInteger($varID);
                    $this->SendDebug('Solar', 'Modus ' . $mode . ' → ' . date('H:i:s', $val), 0);
                    return $val;
                }
            }
        }
        $this->SendDebug('Solar', 'Solar-Variable für Modus ' . $mode . ' nicht gefunden', 0);
        return null;
    }

    private function updateLocationSubscription() {
        $oldVarID = $this->ReadAttributeInteger('RegisteredSunriseVarID');
        if ($oldVarID != 0) {
            $this->UnregisterMessage($oldVarID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredSunriseVarID', 0);
        }
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (empty($timers)) {
            return;
        }
        $locationIDs = @IPS_GetInstanceListByModuleID('{45AE3035-AEF6-4A4B-876D-A2DF51BB4E6E}');
        if (empty($locationIDs)) {
            return;
        }
        foreach (['Sunrise', 'Sonnenaufgang'] as $ident) {
            $varID = @IPS_GetObjectIDByIdent($ident, $locationIDs[0]);
            if ($varID && IPS_VariableExists($varID)) {
                $this->RegisterMessage($varID, VM_UPDATE);
                $this->WriteAttributeInteger('RegisteredSunriseVarID', $varID);
                return;
            }
        }
    }

    // ===== ZEITSCHALTER – KATEGORIEN & VARIABLEN =====

    private function applyTimers(int $scriptID) {
        $timers = json_decode($this->ReadPropertyString('TimerList'), true);
        if (!is_array($timers)) {
            $timers = [];
        }
        foreach ($timers as $i => $timer) {
            $name  = !empty($timer['TimerName']) ? $timer['TimerName'] : ('Timer ' . ($i + 1));
            $catID = $this->ensureTimerCategory($i, $name);
            $this->ensureTimerVars($i, $catID, $scriptID);
        }
        // Überschüssige Kategorien aus alter Konfiguration löschen
        $oldCount = $this->ReadAttributeInteger('TimerCount');
        for ($i = count($timers); $i < $oldCount; $i++) {
            $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $i, $this->InstanceID);
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
    }

    private function ensureTimerCategory(int $index, string $name): int {
        $ident = 'TimerCat_' . $index;
        $catID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, $ident);
            IPS_SetIcon($catID, 'Calendar');
        }
        IPS_SetName($catID, $name);
        IPS_SetPosition($catID, 10 + $index);
        return $catID;
    }

    private function ensureTimerVars(int $index, int $catID, int $scriptID) {
        // Alte App-Variablen aus früherer Version entfernen (Zeit ist jetzt in der Form)
        foreach (['TMode', 'THour', 'TMin', 'TOffset'] as $prefix) {
            $oldID = @IPS_GetObjectIDByIdent($prefix . '_' . $index, $catID);
            if ($oldID && IPS_VariableExists($oldID)) {
                IPS_DeleteVariable($oldID);
            }
        }
        $s = '_' . $index;
        $this->ensureTimerVar($catID, 'TActive' . $s, 0, 'Aktiv',      '~Switch', 0, $scriptID);
        $this->ensureTimerVar($catID, 'TState'  . $s, 0, 'Schaltziel', '~Switch', 1, $scriptID);
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
        $catID = @IPS_GetObjectIDByIdent('TimerCat_' . $index, $this->InstanceID);
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

    private function getCDVarID(string $ident): int {
        $catID = @IPS_GetObjectIDByIdent('CountdownCat', $this->InstanceID);
        if ($catID) {
            $varID = @IPS_GetObjectIDByIdent($ident, $catID);
            if ($varID) {
                return $varID;
            }
        }
        return (int)@IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    }

    private function getCDSeconds(): int {
        return GetValueInteger($this->getCDVarID('CDHours'))   * 3600
             + GetValueInteger($this->getCDVarID('CDMinutes')) * 60
             + GetValueInteger($this->getCDVarID('CDSeconds'));
    }

    private function getCountdownRemaining(): int {
        $parts = explode(':', $this->GetValue('Countdown'));
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        return 0;
    }

    private function formatDuration(int $seconds): string {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function timerStart(int $seconds) {
        $this->SetValue('Countdown', $this->formatDuration($seconds));
        IPS_SetHidden($this->GetIDForIdent('Countdown'), false);
        $this->SetTimerInterval('CountdownTimer', 1000);
        $this->SendDebug('Timer', 'Gestartet: ' . $this->formatDuration($seconds), 0);
    }

    private function timerStop() {
        $this->SetTimerInterval('CountdownTimer', 0);
        $this->SetValue('Countdown', '');
        IPS_SetHidden($this->GetIDForIdent('Countdown'), true);
    }

    // ===== HILFSMETHODEN =====

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

    private function ensureCountdownCategory(): int {
        $catID = @IPS_GetObjectIDByIdent('CountdownCat', $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'CountdownCat');
            IPS_SetName($catID, 'Countdown-Zeit');
            IPS_SetIcon($catID, 'Clock');
            IPS_SetPosition($catID, 3);
        }
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
