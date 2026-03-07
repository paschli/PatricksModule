<?php

// Modul schaltet eine ausgewählte Variable in IP-Symcon.
// Zeigt einen Schalter in der Visualisierung.
// Reagiert auf externe Änderungen der Ziel-Variable (bidirektionale Synchronisation).
// Optionaler Countdown-Timer mit Eingabe in Stunden/Minuten/Sekunden.

class AutSw3 extends IPSModule {

    public function Create() {
        parent::Create();

        $this->RegisterPropertyInteger('TargetID', 0);
        $this->RegisterPropertyBoolean('CountdownEnabled', false);

        $this->RegisterAttributeInteger('RegisteredTargetID', 0);
        $this->RegisterAttributeInteger('CountdownTimerID', 0); // Cleanup alter Versionen

        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        // Profile zuerst sicherstellen, bevor Variablen damit registriert werden
        $this->ensureProfiles();

        $this->RegisterVariableInteger('CDHours',   'Stunden',  'AutSw3.Hours',   1);
        $this->RegisterVariableInteger('CDMinutes', 'Minuten',  'AutSw3.Minutes', 2);
        $this->RegisterVariableInteger('CDSeconds', 'Sekunden', 'AutSw3.Seconds', 3);

        // Migration: alte Integer-Countdown-Variable löschen falls vorhanden (war früher Integer, jetzt String)
        $oldID = @IPS_GetObjectIDByIdent('Countdown', $this->InstanceID);
        if ($oldID && IPS_VariableExists($oldID) && IPS_GetVariable($oldID)['VariableType'] !== 3) {
            IPS_DeleteVariable($oldID);
        }
        $this->RegisterVariableString('Countdown', 'Verbleibend', '', 4);
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Alten manuell erstellten Timer löschen (von vorheriger Modulversion)
        $legacyTimerID = $this->ReadAttributeInteger('CountdownTimerID');
        if ($legacyTimerID != 0 && IPS_EventExists($legacyTimerID)) {
            IPS_DeleteEvent($legacyTimerID);
        }
        $this->WriteAttributeInteger('CountdownTimerID', 0);

        // Variable-Profile sicherstellen
        $this->ensureProfiles();

        // Modul-Timer registrieren – Instance-ID direkt eingebettet
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick(' . $this->InstanceID . ');');

        // Aktionen aktivieren
        $this->EnableAction('State');
        $this->EnableAction('CDHours');
        $this->EnableAction('CDMinutes');
        $this->EnableAction('CDSeconds');

        // Alte Message-Registrierung aufheben
        $oldTargetID = $this->ReadAttributeInteger('RegisteredTargetID');
        if ($oldTargetID != 0) {
            $this->UnregisterMessage($oldTargetID, VM_UPDATE);
        }

        // Neue Registrierung für die Ziel-Variable
        $targetID = $this->ReadPropertyInteger('TargetID');
        if ($targetID != 0 && IPS_VariableExists($targetID)) {
            $this->RegisterMessage($targetID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredTargetID', $targetID);
        } else {
            $this->WriteAttributeInteger('RegisteredTargetID', 0);
        }

        // Countdown-Variablen anzeigen/ausblenden
        $enabled = $this->ReadPropertyBoolean('CountdownEnabled');
        IPS_SetHidden($this->GetIDForIdent('CDHours'),   !$enabled);
        IPS_SetHidden($this->GetIDForIdent('CDMinutes'), !$enabled);
        IPS_SetHidden($this->GetIDForIdent('CDSeconds'), !$enabled);
        IPS_SetHidden($this->GetIDForIdent('Countdown'), true);

        if (!$enabled) {
            $this->timerStop();
        }
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) {
            return;
        }
        if ($SenderID != $this->ReadPropertyInteger('TargetID')) {
            return;
        }
        $this->TargetChanged();
    }

    public function RequestAction($ident, $value) {
        if ($ident === 'State') {
            $this->SetSwitch((bool)$value);
        } elseif (in_array($ident, ['CDHours', 'CDMinutes', 'CDSeconds'])) {
            $this->SetValue($ident, (int)$value);
            if ($this->GetValue('State') && $this->ReadPropertyBoolean('CountdownEnabled')) {
                $total = $this->getCDSeconds();
                if ($total > 0) {
                    $this->timerStart($total);
                } else {
                    $this->timerStop(); // Alle Felder auf 0 = Timer stoppen, Schalter bleibt an
                }
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

            if ($state && $this->ReadPropertyBoolean('CountdownEnabled')) {
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

        $targetState = GetValueBoolean($targetID);
        $currentState = $this->GetValue('State');

        if ($targetState === $currentState) {
            return;
        }

        $this->SendDebug('TargetChanged', 'Ziel geändert auf: ' . ($targetState ? 'EIN' : 'AUS'), 0);
        $this->SetValue('State', $targetState);

        if (!$targetState) {
            $this->timerStop();
        } elseif ($this->ReadPropertyBoolean('CountdownEnabled')) {
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

    private function getCDSeconds(): int {
        return $this->GetValue('CDHours') * 3600
             + $this->GetValue('CDMinutes') * 60
             + $this->GetValue('CDSeconds');
    }

    private function getCountdownRemaining(): int {
        $parts = explode(':', $this->GetValue('Countdown'));
        if (count($parts) === 3) {
            return (int)$parts[0] * 3600 + (int)$parts[1] * 60 + (int)$parts[2];
        }
        return 0;
    }

    private function formatDuration(int $seconds): string {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;
        return sprintf('%02d:%02d:%02d', $h, $m, $s);
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
    }
}
