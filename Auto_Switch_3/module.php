<?php

// Modul schaltet eine ausgewählte Variable in IP-Symcon.
// Zeigt einen Schalter in der Visualisierung.
// Reagiert auf externe Änderungen der Ziel-Variable (bidirektionale Synchronisation).
// Optionaler Countdown-Timer: schaltet das Ziel nach Ablauf der Zeit automatisch aus.

class AutSw3 extends IPSModule {

    public function Create() {
        parent::Create();

        // Ziel-Variable (Boolean-Schalter)
        $this->RegisterPropertyInteger('TargetID', 0);

        // Countdown-Funktion aktivieren
        $this->RegisterPropertyBoolean('CountdownEnabled', false);

        // Gespeicherte Ziel-ID für saubere Abmeldung beim Neu-Konfigurieren
        $this->RegisterAttributeInteger('RegisteredTargetID', 0);

        // Schalter-Variable für die Visualisierung
        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        // Einstellbare Countdown-Zeit (in Sekunden) – in der App veränderbar
        $this->RegisterVariableInteger('CountdownSetting', 'Countdown-Zeit (s)', '', 1);

        // Timer für den Countdown (einmaliger Ablauf)
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick($id);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Aktionen aktivieren
        $this->EnableAction('State');
        $this->EnableAction('CountdownSetting');

        // Alte Message-Registrierung für die Ziel-Variable aufheben
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

        // Countdown-Variable anzeigen/ausblenden je nach Konfiguration
        $enabled = $this->ReadPropertyBoolean('CountdownEnabled');
        IPS_SetHidden($this->GetIDForIdent('CountdownSetting'), !$enabled);

        // Timer stoppen falls Countdown deaktiviert wurde
        if (!$enabled) {
            $this->timerStop();
        }
    }

    // Wird aufgerufen, wenn sich eine registrierte Variable ändert
    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) {
            return;
        }
        $targetID = $this->ReadPropertyInteger('TargetID');
        if ($SenderID != $targetID) {
            return;
        }
        $this->TargetChanged();
    }

    // Wird aufgerufen, wenn der Benutzer den Schalter oder die Countdown-Zeit in der App ändert
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

    // Schaltet das Ziel und startet/stoppt den Countdown
    public function SetSwitch(bool $state) {
        if (!IPS_SemaphoreEnter('AutSw3_' . $this->InstanceID, 1000)) {
            $this->SendDebug('SetSwitch', 'Semaphor Timeout', 0);
            return;
        }
        try {
            $targetID = $this->ReadPropertyInteger('TargetID');
            $this->SendDebug('SetSwitch', 'Schalte auf ' . ($state ? 'EIN' : 'AUS') . ', TargetID=' . $targetID, 0);

            if ($targetID == 0) {
                $this->SendDebug('SetSwitch', 'Kein Ziel konfiguriert', 0);
            } elseif (!IPS_VariableExists($targetID)) {
                $this->SendDebug('SetSwitch', 'Ziel-Variable existiert nicht: ' . $targetID, 0);
            } else {
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

    // Reagiert auf externe Änderung der Ziel-Variable
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

    // Wird einmalig aufgerufen wenn der Countdown abläuft
    public function CountdownTick() {
        $this->SendDebug('CountdownTick', 'Countdown abgelaufen – schalte aus', 0);
        $this->timerStop();
        $this->SetSwitch(false);
        $this->SendDebug('CountdownTick', 'SetSwitch(false) abgeschlossen', 0);
    }

    // Startet den Timer direkt über IPS-API
    private function timerStart(int $seconds) {
        $timerID = @IPS_GetObjectIDByIdent('CountdownTimer', $this->InstanceID);
        if ($timerID) {
            IPS_SetTimerInterval($timerID, $seconds * 1000);
            $this->SendDebug('Timer', 'Gestartet: ' . $seconds . 's (ID=' . $timerID . ')', 0);
        } else {
            $this->SendDebug('Timer', 'Timer-Objekt nicht gefunden!', 0);
        }
    }

    // Stoppt den Timer direkt über IPS-API
    private function timerStop() {
        $timerID = @IPS_GetObjectIDByIdent('CountdownTimer', $this->InstanceID);
        if ($timerID) {
            IPS_SetTimerInterval($timerID, 0);
        }
    }
}
