<?php

// Modul schaltet eine ausgewählte Variable in IP-Symcon.
// Zeigt einen Schalter in der Visualisierung.
// Reagiert auf externe Änderungen der Ziel-Variable (bidirektionale Synchronisation).
// Optionaler Countdown-Timer: schaltet das Ziel nach Ablauf der Zeit automatisch aus.

class AutSw3 extends IPSModule {

    public function Create() {
        parent::Create();

        $this->RegisterPropertyInteger('TargetID', 0);
        $this->RegisterPropertyBoolean('CountdownEnabled', false);

        $this->RegisterAttributeInteger('RegisteredTargetID', 0);
        // Attribut für Cleanup des alten IPS_CreateEvent-Timers (vorherige Modulversion)
        $this->RegisterAttributeInteger('CountdownTimerID', 0);

        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        $this->RegisterVariableInteger('CountdownSetting', 'Countdown-Zeit (s)', '', 1);
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Alten manuell erstellten Timer löschen (von vorheriger Modulversion)
        $legacyTimerID = $this->ReadAttributeInteger('CountdownTimerID');
        if ($legacyTimerID != 0 && IPS_EventExists($legacyTimerID)) {
            IPS_DeleteEvent($legacyTimerID);
            $this->SendDebug('Timer', 'Alter IPS_CreateEvent-Timer gelöscht: ID=' . $legacyTimerID, 0);
        }
        $this->WriteAttributeInteger('CountdownTimerID', 0);

        // Modul-Timer registrieren – Instance-ID direkt eingebettet (kein $id-Variable-Problem)
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick(' . $this->InstanceID . ');');
        $this->SendDebug('Timer', 'RegisterTimer gesetzt für Instanz ' . $this->InstanceID, 0);

        // Aktionen aktivieren
        $this->EnableAction('State');
        $this->EnableAction('CountdownSetting');

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

        // Countdown-Variable anzeigen/ausblenden
        $enabled = $this->ReadPropertyBoolean('CountdownEnabled');
        IPS_SetHidden($this->GetIDForIdent('CountdownSetting'), !$enabled);

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
        } elseif ($ident === 'CountdownSetting') {
            $newTime = (int)$value;
            $this->SetValue('CountdownSetting', $newTime);
            // Falls Schalter gerade EIN ist: Timer mit neuer Zeit neu starten
            if ($this->GetValue('State') && $this->ReadPropertyBoolean('CountdownEnabled') && $newTime > 0) {
                $this->timerStart($newTime);
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
                $countdownTime = $this->GetValue('CountdownSetting');
                if ($countdownTime > 0) {
                    $this->timerStart($countdownTime);
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
            $countdownTime = $this->GetValue('CountdownSetting');
            if ($countdownTime > 0) {
                $this->timerStart($countdownTime);
            }
        }
    }

    public function CountdownTick() {
        $this->SendDebug('CountdownTick', 'Countdown abgelaufen – schalte aus', 0);
        $this->timerStop();
        $this->SetSwitch(false);
        $this->SendDebug('CountdownTick', 'SetSwitch(false) abgeschlossen', 0);
    }

    private function timerStart(int $seconds) {
        $this->SetTimerInterval('CountdownTimer', $seconds * 1000);
        $this->SendDebug('Timer', 'Gestartet: ' . $seconds . 's', 0);
    }

    private function timerStop() {
        $this->SetTimerInterval('CountdownTimer', 0);
    }
}
