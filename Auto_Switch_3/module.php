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

        // Standardwert für die Countdown-Zeit beim ersten Start (in Sekunden)
        $this->RegisterPropertyInteger('CountdownTime', 0);

        // Gespeicherte Ziel-ID für saubere Abmeldung beim Neu-Konfigurieren
        $this->RegisterAttributeInteger('RegisteredTargetID', 0);

        // Schalter-Variable für die Visualisierung
        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        // Einstellbare Countdown-Zeit (in Sekunden, 0 = deaktiviert) – in der App veränderbar
        $this->RegisterVariableInteger('CountdownSetting', 'Countdown-Zeit (s)', '', 1);

        // Timer für den Countdown (einmaliger Ablauf)
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick($id);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Aktionen aktivieren
        $this->EnableAction('State');
        $this->EnableAction('CountdownSetting');

        // Standardwert aus Property übernehmen wenn CountdownSetting noch nicht gesetzt
        if ($this->GetValue('CountdownSetting') == 0 && $this->ReadPropertyInteger('CountdownTime') > 0) {
            $this->SetValue('CountdownSetting', $this->ReadPropertyInteger('CountdownTime'));
        }

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

        // Countdown-Timer stoppen (wird neu gestartet wenn Schalter eingeschaltet wird)
        $this->SetTimerInterval('CountdownTimer', 0);
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
            $this->SetValue('CountdownSetting', (int)$value);
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

            if ($state) {
                $countdownTime = $this->GetValue('CountdownSetting');
                if ($countdownTime > 0) {
                    $this->SetTimerInterval('CountdownTimer', $countdownTime * 1000);
                    $this->SendDebug('SetSwitch', 'Countdown gestartet: ' . $countdownTime . 's', 0);
                }
            } else {
                $this->SetTimerInterval('CountdownTimer', 0);
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

        // Keine Aktion nötig, wenn der Zustand bereits übereinstimmt
        if ($targetState === $currentState) {
            return;
        }

        $this->SendDebug('TargetChanged', 'Ziel geändert auf: ' . ($targetState ? 'EIN' : 'AUS'), 0);
        $this->SetValue('State', $targetState);

        if (!$targetState) {
            // Ziel wurde extern ausgeschaltet: Countdown stoppen
            $this->SetTimerInterval('CountdownTimer', 0);
        } else {
            // Ziel wurde extern eingeschaltet: Countdown starten falls konfiguriert
            $countdownTime = $this->GetValue('CountdownSetting');
            if ($countdownTime > 0) {
                $this->SetTimerInterval('CountdownTimer', $countdownTime * 1000);
                $this->SendDebug('TargetChanged', 'Countdown gestartet: ' . $countdownTime . 's', 0);
            }
        }
    }

    // Wird einmalig aufgerufen wenn der Countdown abläuft
    public function CountdownTick() {
        $this->SendDebug('CountdownTick', 'Countdown abgelaufen – schalte aus', 0);
        $this->SetTimerInterval('CountdownTimer', 0);
        $this->SetSwitch(false);
        $this->SendDebug('CountdownTick', 'SetSwitch(false) abgeschlossen', 0);
    }
}
