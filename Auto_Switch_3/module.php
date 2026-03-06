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

        // Countdown-Zeit in Sekunden (0 = deaktiviert)
        $this->RegisterPropertyInteger('CountdownTime', 0);

        // Gespeicherte Ziel-ID für saubere Abmeldung beim Neu-Konfigurieren
        $this->RegisterAttributeInteger('RegisteredTargetID', 0);

        // Schalter-Variable für die Visualisierung
        $this->RegisterVariableBoolean('State', 'Schalter', '~Switch', 0);
        IPS_SetIcon($this->GetIDForIdent('State'), 'Power');

        // Countdown-Anzeige in Sekunden
        $this->RegisterVariableInteger('Countdown', 'Countdown (s)', '', 1);

        // Timer für den Countdown (1-Sekunden-Takt)
        $this->RegisterTimer('CountdownTimer', 0, 'AutSw3_CountdownTick($id);');
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        // Aktion auf den Schalter aktivieren
        $this->EnableAction('State');

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

        // Countdown-Variable anzeigen oder ausblenden
        $countdownTime = $this->ReadPropertyInteger('CountdownTime');
        IPS_SetHidden($this->GetIDForIdent('Countdown'), ($countdownTime == 0));
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

    // Wird aufgerufen, wenn der Benutzer den Schalter in der Visualisierung betätigt
    public function RequestAction($ident, $value) {
        if ($ident === 'State') {
            $this->SetSwitch((bool)$value);
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

            if ($targetID != 0 && IPS_VariableExists($targetID)) {
                RequestAction($targetID, $state);
            }

            $this->SetValue('State', $state);

            if ($state) {
                $countdownTime = $this->ReadPropertyInteger('CountdownTime');
                if ($countdownTime > 0) {
                    $this->SetValue('Countdown', $countdownTime);
                    $this->SetTimerInterval('CountdownTimer', 1000);
                    $this->SendDebug('SetSwitch', 'Countdown gestartet: ' . $countdownTime . 's', 0);
                }
            } else {
                $this->SetTimerInterval('CountdownTimer', 0);
                $this->SetValue('Countdown', 0);
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
            $this->SetValue('Countdown', 0);
        } else {
            // Ziel wurde extern eingeschaltet: Countdown starten falls konfiguriert
            $countdownTime = $this->ReadPropertyInteger('CountdownTime');
            if ($countdownTime > 0) {
                $this->SetValue('Countdown', $countdownTime);
                $this->SetTimerInterval('CountdownTimer', 1000);
                $this->SendDebug('TargetChanged', 'Countdown gestartet: ' . $countdownTime . 's', 0);
            }
        }
    }

    // Wird jede Sekunde aufgerufen solange der Countdown läuft
    public function CountdownTick() {
        $remaining = $this->GetValue('Countdown') - 1;
        $this->SendDebug('CountdownTick', 'Verbleibend: ' . $remaining . 's', 0);

        if ($remaining <= 0) {
            $this->SetTimerInterval('CountdownTimer', 0);
            $this->SetValue('Countdown', 0);
            $this->SetSwitch(false);
        } else {
            $this->SetValue('Countdown', $remaining);
        }
    }
}
