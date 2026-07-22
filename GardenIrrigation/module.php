<?php

/**
 * GardenIrrigation – Bewässerungssteuerung für IP-Symcon
 *
 * Zonen:
 *   1 = Rasen        → Hauptventil + ValveRasen
 *   2 = Hecke        → Hauptventil + ValveHecke
 *   3 = Hang         → Hauptventil + ValveHang (gemeinsam) + ValveHang2 (Ausgang Hang)
 *   4 = Hecke Garage → Hauptventil + ValveHang (gemeinsam) + ValveGarage (Ausgang Garage)
 *
 * Durchflussmesser-Kalibrierung (logarithmisch):
 *   K(Q) = A · ln(Q) + B   [Pulse/Liter; Q in l/min]
 *   Standardwerte: A = 149,71 | B = 65,507
 */

class GardenIrrigation extends IPSModule {

    // Zonen-Konstanten
    const ZONE_NONE          = 0;
    const ZONE_RASEN         = 1;
    const ZONE_HECKE         = 2;
    const ZONE_HANG          = 3;
    const ZONE_HECKE_GARAGE  = 4;

    const ZONE_NAMES = [
        self::ZONE_NONE         => 'Keine',
        self::ZONE_RASEN        => 'Rasen',
        self::ZONE_HECKE        => 'Hecke',
        self::ZONE_HANG         => 'Hang',
        self::ZONE_HECKE_GARAGE => 'Hecke Garage',
    ];

    // Wochentag-Index (date('N'): 1=Mo … 7=So) → Property-Suffix
    const DAY_PROPS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

    // Zonenprefix → Zonen-ID
    const ZONE_PREFIX = [
        'Rasen'  => self::ZONE_RASEN,
        'Hecke'  => self::ZONE_HECKE,
        'Hang'   => self::ZONE_HANG,
        'Garage' => self::ZONE_HECKE_GARAGE,
    ];

    // =========================================================================
    // LIFECYCLE
    // =========================================================================

    public function Create() {
        parent::Create();

        // ── Ventile & Pumpe ──────────────────────────────────────────────────
        $this->RegisterPropertyInteger('MainValveID',   0);
        $this->RegisterPropertyInteger('ValveRasenID',  0);
        $this->RegisterPropertyInteger('ValveHeckeID',  0);
        $this->RegisterPropertyInteger('ValveHangID',    0);
        $this->RegisterPropertyInteger('ValveHang2ID',  0); // Ausgangsventil Hang
        $this->RegisterPropertyInteger('ValveGarageID', 0); // Ausgangsventil Hecke Garage
        // Variablen-IDs für den Countdown-Wert des Verteilerventils
        $this->RegisterPropertyInteger('ValveHang2CountdownVarID',  0); // Countdown-Variable Ausgang Hang
        $this->RegisterPropertyInteger('ValveGarageCountdownVarID', 0); // Countdown-Variable Ausgang Hecke Garage
        $this->RegisterPropertyInteger('FertPumpID',    0);

        // ── Sensoren ─────────────────────────────────────────────────────────
        $this->RegisterPropertyInteger('SoilHeckeID',   0);
        $this->RegisterPropertyInteger('SoilGarageID',  0); // Bodenfeuchte Hecke Garage
        $this->RegisterPropertyInteger('SoilHangID',    0);
        $this->RegisterPropertyInteger('RainSensorID',  0);
        $this->RegisterPropertyInteger('TempSensorID',  0);
        $this->RegisterPropertyInteger('FlowCounterID', 0);

        // ── Durchflussmesser ─────────────────────────────────────────────────
        $this->RegisterPropertyFloat('FlowCalibA', 149.71);
        $this->RegisterPropertyFloat('FlowCalibB',  65.507);

        // ── Zonen (Rasen, Hecke, Hang, Garage) ───────────────────────────────
        foreach (array_keys(self::ZONE_PREFIX) as $z) {
            $this->RegisterPropertyBoolean($z . 'Enabled',      $z !== 'Garage');
            $this->RegisterPropertyFloat(  $z . 'TargetLiters', $z === 'Rasen' ? 80.0 : ($z === 'Hecke' ? 40.0 : 30.0));
            $this->RegisterPropertyFloat(  $z . 'MaxLiters',    $z === 'Rasen' ? 200.0 : ($z === 'Hecke' ? 120.0 : 100.0));
            $this->RegisterPropertyBoolean($z . 'FertEnabled',  false);
            $this->RegisterPropertyFloat(  $z . 'FertMl',       50.0); // absolut in ml
            $this->RegisterPropertyInteger($z . 'ScheduleTime', 21600); // 06:00
            // Tage (Mo-So)
            foreach (self::DAY_PROPS as $day) {
                $default = ($z === 'Hecke' || $z === 'Garage')
                    ? in_array($day, ['Mo', 'Mi', 'Fr'])
                    : true;
                $this->RegisterPropertyBoolean($z . 'Day' . $day, $default);
            }
            // Feuchtigkeitsschwelle (alle Zonen, bei Zonen ohne Sensor schlicht ignoriert)
            $this->RegisterPropertyFloat($z . 'MoistureThreshold', 70.0);
            // Regen-Schwelle in mm – nicht bewässern wenn Regensensor ≥ diesem Wert
            $this->RegisterPropertyFloat($z . 'RainThresholdMm', 3.0);
            // Dünger-Wochentage (Teilmenge der Bewässerungs-Tage)
            foreach (self::DAY_PROPS as $day) {
                $this->RegisterPropertyBoolean($z . 'FertDay' . $day, false);
            }
        }

        // ── Countdown für 2-Wege-Ventil (Hang + Garage) ─────────────────────
        // 0 = deaktiviert (nur nach Volumen stoppen), 1-120 = Laufzeit in Minuten
        $this->RegisterPropertyInteger('HangCountdownMin',   0);
        $this->RegisterPropertyInteger('GarageCountdownMin', 0);

        // ── Dünger global ────────────────────────────────────────────────────
        $this->RegisterPropertyBoolean('FertEnabled',       false);
        $this->RegisterPropertyFloat(  'FertRatioPercent',  4.0);  // % Dünger im Wasser
        $this->RegisterPropertyInteger('FertDelaySeconds',  15);

        // ── Globale Einstellungen ─────────────────────────────────────────────
        $this->RegisterPropertyInteger('RainBlockHours',     24);
        $this->RegisterPropertyFloat(  'TempBoostThreshold', 30.0);
        $this->RegisterPropertyFloat(  'TempBoostPercent',   10.0);
        $this->RegisterPropertyFloat(  'LeakFlowThreshold',  1.0);
        $this->RegisterPropertyInteger('MaxZoneRuntimeMin',  60);
        $this->RegisterPropertyFloat(  'WaterPrice',         2.0);

        // ── Push-Benachrichtigungen ───────────────────────────────────────────
        $this->RegisterPropertyInteger('PushTargetID',    0);
        $this->RegisterPropertyBoolean('PushOnStart',     false);
        $this->RegisterPropertyBoolean('PushOnEnd',       false);
        $this->RegisterPropertyBoolean('PushOnProblem',   false);
        $this->RegisterPropertyBoolean('PushOnRain',      false);
        $this->RegisterPropertyInteger('FlowWatchdogMin', 3);

        // ── Profile vorab anlegen (müssen vor RegisterVariable* existieren) ───
        $this->ensureProfiles();

        // ── Laufzeit-Attribute ────────────────────────────────────────────────
        $this->RegisterAttributeInteger('CurrentZone',        0);
        $this->RegisterAttributeString( 'ZoneQueue',          '[]');
        $this->RegisterAttributeInteger('ZoneStartTime',      0);
        $this->RegisterAttributeFloat(  'ZoneVolumeLiters',   0.0);
        $this->RegisterAttributeFloat(  'ZoneTargetLiters',   0.0);
        $this->RegisterAttributeFloat(  'FertDispensedMl',    0.0);
        $this->RegisterAttributeFloat(  'LastPulseCountF',     0.0);
        $this->RegisterAttributeFloat(  'LastPulseTimeF',      0.0); // microtime(true)
        $this->RegisterAttributeFloat(  'CurrentFlowRate',    0.0);
        $this->RegisterAttributeInteger('RainBlockUntil',     0);
        $this->RegisterAttributeBoolean('FertPumpRunning',     false);
        $this->RegisterAttributeInteger('RegisteredRainID',  0);
        $this->RegisterAttributeInteger('RegisteredFlowID', 0);

        // ── Statistik-Historie (JSON: {"2026-05-20": 12.3, ...}) ─────────────
        $this->RegisterAttributeString('DailyHistoryTotal',  '{}');
        $this->RegisterAttributeString('DailyHistoryRasen',  '{}');
        $this->RegisterAttributeString('DailyHistoryHecke',  '{}');
        $this->RegisterAttributeString('DailyHistoryHang',   '{}');
        $this->RegisterAttributeString('DailyHistoryGarage', '{}');

        // ── Variablen ────────────────────────────────────────────────────────
        $this->RegisterVariableString( 'Status',       'Status',       '',                   0);
        $this->RegisterVariableBoolean('AutoMode',     'Automatik',    '~Switch',            1);
        $this->RegisterVariableFloat(  'RainValue',    'Regen',        'GardenIrr.RainMm',   3);
        $this->RegisterVariableBoolean('RainBlocked',  'Regen-Sperre', '~Switch',            4);
        // Pos 5–8: Sensor-Links (werden in ApplyChanges per ensureSensorLink angelegt)
        $this->RegisterVariableBoolean('EmergencyStop','Notaus',       '~Switch',            11);
        $this->RegisterVariableBoolean('LeakDetected', 'Leck erkannt', '~Alert',             12);
        // Laufzeit-Variablen (pos 13–16, standardmäßig ausgeblendet)
        $this->RegisterVariableInteger('ActiveZone',   'Aktive Zone',  'GardenIrr.Zone',     13);
        $this->RegisterVariableFloat(  'FlowRate',     'Durchfluss',   'GardenIrr.FlowRate', 14);
        $this->RegisterVariableFloat(  'ZoneVolume',   'Volumen Zone', 'GardenIrr.Volume',   15);
        $this->RegisterVariableFloat(  'TargetVolume', 'Ziel-Volumen', 'GardenIrr.Volume',   16);

        IPS_SetIcon($this->GetIDForIdent('Status'),       'Plant');
        IPS_SetIcon($this->GetIDForIdent('ActiveZone'),   'Irrigation');
        IPS_SetIcon($this->GetIDForIdent('FlowRate'),     'Gauge');
        IPS_SetIcon($this->GetIDForIdent('RainValue'),    'Cloud');
        IPS_SetIcon($this->GetIDForIdent('RainBlocked'),  'Cloud');
        IPS_SetIcon($this->GetIDForIdent('LeakDetected'), 'Alert');
        IPS_SetIcon($this->GetIDForIdent('AutoMode'),     'Execute');
        IPS_SetIcon($this->GetIDForIdent('EmergencyStop'),'Alert');

        // ── Timer ─────────────────────────────────────────────────────────────
        $id = $this->InstanceID;
        $this->RegisterTimer('ScheduleTimer',  0, "GardenIrr_ScheduleTick($id);");
        $this->RegisterTimer('ZoneTimer',      0, "GardenIrr_ZoneSafetyTick($id);");
        $this->RegisterTimer('FlowTimer',      0, "GardenIrr_FlowTick($id);");
        $this->RegisterTimer('LeakTimer',      0, "GardenIrr_LeakTick($id);");
        $this->RegisterTimer('FertStartTimer', 0, "GardenIrr_FertStartTick($id);");
        $this->RegisterTimer('MidnightTimer',  0, "GardenIrr_MidnightTick($id);");
        $this->RegisterTimer('NoFlowTimer',    0, "GardenIrr_NoFlowTick($id);");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->ensureProfiles();
        $this->ensureStatistikCategory();
        $this->ensureKonfigurationCategory();
        $this->ensureManualCategory();
        $this->cleanupOldVariables();

        $this->EnableAction('AutoMode');
        $this->EnableAction('EmergencyStop');

        // Regen-Sensor abonnieren
        $oldRainID = $this->ReadAttributeInteger('RegisteredRainID');
        if ($oldRainID != 0) {
            $this->UnregisterMessage($oldRainID, VM_UPDATE);
        }
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID != 0 && IPS_VariableExists($rainID)) {
            $this->RegisterMessage($rainID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredRainID', $rainID);
        } else {
            $this->WriteAttributeInteger('RegisteredRainID', 0);
        }

        // Durchflusszähler abonnieren (event-driven statt Timer)
        $oldFlowID = $this->ReadAttributeInteger('RegisteredFlowID');
        if ($oldFlowID != 0) {
            $this->UnregisterMessage($oldFlowID, VM_UPDATE);
        }
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID != 0 && IPS_VariableExists($flowID)) {
            $this->RegisterMessage($flowID, VM_UPDATE);
            $this->WriteAttributeInteger('RegisteredFlowID', $flowID);
            $this->WriteAttributeFloat('LastPulseCountF', GetValueFloat($flowID));
            $this->WriteAttributeFloat('LastPulseTimeF',   (float)microtime(true));
        } else {
            $this->WriteAttributeInteger('RegisteredFlowID', 0);
        }

        // Sensor-Links anlegen (Archivzugriff über Links möglich)
        $this->ensureSensorLink('SoilHeckeLnk',  'Bodenfeuchte Hecke',         'Drops',       5,
            $this->ReadPropertyInteger('SoilHeckeID'));
        $this->ensureSensorLink('SoilGarageLnk', 'Bodenfeuchte Hecke Garage',  'Drops',       6,
            $this->ReadPropertyInteger('SoilGarageID'));
        $this->ensureSensorLink('SoilHangLnk',   'Bodenfeuchte Hang',          'Drops',       7,
            $this->ReadPropertyInteger('SoilHangID'));
        $this->ensureSensorLink('TempLnk',        'Temperatur',                 'Temperature', 8,
            $this->ReadPropertyInteger('TempSensorID'));

        // Leck-Timer starten wenn keine Zone läuft
        if ($this->ReadAttributeInteger('CurrentZone') == self::ZONE_NONE) {
            $this->SetTimerInterval('LeakTimer', 30 * 1000);
        }

        // Laufzeit-Variablen nur einblenden wenn tatsächlich eine Zone aktiv ist
        $this->setRunningVarsVisible($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE);

        // Regenwert und RainBlocked-Anzeige aktualisieren
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID != 0 && IPS_VariableExists($rainID)) {
            $this->SetValue('RainValue', round(GetValueFloat($rainID), 1));
        }
        $this->SetValue('RainBlocked', $this->isRainBlockedForAnyZone());

        $this->scheduleNextMidnight();
        $this->scheduleNext();
        $this->updateStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) return;

        $rainID = $this->ReadAttributeInteger('RegisteredRainID');
        if ($rainID != 0 && $SenderID == $rainID) {
            $this->onRainUpdate();
            return;
        }

        $flowID = $this->ReadAttributeInteger('RegisteredFlowID');
        if ($flowID != 0 && $SenderID == $flowID) {
            $this->onFlowUpdate($TimeStamp);
            return;
        }

    }

    public function RequestAction($ident, $value) {
        // ── Konfigurationskategorie: alle Konf*-Variablen ────────────────────
        if (substr($ident, 0, 4) === 'Konf') {
            $this->handleKonfChange($ident, $value);
            return;
        }

        switch ($ident) {
            case 'AutoMode':
                $this->SetValue('AutoMode', (bool)$value);
                if ((bool)$value) {
                    $this->scheduleNext();
                } else {
                    $this->SetTimerInterval('ScheduleTimer', 0);
                }
                break;

            case 'EmergencyStop':
                if ((bool)$value) {
                    if ($this->ReadPropertyBoolean('PushOnProblem')) {
                        $zone = $this->ReadAttributeInteger('CurrentZone');
                        if ($zone != self::ZONE_NONE) {
                            $vol = $this->ReadAttributeFloat('ZoneVolumeLiters');
                            $this->sendPush('Bewässerung manuell gestoppt', sprintf('%s: %.1f L bewässert', self::ZONE_NAMES[$zone], $vol));
                        }
                    }
                    $this->StopAll();
                    $this->SetValue('EmergencyStop', false);
                }
                break;

            case 'ManualStart':
                if ((bool)$value) {
                    $this->handleManualStart();
                    $this->setManualVar('ManualStart', false);
                }
                break;

            case 'ManualZone':
                $this->setManualVar('ManualZone', (int)$value);
                break;

            case 'ManualLiters':
                $this->setManualVar('ManualLiters', (float)$value);
                break;
        }
    }

    // =========================================================================
    // ÖFFENTLICHE API
    // =========================================================================

    /**
     * Startet eine Zone mit gewünschtem Zielvolumen.
     * Temperatur-Boost wird automatisch angewendet.
     */
    public function StartZone(int $zone, float $targetLiters) {
        if ($zone < self::ZONE_RASEN || $zone > self::ZONE_HECKE_GARAGE) {
            $this->SendDebug('StartZone', 'Ungültige Zone: ' . $zone, 0);
            return;
        }
        if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) {
            $this->SendDebug('StartZone', 'Zone bereits aktiv: ' . $this->ReadAttributeInteger('CurrentZone'), 0);
            return;
        }

        $adjusted = $this->applyTemperatureFactor($targetLiters);
        $name     = self::ZONE_NAMES[$zone];
        $this->SendDebug('StartZone', sprintf('Starte %s: %.1f L (Temp-angepasst von %.1f L)', $name, $adjusted, $targetLiters), 0);

        // Zustand setzen
        $this->WriteAttributeInteger('CurrentZone',      $zone);
        $this->WriteAttributeInteger('ZoneStartTime',    time());
        $this->WriteAttributeFloat(  'ZoneVolumeLiters', 0.0);
        $this->WriteAttributeFloat(  'ZoneTargetLiters', $adjusted);
        $this->WriteAttributeBoolean('FertPumpRunning',  false);
        $this->WriteAttributeFloat(  'FertDispensedMl',  0.0);

        // Puls-Referenz
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID != 0 && IPS_VariableExists($flowID)) {
            $this->WriteAttributeFloat('LastPulseCountF', GetValueFloat($flowID));
            $this->WriteAttributeFloat('LastPulseTimeF',   (float)microtime(true));
        }

        // Anzeige
        $this->setRunningVarsVisible(true);
        $this->SetValue('ActiveZone',   $zone);
        $this->SetValue('ZoneVolume',   0.0);
        $this->SetValue('TargetVolume', $adjusted);

        // Ventile öffnen
        $this->openZoneValves($zone);

        // Sicherheits-/Countdown-Timer
        // Für Hang + Garage: per-Zone Countdown nutzbar, sonst globaler Safety-Timeout
        $prefix       = $this->zonePrefixById($zone);
        $countdownMin = ($prefix) ? $this->ReadPropertyInteger($prefix . 'CountdownMin') : 0;
        $safetyMin    = $this->ReadPropertyInteger('MaxZoneRuntimeMin');
        $timerMin     = ($countdownMin > 0) ? min($countdownMin, $safetyMin) : $safetyMin;
        $this->SetTimerInterval('ZoneTimer', $timerMin * 60 * 1000);

        // Watchdog: wenn 60s kein Durchfluss-Update → Durchfluss = 0
        $this->SetTimerInterval('FlowTimer', 60000);

        // Kein-Fluss-Watchdog
        $watchdogMin = $this->ReadPropertyInteger('FlowWatchdogMin');
        if ($watchdogMin > 0) {
            $this->SetTimerInterval('NoFlowTimer', $watchdogMin * 60 * 1000);
        }

        // Leck-Timer pausieren
        $this->SetTimerInterval('LeakTimer', 0);

        // Dünger planen
        $this->scheduleFertStart($zone);

        // Push: Start
        if ($this->ReadPropertyBoolean('PushOnStart')) {
            $moisture = $this->getSoilMoistureForZone($zone);
            $isFert   = $this->isFertActiveToday($zone);
            $msg = sprintf('%s: %.1f L geplant', $name, $adjusted);
            if ($isFert)         { $msg .= ' + Düngung'; }
            if ($moisture !== null) { $msg .= sprintf(' | Feuchte: %.0f%%', $moisture); }
            $this->sendPush('Bewässerung gestartet', $msg);
        }

        $this->updateStatus();
    }

    /**
     * Stoppt alle Ventile sofort (Notaus / Programmende).
     */
    public function StopAll() {
        // Aufrufer ermitteln für Debug-Trace
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        $caller = isset($trace[1]) ? ($trace[1]['function'] ?? '?') : '?';
        $caller2 = isset($trace[2]) ? ($trace[2]['function'] ?? '') : '';
        $this->SendDebug('StopAll', sprintf('Aufruf von: %s%s', $caller, $caller2 ? ' ← ' . $caller2 : ''), 0);
        $this->SendDebug('StopAll', 'Alle Ventile werden geschlossen', 0);

        $this->SetTimerInterval('ZoneTimer',      0);
        $this->SetTimerInterval('FlowTimer',      0);
        $this->SetTimerInterval('FertStartTimer', 0);
        $this->SetTimerInterval('NoFlowTimer',    0);

        $this->stopFertPump();
        $this->closeAllValves();

        $this->WriteAttributeInteger('CurrentZone',      self::ZONE_NONE);
        $this->WriteAttributeFloat(  'ZoneVolumeLiters', 0.0);
        $this->WriteAttributeFloat(  'ZoneTargetLiters', 0.0);
        $this->WriteAttributeBoolean('FertPumpRunning',  false);
        $this->WriteAttributeString( 'ZoneQueue',        '[]');

        $this->SetValue('ActiveZone',   self::ZONE_NONE);
        $this->SetValue('ZoneVolume',   0.0);
        $this->SetValue('TargetVolume', 0.0);
        $this->SetValue('FlowRate',     0.0);
        $this->setRunningVarsVisible(false);

        $this->SetTimerInterval('LeakTimer', 30 * 1000);
        $this->updateStatus();
    }

    /**
     * Zeitplan-Tick – aufgerufen vom ScheduleTimer.
     */
    public function ScheduleTick() {
        if (!$this->GetValue('AutoMode')) return;

        $this->SendDebug('Schedule', 'Tick ausgelöst', 0);

        if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) {
            $this->scheduleNext();
            return;
        }

        // Queue aufbauen: nur Zonen, deren geplante Zeit ±5 Min. um jetzt liegt.
        // buildQueueForNow() verhindert, dass Zonen anderer Uhrzeiten mit gestartet werden.
        $queue = json_decode($this->ReadAttributeString('ZoneQueue'), true);
        if (!is_array($queue) || empty($queue)) {
            $queue = $this->buildQueueForNow();
            $this->WriteAttributeString('ZoneQueue', json_encode($queue));
        }

        if (empty($queue)) {
            $this->SendDebug('Schedule', 'Keine Zonen für heute', 0);
            $this->scheduleNext();
            return;
        }

        $next = array_shift($queue);
        $this->WriteAttributeString('ZoneQueue', json_encode($queue));
        $this->startZoneFromConfig($next);
    }

    /**
     * Mitternachts-Tick – täglich um 0:00 Uhr.
     * Aktualisiert Statistik (Heute = 0, Letzte 7 Tage = Rollwert)
     * und plant den nächsten Tick für die folgende Mitternacht.
     */
    public function MidnightTick() {
        $this->SendDebug('Midnight', 'Tagesreset – Statistik wird aktualisiert', 0);
        $this->updateStatistik();
        $this->scheduleNextMidnight();
    }

    /** Berechnet die Millisekunden bis zur nächsten Mitternacht und startet den Timer. */
    private function scheduleNextMidnight() {
        // Nächste Mitternacht = Beginn des nächsten Tages + 5s Puffer
        $nextMidnight = mktime(0, 0, 5, (int)date('n'), (int)date('j') + 1);
        $ms           = max(60000, ($nextMidnight - time()) * 1000);
        $this->SetTimerInterval('MidnightTimer', $ms);
        $this->SendDebug('Midnight', 'Nächster Reset um ' . date('d.m.Y 00:00:05', $nextMidnight), 0);
    }

    /**
     * Sicherheits-Timeout – maximale Zonenlaufzeit überschritten.
     */
    public function ZoneSafetyTick() {
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone == self::ZONE_NONE) return;
        $this->SendDebug('Safety', 'Timeout Zone ' . self::ZONE_NAMES[$zone], 0);
        $this->finishZone($zone, true);
    }

    /**
     * Kein-Fluss-Watchdog – wird gefeuert wenn seit N Minuten kein positiver Durchfluss gemessen wurde.
     */
    public function NoFlowTick() {
        $this->SetTimerInterval('NoFlowTimer', 0);
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone == self::ZONE_NONE) return;

        $min = $this->ReadPropertyInteger('FlowWatchdogMin');
        $this->SendDebug('NoFlow', sprintf('Kein Durchfluss seit %d Min – stoppe Zone %s', $min, self::ZONE_NAMES[$zone]), 0);

        if ($this->ReadPropertyBoolean('PushOnProblem')) {
            $this->sendPush('Kein Durchfluss', sprintf('%s: Kein Wasser seit %d Min – Zone gestoppt', self::ZONE_NAMES[$zone], $min));
        }
        $this->StopAll();
    }

    /**
     * FlowTick – Watchdog: wird gefeuert wenn 60s kein VM_UPDATE vom Durchflusszähler kam.
     * → kein Durchfluss mehr, Rate auf 0 setzen.
     * Viele Zähler senden VM_UPDATE nur alle 20–60s (aggregierte Werte), daher 60s Intervall.
     */
    public function FlowTick() {
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone == self::ZONE_NONE) return;

        $this->SendDebug('Flow', 'Watchdog: kein Zähler-Update seit 60s → Rate = 0', 0);
        $this->SetValue('FlowRate', 0.0);
        $this->WriteAttributeFloat('CurrentFlowRate', 0.0);
        // Watchdog weiter laufen lassen (bleibt auf 60s)
    }

    /**
     * Wird bei jeder VM_UPDATE-Nachricht des Durchflusszählers aufgerufen.
     * Berechnet Durchfluss und akkumuliert Volumen event-getrieben.
     *
     * WICHTIG: $TimeStamp aus MessageSink ist ein ganzzahliger Unix-Timestamp
     * (IPS-intern), daher wird microtime(true) für konsistente Zeitmessung genutzt.
     */
    private function onFlowUpdate(float $timestamp) {
        $now    = (float)microtime(true); // eigenen hochauflösenden Zeitstempel verwenden
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID == 0 || !IPS_VariableExists($flowID)) return;

        $currentCount = GetValueFloat($flowID);
        $lastCount    = $this->ReadAttributeFloat('LastPulseCountF');
        $lastTime     = $this->ReadAttributeFloat('LastPulseTimeF');
        $deltaTime    = $now - $lastTime;
        $deltaPulses  = $currentCount - $lastCount;

        // Referenzwerte immer aktualisieren
        $this->WriteAttributeFloat('LastPulseCountF', $currentCount);
        $this->WriteAttributeFloat('LastPulseTimeF',  $now);

        // Watchdog zurücksetzen (60s – Zähler sendet VM_UPDATE ggf. nur alle 20–60s)
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone != self::ZONE_NONE) {
            $this->SetTimerInterval('FlowTimer', 60000);
        }

        if ($deltaPulses <= 0) {
            $this->SetValue('FlowRate', 0.0);
            $this->WriteAttributeFloat('CurrentFlowRate', 0.0);
            return;
        }

        // Kein-Fluss-Watchdog zurücksetzen, da positive Pulse vorhanden
        if ($zone != self::ZONE_NONE) {
            $watchdogMin = $this->ReadPropertyInteger('FlowWatchdogMin');
            if ($watchdogMin > 0) {
                $this->SetTimerInterval('NoFlowTimer', $watchdogMin * 60 * 1000);
            }
        }

        // Anlaufsperre: erste 3s nach Zonenstart ignorieren (vorgequeute Updates /
        // Rohrfüllung können Delta-Zeit nahe 0 erzeugen und Phantomvolumen addieren)
        $zoneStartTime = $this->ReadAttributeInteger('ZoneStartTime');
        if ($zone != self::ZONE_NONE && $zoneStartTime > 0 && ($now - $zoneStartTime) < 3.0) {
            $this->SendDebug('Flow', sprintf('Anlaufsperre (%.1fs seit Start) – überspringe Update', $now - $zoneStartTime), 0);
            return;
        }

        // Mindest-Zeitfenster: deltaTime < 0.5s → zu kurz für genaue Messung, überspringen
        if ($deltaTime < 0.5) {
            $this->SendDebug('Flow', sprintf('Δt=%.3fs zu klein – überspringe', $deltaTime), 0);
            return;
        }

        // Durchfluss berechnen
        $pulsesPerSec = $deltaPulses / $deltaTime;
        $flowRate     = $this->calculateFlowRate($pulsesPerSec);
        $this->WriteAttributeFloat('CurrentFlowRate', $flowRate);
        $this->SetValue('FlowRate', round($flowRate, 1));

        // Volumen nur akkumulieren wenn Zone aktiv
        if ($zone == self::ZONE_NONE) return;

        $K           = $this->calculateK($flowRate);
        $deltaLiters = ($K > 0) ? ($deltaPulses / $K) : 0.0;
        $newVolume   = $this->ReadAttributeFloat('ZoneVolumeLiters') + $deltaLiters;

        $this->WriteAttributeFloat('ZoneVolumeLiters', $newVolume);
        $this->SetValue('ZoneVolume', round($newVolume, 1));
        $this->accumulateDailyVolume($deltaLiters, $zone);

        $target = $this->ReadAttributeFloat('ZoneTargetLiters');

        $this->SendDebug('Flow', sprintf(
            'Q=%.2f l/min | Δt=%.3fs | ΔP=%.1f | K=%.0f P/L | ΔV=%.3f L | %.1f / %.1f L',
            $flowRate, $deltaTime, $deltaPulses, $K, $deltaLiters, $newVolume, $target
        ), 0);

        // Düngerpumpe: Zielmenge in ml erreicht?
        if ($this->ReadAttributeBoolean('FertPumpRunning')) {
            $ratio     = $this->ReadPropertyFloat('FertRatioPercent') / 100.0;
            $dispensed = $this->ReadAttributeFloat('FertDispensedMl') + $deltaLiters * $ratio * 1000.0;
            $this->WriteAttributeFloat('FertDispensedMl', $dispensed);

            $prefix       = $this->zonePrefixById($zone);
            $targetFertMl = $prefix ? $this->ReadPropertyFloat($prefix . 'FertMl') : 0.0;

            if ($targetFertMl > 0 && $dispensed >= $targetFertMl) {
                $this->SendDebug('Fert', sprintf('%.0f ml Dünger erreicht – stoppe Pumpe (Spülung läuft)', $dispensed), 0);
                $this->stopFertPump();
            }
        }

        // Zielvolumen erreicht?
        if ($newVolume >= $target) {
            $this->SendDebug('Flow', sprintf('Zielvolumen erreicht: %.1f >= %.1f L → finishZone', $newVolume, $target), 0);
            $this->finishZone($zone, false);
        } else {
            $this->updateStatus();
        }
    }

    /**
     * Leck-Tick – alle 30 Sek. bei geschlossenen Ventilen.
     */
    public function LeakTick() {
        if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) return;

        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID == 0 || !IPS_VariableExists($flowID)) return;

        $now          = (float)microtime(true);
        $currentCount = GetValueFloat($flowID);
        $lastCount    = $this->ReadAttributeFloat('LastPulseCountF');
        $lastTime     = $this->ReadAttributeFloat('LastPulseTimeF');
        $deltaTime    = max(1.0, $now - $lastTime);
        $deltaPulses  = $currentCount - $lastCount;

        $this->WriteAttributeFloat('LastPulseCountF', $currentCount);
        $this->WriteAttributeFloat('LastPulseTimeF',   $now);

        if ($deltaPulses <= 0) {
            $this->SetValue('LeakDetected', false);
            return;
        }

        $pulsesPerSec = $deltaPulses / $deltaTime;
        $flowRate     = $this->calculateFlowRate($pulsesPerSec);
        $threshold    = $this->ReadPropertyFloat('LeakFlowThreshold');

        if ($flowRate >= $threshold) {
            $this->SendDebug('Leak', sprintf('Leck! %.1f l/min bei geschlossenen Ventilen', $flowRate), 0);
            if (!$this->GetValue('LeakDetected') && $this->ReadPropertyBoolean('PushOnProblem')) {
                $this->sendPush('Leck erkannt', sprintf('Durchfluss %.1f l/min bei geschlossenen Ventilen!', $flowRate));
            }
            $this->SetValue('LeakDetected', true);
            // Hauptventil als Schutz schließen
            $mainID = $this->ReadPropertyInteger('MainValveID');
            $this->setValve($mainID, false, 'Main[Leck]');
        } else {
            $this->SetValue('LeakDetected', false);
        }
    }

    /**
     * Dünger-Starttick – verzögerter Pumpenstart nach Ventilöffnung.
     */
    public function FertStartTick() {
        $this->SetTimerInterval('FertStartTimer', 0);
        if ($this->ReadAttributeInteger('CurrentZone') == self::ZONE_NONE) return;

        $this->SendDebug('Fert', 'Starte Düngerpumpe', 0);
        $this->WriteAttributeBoolean('FertPumpRunning', true);
        $this->startFertPump();
        $this->updateStatus();
    }

    /**
     * Zeitplan sofort neu berechnen (Form-Button).
     */
    public function RescheduleNow() {
        $this->scheduleNext();
    }

    // =========================================================================
    // PRIVATE – ZONENBETRIEB
    // =========================================================================

    private function startZoneFromConfig(array $cfg) {
        $zone         = (int)$cfg['zone'];
        $targetLiters = (float)$cfg['targetLiters'];

        $this->SendDebug('Zone', sprintf('Prüfe %s (%.1f L)', self::ZONE_NAMES[$zone], $targetLiters), 0);

        if ($this->isRainBlockedForZone($zone)) {
            $this->SendDebug('Zone', self::ZONE_NAMES[$zone] . ': Regen-Sperre → überspringe', 0);
            if ($this->ReadPropertyBoolean('PushOnRain')) {
                $rainID = $this->ReadPropertyInteger('RainSensorID');
                $mm = ($rainID != 0 && IPS_VariableExists($rainID)) ? GetValueFloat($rainID) : 0.0;
                $this->sendPush('Bewässerung übersprungen', sprintf('%s: Regen-Sperre (%.1f mm)', self::ZONE_NAMES[$zone], $mm));
            }
            $this->dequeueNext();
            return;
        }

        if ($this->isMoistureOk($zone)) {
            $this->SendDebug('Zone', self::ZONE_NAMES[$zone] . ': Feuchte ausreichend → überspringe', 0);
            $this->dequeueNext();
            return;
        }

        $this->SendDebug('Zone', self::ZONE_NAMES[$zone] . ': Bedingungen OK → starte Zone', 0);
        $this->StartZone($zone, $targetLiters);
    }

    private function finishZone(int $zone, bool $forced) {
        $volume   = $this->ReadAttributeFloat('ZoneVolumeLiters');
        $target   = $this->ReadAttributeFloat('ZoneTargetLiters');
        $duration = time() - $this->ReadAttributeInteger('ZoneStartTime');

        $reason = $forced ? 'Timeout/Safety' : sprintf('Zielvolumen erreicht (%.1f / %.1f L)', $volume, $target);
        $this->SendDebug('Zone', sprintf(
            '%s beendet nach %d Sek. – Grund: %s',
            self::ZONE_NAMES[$zone], $duration, $reason
        ), 0);

        // Zuerst Zustand zurücksetzen, DANN Push – sonst kommen währd des blockierenden
        // WFC_PushNotification-Aufrufs weitere VM_UPDATE-Ereignisse und rufen finishZone
        // erneut auf (CurrentZone noch nicht ZONE_NONE → Race Condition).
        $this->SetTimerInterval('ZoneTimer',      0);
        $this->SetTimerInterval('FlowTimer',      0);
        $this->SetTimerInterval('FertStartTimer', 0);
        $this->SetTimerInterval('NoFlowTimer',    0);

        $this->stopFertPump();
        $this->closeAllValves();

        $this->WriteAttributeInteger('CurrentZone',      self::ZONE_NONE);
        $this->WriteAttributeFloat(  'ZoneVolumeLiters', 0.0);
        $this->WriteAttributeFloat(  'ZoneTargetLiters', 0.0);
        $this->WriteAttributeBoolean('FertPumpRunning',  false);

        $this->SetValue('ActiveZone',   self::ZONE_NONE);
        $this->SetValue('ZoneVolume',   0.0);
        $this->SetValue('TargetVolume', 0.0);
        $this->SetValue('FlowRate',     0.0);

        $this->SetTimerInterval('LeakTimer', 30 * 1000);

        // Push erst nach dem Reset (concurrent onFlowUpdate sieht jetzt ZONE_NONE und bricht ab)
        if (!$forced && $this->ReadPropertyBoolean('PushOnEnd')) {
            $moisture = $this->getSoilMoistureForZone($zone);
            $msg = sprintf('%s: %.1f L', self::ZONE_NAMES[$zone], $volume);
            if ($moisture !== null) { $msg .= sprintf(' | Feuchte: %.0f%%', $moisture); }
            $this->sendPush('Bewässerung beendet', $msg);
        } elseif ($forced && $this->ReadPropertyBoolean('PushOnProblem')) {
            $this->sendPush('Bewässerung gestoppt', sprintf('%s: Sicherheits-Timeout nach %.1f L', self::ZONE_NAMES[$zone], $volume));
        }

        $this->dequeueNext();
    }

    private function dequeueNext() {
        $queue = json_decode($this->ReadAttributeString('ZoneQueue'), true);
        if (is_array($queue) && !empty($queue)) {
            // Kurze Pause zwischen Zonen
            sleep(3);
            $next = array_shift($queue);
            $this->WriteAttributeString('ZoneQueue', json_encode($queue));
            $this->startZoneFromConfig($next);
        } else {
            $this->WriteAttributeString('ZoneQueue', '[]');
            $this->setRunningVarsVisible(false);
            $this->scheduleNext();
            $this->updateStatus();
        }
    }

    /** Blendet Laufzeit-Variablen ein oder aus. */
    private function setRunningVarsVisible(bool $visible) {
        $hidden = !$visible;
        IPS_SetHidden($this->GetIDForIdent('ActiveZone'),   $hidden);
        IPS_SetHidden($this->GetIDForIdent('FlowRate'),     $hidden);
        IPS_SetHidden($this->GetIDForIdent('ZoneVolume'),   $hidden);
        IPS_SetHidden($this->GetIDForIdent('TargetVolume'), $hidden);
    }

    // =========================================================================
    // PRIVATE – VENTILSTEUERUNG
    // =========================================================================

    private function openZoneValves(int $zone) {
        $mainID    = $this->ReadPropertyInteger('MainValveID');
        $rasenID   = $this->ReadPropertyInteger('ValveRasenID');
        $heckeID   = $this->ReadPropertyInteger('ValveHeckeID');
        $hangID    = $this->ReadPropertyInteger('ValveHangID');
        $hang2ID   = $this->ReadPropertyInteger('ValveHang2ID');
        $garageID  = $this->ReadPropertyInteger('ValveGarageID');

        $this->SendDebug('Valves', sprintf(
            'openZoneValves(%s) – IDs: Main=%d Rasen=%d Hecke=%d HangEin=%d HangAus=%d Garage=%d',
            self::ZONE_NAMES[$zone], $mainID, $rasenID, $heckeID, $hangID, $hang2ID, $garageID
        ), 0);

        // Nur Ventile schließen, die für diese Zone NICHT benötigt werden.
        // Zone-eigene Ventile werden bewusst NICHT geschlossen – eine AUS→AN-Sequenz
        // in kurzer Folge kann auf manchen Geräten als Stop/Reset interpretiert werden.
        switch ($zone) {
            case self::ZONE_RASEN:
                $this->setValve($heckeID,  false, 'Hecke');
                $this->setValve($hangID,   false, 'HangEin');
                $this->setValve($hang2ID,  false, 'HangAus');
                $this->setValve($garageID, false, 'Garage');
                usleep(100000); // 100 ms
                $this->setValve($rasenID, true, 'Rasen');
                break;

            case self::ZONE_HECKE:
                $this->setValve($rasenID,  false, 'Rasen');
                $this->setValve($hangID,   false, 'HangEin');
                $this->setValve($hang2ID,  false, 'HangAus');
                $this->setValve($garageID, false, 'Garage');
                usleep(100000); // 100 ms
                $this->setValve($heckeID, true, 'Hecke');
                break;

            case self::ZONE_HANG:
                // Andere Ausgänge schließen – HangEin und HangAus werden NICHT angefasst
                $this->setValve($rasenID,  false, 'Rasen');
                $this->setValve($heckeID,  false, 'Hecke');
                $this->setValve($garageID, false, 'Garage');
                usleep(100000); // 100 ms
                // Countdown BEVOR Ventil öffnet (nur wenn > 0)
                $cdMin   = $this->ReadPropertyInteger('HangCountdownMin');
                $cdVarID = $this->ReadPropertyInteger('ValveHang2CountdownVarID');
                if ($cdMin > 0) {
                    $this->writeCountdownVar($cdVarID, $cdMin);
                    usleep(50000); // 50 ms
                } else {
                    $this->SendDebug('Countdown', sprintf('HangCountdownMin=0 – kein Schreiben an VarID=%d', $cdVarID), 0);
                }
                $this->setValve($hang2ID, true, 'HangAus');
                $this->setValve($hangID,  true, 'HangEin');
                break;

            case self::ZONE_HECKE_GARAGE:
                // Anderen Ausgang schließen – HangEin und Garage werden NICHT angefasst
                $this->setValve($rasenID,  false, 'Rasen');
                $this->setValve($heckeID,  false, 'Hecke');
                $this->setValve($hang2ID,  false, 'HangAus');
                usleep(100000); // 100 ms
                // Countdown BEVOR Ventil öffnet (nur wenn > 0)
                $cdMin   = $this->ReadPropertyInteger('GarageCountdownMin');
                $cdVarID = $this->ReadPropertyInteger('ValveGarageCountdownVarID');
                if ($cdMin > 0) {
                    $this->writeCountdownVar($cdVarID, $cdMin);
                    usleep(50000); // 50 ms
                } else {
                    $this->SendDebug('Countdown', sprintf('GarageCountdownMin=0 – kein Schreiben an VarID=%d', $cdVarID), 0);
                }
                $this->setValve($garageID, true, 'Garage');
                $this->setValve($hangID,   true, 'HangEin');
                break;
        }

        // Hauptventil zuletzt öffnen
        usleep(200000); // 200 ms – Zonenventile öffnen zuerst
        $this->setValve($mainID, true, 'Main');

        $this->SendDebug('Valves', '── openZoneValves abgeschlossen ──', 0);
    }

    private function closeAllValves() {
        $mainID   = $this->ReadPropertyInteger('MainValveID');
        $rasenID  = $this->ReadPropertyInteger('ValveRasenID');
        $heckeID  = $this->ReadPropertyInteger('ValveHeckeID');
        $hangID   = $this->ReadPropertyInteger('ValveHangID');
        $hang2ID  = $this->ReadPropertyInteger('ValveHang2ID');
        $garageID = $this->ReadPropertyInteger('ValveGarageID');

        $this->SendDebug('Valves', sprintf(
            'closeAllValves – IDs: Main=%d Rasen=%d Hecke=%d HangEin=%d HangAus=%d Garage=%d',
            $mainID, $rasenID, $heckeID, $hangID, $hang2ID, $garageID
        ), 0);

        // Hauptventil zuerst – kein Druck mehr in Leitung
        $this->setValve($mainID,   false, 'Main');
        usleep(300000); // 300 ms Druckabbau

        $this->setValve($rasenID,  false, 'Rasen');
        $this->setValve($heckeID,  false, 'Hecke');
        $this->setValve($hangID,   false, 'HangEin');
        $this->setValve($hang2ID,  false, 'HangAus');
        $this->setValve($garageID, false, 'Garage');

        $this->SendDebug('Valves', '── closeAllValves abgeschlossen ──', 0);
    }

    /**
     * Setzt ein Ventil und loggt VarID, Label, bisherigen und neuen Zustand.
     */
    private function setValve(int $varID, bool $state, string $label = '?') {
        if ($varID == 0) {
            $this->SendDebug('Valve', sprintf('[%s] VarID=0 – nicht konfiguriert, überspringe', $label), 0);
            return;
        }
        if (!IPS_VariableExists($varID)) {
            $this->SendDebug('Valve', sprintf('[%s] VarID=%d existiert nicht!', $label, $varID), 0);
            return;
        }
        $current = GetValueBoolean($varID) ? 'AN' : 'AUS';
        $target  = $state ? 'AN' : 'AUS';
        $this->SendDebug('Valve', sprintf('[%s] VarID=%d: %s → %s', $label, $varID, $current, $target), 0);
        try {
            RequestAction($varID, $state);
        } catch (Exception $e) {
            $this->SendDebug('Valve', sprintf('[%s] VarID=%d Fehler: %s', $label, $varID, $e->getMessage()), 0);
        }
    }

    /**
     * Schreibt einen Countdown-Wert (Minuten > 0) in eine externe Variable
     * (z.B. Laufzeit-Countdown des 2-Wege-Verteilerventils).
     * Wird NUR aufgerufen wenn minutes > 0, da 0 das Gerät sofort stoppen kann.
     */
    private function writeCountdownVar(int $varID, int $minutes) {
        if ($varID == 0) {
            $this->SendDebug('Countdown', sprintf('%d min – VarID=0, nicht konfiguriert', $minutes), 0);
            return;
        }
        if (!IPS_VariableExists($varID)) {
            $this->SendDebug('Countdown', sprintf('VarID=%d existiert nicht!', $varID), 0);
            return;
        }
        $current = GetValueInteger($varID);
        $this->SendDebug('Countdown', sprintf('VarID=%d: %d → %d min', $varID, $current, $minutes), 0);
        try {
            RequestAction($varID, $minutes);
        } catch (Exception $e) {
            $this->SendDebug('Countdown', sprintf('VarID=%d Fehler: %s', $varID, $e->getMessage()), 0);
        }
    }

    private function startFertPump() {
        $pumpID = $this->ReadPropertyInteger('FertPumpID');
        $this->setValve($pumpID, true, 'Pumpe');
    }

    private function stopFertPump() {
        if (!$this->ReadAttributeBoolean('FertPumpRunning')) return;
        $this->WriteAttributeBoolean('FertPumpRunning', false);
        $pumpID = $this->ReadPropertyInteger('FertPumpID');
        $this->setValve($pumpID, false, 'Pumpe');
        $this->SendDebug('Fert', 'Pumpe gestoppt', 0);
    }

    private function scheduleFertStart(int $zone) {
        if (!$this->ReadPropertyBoolean('FertEnabled')) return;

        $prefix = $this->zonePrefixById($zone);
        if ($prefix === null) return;

        if (!$this->ReadPropertyBoolean($prefix . 'FertEnabled')) return;

        // Düngung nur an konfigurierten Wochentagen
        $day = self::DAY_PROPS[(int)date('N') - 1]; // 0=Mo … 6=So
        if (!$this->ReadPropertyBoolean($prefix . 'FertDay' . $day)) {
            $this->SendDebug('Fert', 'Heute kein Dünger-Tag für ' . $prefix, 0);
            return;
        }

        $delayMs = $this->ReadPropertyInteger('FertDelaySeconds') * 1000;
        $this->SetTimerInterval('FertStartTimer', max(1000, $delayMs));
        $this->SendDebug('Fert', 'Pumpenstart in ' . $this->ReadPropertyInteger('FertDelaySeconds') . ' Sek.', 0);
    }

    // =========================================================================
    // PRIVATE – ZEITPLAN
    // =========================================================================

    /**
     * Baut eine Queue nur für Zonen, deren geplante Startzeit innerhalb
     * eines ±5-Minuten-Fensters um jetzt liegt.
     *
     * Hintergrund: buildDailyQueue() würde ALLE Zonen des Tages in eine Queue
     * packen – unabhängig von ihrer geplanten Zeit. Dadurch startete z.B.
     * Hecke (06:00) sofort nach Rasen (23:00), weil beide am selben Tag aktiv waren.
     */
    private function buildQueueForNow(): array {
        $dowIdx  = (int)date('N') - 1; // 0=Mo … 6=So
        $day     = self::DAY_PROPS[$dowIdx];
        $now     = time();
        $window  = 5 * 60; // ±5 Minuten
        $queue   = [];

        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            if (!$this->ReadPropertyBoolean($prefix . 'Enabled'))    continue;
            if (!$this->ReadPropertyBoolean($prefix . 'Day' . $day)) continue;

            $schedTime = $this->ReadPropertyInteger($prefix . 'ScheduleTime');
            $hour      = intdiv($schedTime, 3600);
            $min       = intdiv($schedTime % 3600, 60);
            $fireTime  = mktime($hour, $min, 0); // heutiger Timestamp für diese Zeit

            if (abs($now - $fireTime) <= $window) {
                $queue[] = [
                    'zone'         => $zone,
                    'targetLiters' => $this->ReadPropertyFloat($prefix . 'TargetLiters'),
                ];
                $this->SendDebug('Schedule', sprintf(
                    '%s fällig (%s, Δ%ds) – in Queue',
                    self::ZONE_NAMES[$zone], date('H:i', $fireTime), $now - $fireTime
                ), 0);
            } else {
                $this->SendDebug('Schedule', sprintf(
                    '%s (%s) liegt außerhalb des ±5min-Fensters – überspringe',
                    self::ZONE_NAMES[$zone], date('H:i', $fireTime)
                ), 0);
            }
        }

        return $queue;
    }

    private function scheduleNext() {
        if (!$this->GetValue('AutoMode')) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            return;
        }

        $dowIdx   = (int)date('N') - 1;
        $day      = self::DAY_PROPS[$dowIdx];
        $now      = time();
        $earliest = null;

        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            if (!$this->ReadPropertyBoolean($prefix . 'Enabled'))    continue;
            if (!$this->ReadPropertyBoolean($prefix . 'Day' . $day)) continue;

            $schedTime = $this->ReadPropertyInteger($prefix . 'ScheduleTime');
            $hour      = intdiv($schedTime, 3600);
            $min       = intdiv($schedTime % 3600, 60);
            $fireTime  = mktime($hour, $min, 0);

            if ($fireTime <= $now) {
                $fireTime += 86400; // morgen
            }

            if ($earliest === null || $fireTime < $earliest) {
                $earliest = $fireTime;
            }
        }

        if ($earliest === null) {
            $this->SetTimerInterval('ScheduleTimer', 0);
            return;
        }

        $intervalMs = ($earliest - $now) * 1000;
        $this->SetTimerInterval('ScheduleTimer', max(1000, $intervalMs));
        $this->SendDebug('Schedule', 'Nächster Lauf: ' . date('d.m.Y H:i', $earliest), 0);
    }

    // =========================================================================
    // PRIVATE – SENSOREN & FAKTOREN
    // =========================================================================

    private function onRainUpdate() {
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID == 0 || !IPS_VariableExists($rainID)) return;

        $mm = GetValueFloat($rainID);
        $this->SendDebug('Rain', sprintf('Sensor-Wert: %.1f mm', $mm), 0);
        $this->SetValue('RainValue', round($mm, 1));

        // RainBlocked-Variable zeigt an ob IRGENDEINE Zone gesperrt wäre
        $anyBlocked = $this->isRainBlockedForAnyZone($mm);
        $this->SetValue('RainBlocked', $anyBlocked);

        // Laufende Zone stoppen wenn deren Schwelle überschritten
        $currentZone = $this->ReadAttributeInteger('CurrentZone');
        if ($currentZone != self::ZONE_NONE && $this->isRainBlockedForZone($currentZone, $mm)) {
            $this->SendDebug('Rain', 'Laufende Zone ' . self::ZONE_NAMES[$currentZone] . ' wird durch Regen gestoppt', 0);
            if ($this->ReadPropertyBoolean('PushOnRain')) {
                $this->sendPush('Bewässerung gestoppt', sprintf('%s: Regen-Sperre (%.1f mm)', self::ZONE_NAMES[$currentZone], $mm));
            }
            $this->StopAll();
        }
    }

    /** Gibt true zurück wenn der Regensensor-Wert die Schwelle der Zone überschreitet. */
    private function isRainBlockedForZone(int $zone, float $mm = -1.0): bool {
        $rainID = $this->ReadPropertyInteger('RainSensorID');
        if ($rainID == 0 || !IPS_VariableExists($rainID)) return false;

        if ($mm < 0) $mm = GetValueFloat($rainID);
        $prefix = $this->zonePrefixById($zone);
        if (!$prefix) return false;

        $threshold = $this->ReadPropertyFloat($prefix . 'RainThresholdMm');
        if ($threshold <= 0) return false; // 0 = Regen-Sperre deaktiviert

        $blocked = $mm >= $threshold;
        if ($blocked) {
            $this->SendDebug('Rain', sprintf('%s: %.1f mm ≥ Schwelle %.1f mm – überspringe', self::ZONE_NAMES[$zone], $mm, $threshold), 0);
        }
        return $blocked;
    }

    /** Gibt true zurück wenn mindestens eine Zone durch Regen gesperrt wäre. */
    private function isRainBlockedForAnyZone(float $mm = -1.0): bool {
        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            if ($this->isRainBlockedForZone($zone, $mm)) return true;
        }
        return false;
    }

    /**
     * Gibt true zurück wenn die Bodenfeuchte ausreichend ist (Zone überspringen).
     * Sensor-Zuordnung: Hecke → SoilHeckeID | HeckeGarage → SoilGarageID | Hang → SoilHangID | Rasen → kein Sensor
     *
     * Rückgabe true  = Feuchte OK  → Zone ÜBERSPRINGEN (kein Bewässern nötig)
     * Rückgabe false = Feuchte LOW → Zone STARTEN (Bewässerung nötig)
     */
    private function isMoistureOk(int $zone): bool {
        $prefix    = $this->zonePrefixById($zone);
        $threshold = $prefix ? $this->ReadPropertyFloat($prefix . 'MoistureThreshold') : 70.0;

        // Sensor-ID nach Zone ermitteln
        switch ($zone) {
            case self::ZONE_HECKE:
                $sensorID = $this->ReadPropertyInteger('SoilHeckeID');
                $propKey  = 'SoilHeckeID';
                break;
            case self::ZONE_HECKE_GARAGE:
                $sensorID = $this->ReadPropertyInteger('SoilGarageID');
                $propKey  = 'SoilGarageID';
                break;
            case self::ZONE_HANG:
                $sensorID = $this->ReadPropertyInteger('SoilHangID');
                $propKey  = 'SoilHangID';
                break;
            default:
                $this->SendDebug('Moisture', sprintf('%s: kein Sensor vorgesehen → Bewässerung wird gestartet', self::ZONE_NAMES[$zone]), 0);
                return false; // Kein Sensor für diese Zone → nie überspringen
        }

        if ($sensorID == 0) {
            $this->SendDebug('Moisture', sprintf(
                '%s: %s nicht konfiguriert (VarID=0) → Bewässerung wird gestartet',
                self::ZONE_NAMES[$zone], $propKey
            ), 0);
            return false;
        }
        if (!IPS_VariableExists($sensorID)) {
            $this->SendDebug('Moisture', sprintf(
                '%s: Variable %d existiert nicht mehr → Bewässerung wird gestartet',
                self::ZONE_NAMES[$zone], $sensorID
            ), 0);
            return false;
        }

        $moisture = (float)GetValueInteger($sensorID);
        $ok       = $moisture >= $threshold;

        $this->SendDebug('Moisture', sprintf(
            '%s: VarID=%d Ist=%.0f%% Schwelle=%.0f%% → %s',
            self::ZONE_NAMES[$zone], $sensorID, $moisture, $threshold,
            $ok ? 'ÜBERSPRINGEN (Feuchte ausreichend)' : 'BEWÄSSERN (Feuchte zu niedrig)'
        ), 0);

        return $ok;
    }

    private function applyTemperatureFactor(float $baseLiters): float {
        $tempID = $this->ReadPropertyInteger('TempSensorID');
        if ($tempID == 0 || !IPS_VariableExists($tempID)) return $baseLiters;

        $temp      = GetValueFloat($tempID);
        $threshold = $this->ReadPropertyFloat('TempBoostThreshold');
        $boost     = $this->ReadPropertyFloat('TempBoostPercent');

        if ($temp <= $threshold) return $baseLiters;

        $factor = 1.0 + ($boost / 100.0) * (($temp - $threshold) / 5.0);
        $factor = min($factor, 2.0); // Deckel: max +100%

        $adjusted = round($baseLiters * $factor, 1);
        $this->SendDebug('TempFactor', sprintf('%.1f°C → Faktor %.2f → %.1f L', $temp, $factor, $adjusted), 0);
        return $adjusted;
    }

    // =========================================================================
    // PRIVATE – DURCHFLUSSMESSER
    // =========================================================================

    /**
     * Berechnet Durchflussrate Q [l/min] aus Pulsfrequenz via Newton-Raphson.
     * K(Q) = A·ln(Q) + B  →  Q = f·60 / K(Q)
     */
    private function calculateFlowRate(float $pulsesPerSec): float {
        if ($pulsesPerSec <= 0) return 0.0;

        $A = $this->ReadPropertyFloat('FlowCalibA');
        $B = $this->ReadPropertyFloat('FlowCalibB');

        $Q = 10.0; // Startwert 10 l/min
        for ($i = 0; $i < 25; $i++) {
            $Q   = max(0.01, $Q);
            $K   = $A * log($Q) + $B;
            $Qn  = $pulsesPerSec * 60.0 / $K;
            if (abs($Qn - $Q) < 0.0005) break;
            $Q   = $Qn;
        }
        return max(0.0, $Q);
    }

    /**
     * Gibt K(Q) = Pulse/Liter für gegebene Durchflussrate zurück.
     */
    private function calculateK(float $flowRate): float {
        $flowRate = max(0.01, $flowRate);
        $A = $this->ReadPropertyFloat('FlowCalibA');
        $B = $this->ReadPropertyFloat('FlowCalibB');
        return $A * log($flowRate) + $B;
    }

    // =========================================================================
    // PRIVATE – STATISTIK
    // =========================================================================

    private function accumulateDailyVolume(float $liters, int $zone) {
        // Gesamt-Historie aktualisieren
        $totalHistory = $this->addToHistory(
            $this->ReadAttributeString('DailyHistoryTotal'), $liters
        );
        $this->WriteAttributeString('DailyHistoryTotal', $totalHistory);

        // Zonen-Historie aktualisieren
        $prefix = $this->zonePrefixById($zone);
        if ($prefix !== null) {
            $attrKey     = 'DailyHistory' . $prefix;
            $zoneHistory = $this->addToHistory(
                $this->ReadAttributeString($attrKey), $liters
            );
            $this->WriteAttributeString($attrKey, $zoneHistory);
        }

        $this->updateStatistik();
    }

    /**
     * Fügt Liter zum jeweiligen Tages-Eintrag im JSON-History-String hinzu
     * und beschneidet auf die letzten 7 Tage.
     */
    private function addToHistory(string $historyJson, float $liters): string {
        $history = json_decode($historyJson, true) ?: [];
        $today   = date('Y-m-d');
        $history[$today] = round(($history[$today] ?? 0.0) + $liters, 3);

        // Einträge älter als 366 Tage entfernen (reicht für Jahresstatistik)
        $cutoff = date('Y-m-d', strtotime('-365 days'));
        foreach (array_keys($history) as $date) {
            if ($date < $cutoff) unset($history[$date]);
        }
        return json_encode($history);
    }

    /** Summe der letzten 7 Tage aus History-JSON */
    private function getLast7Days(string $historyJson): float {
        $history = json_decode($historyJson, true) ?: [];
        $total   = 0.0;
        for ($i = 0; $i < 7; $i++) {
            $date   = date('Y-m-d', strtotime("-$i days"));
            $total += $history[$date] ?? 0.0;
        }
        return round($total, 1);
    }

    /** Tageswert aus History-JSON */
    private function getTodayVolume(string $historyJson): float {
        $history = json_decode($historyJson, true) ?: [];
        return round($history[date('Y-m-d')] ?? 0.0, 1);
    }

    /** Jahreswert (laufendes Kalenderjahr) aus History-JSON */
    private function getYearVolume(string $historyJson): float {
        $history = json_decode($historyJson, true) ?: [];
        $year    = date('Y');
        $total   = 0.0;
        foreach ($history as $date => $liters) {
            if (substr($date, 0, 4) === $year) {
                $total += $liters;
            }
        }
        return round($total, 1);
    }

    /** Aktualisiert alle Statistik-Variablen in der StatCat-Kategorie */
    private function updateStatistik() {
        $totalHistory = $this->ReadAttributeString('DailyHistoryTotal');
        $yearLiters   = $this->getYearVolume($totalHistory);
        $price        = $this->ReadPropertyFloat('WaterPrice'); // €/m³
        $yearCost     = round($yearLiters / 1000.0 * $price, 2); // Liter → m³ × Preis

        $this->setStatVar('StatTodayTotal', $this->getTodayVolume($totalHistory));
        $this->setStatVar('StatLast7Total', $this->getLast7Days($totalHistory));
        $this->setStatVar('StatYearTotal',  $yearLiters);
        $this->setStatVar('StatYearCost',   $yearCost);

        foreach (array_keys(self::ZONE_PREFIX) as $prefix) {
            $history = $this->ReadAttributeString('DailyHistory' . $prefix);
            $this->setStatVar('StatToday_' . $prefix, $this->getTodayVolume($history));
            $this->setStatVar('StatLast7_' . $prefix, $this->getLast7Days($history));
        }
    }

    /** Setzt eine Statistik-Variable (sucht in StatCat und Zonen-Unterkategorien) */
    private function setStatVar(string $ident, float $value) {
        $statCatID = @IPS_GetObjectIDByIdent('StatCat', $this->InstanceID);
        if (!$statCatID) return;

        // Direkt in StatCat
        $varID = @IPS_GetObjectIDByIdent($ident, $statCatID);
        if ($varID) { SetValueFloat($varID, $value); return; }

        // In Zonen-Unterkategorien
        foreach (array_keys(self::ZONE_PREFIX) as $prefix) {
            $zoneCatID = @IPS_GetObjectIDByIdent('StatZone_' . $prefix, $statCatID);
            if (!$zoneCatID) continue;
            $varID = @IPS_GetObjectIDByIdent($ident, $zoneCatID);
            if ($varID) { SetValueFloat($varID, $value); return; }
        }
    }

    // =========================================================================
    // PRIVATE – MANUELLE STEUERUNG
    // =========================================================================

    private function handleManualStart() {
        $manCatID = @IPS_GetObjectIDByIdent('ManualCat', $this->InstanceID);
        if (!$manCatID) return;

        $zoneVarID   = @IPS_GetObjectIDByIdent('ManualZone',   $manCatID);
        $litersVarID = @IPS_GetObjectIDByIdent('ManualLiters', $manCatID);
        if (!$zoneVarID || !$litersVarID) return;

        $zone   = GetValueInteger($zoneVarID);
        $liters = GetValueFloat($litersVarID);

        if ($zone == self::ZONE_NONE || $liters <= 0) return;

        if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) {
            $this->StopAll();
            sleep(1);
        }

        $this->WriteAttributeString('ZoneQueue', '[]'); // Kein Auto-Weiterschalten
        $this->StartZone($zone, $liters);
    }

    // =========================================================================
    // PRIVATE – UI / INITIALISIERUNG
    // =========================================================================

    private function updateStatus() {
        $zone = $this->ReadAttributeInteger('CurrentZone');

        if ($zone == self::ZONE_NONE) {
            if ($this->GetValue('LeakDetected')) {
                $status = '🚨 Leck erkannt! Hauptventil gesperrt.';
            } elseif ($this->isRainBlockedForAnyZone()) {
                $rainID = $this->ReadPropertyInteger('RainSensorID');
                $mm     = ($rainID && IPS_VariableExists($rainID)) ? GetValueFloat($rainID) : 0.0;
                $status = sprintf('🌧 Regen-Sperre aktiv (%.1f mm)', $mm);
            } elseif ($this->GetValue('AutoMode')) {
                $status = '✅ Bereit – Automatik aktiv';
            } else {
                $status = '⏸ Bereit – Automatik deaktiviert';
            }
        } else {
            $vol    = round($this->ReadAttributeFloat('ZoneVolumeLiters'), 1);
            $target = round($this->ReadAttributeFloat('ZoneTargetLiters'), 1);
            $flow   = round($this->ReadAttributeFloat('CurrentFlowRate'),  1);
            $fert   = $this->ReadAttributeBoolean('FertPumpRunning') ? ' 🌿+Dünger' : '';
            $pct    = ($target > 0) ? min(100, round($vol / $target * 100)) : 0;
            $status = sprintf('💧 %s: %.1f / %.1f L (%d%%) | %.1f l/min%s',
                self::ZONE_NAMES[$zone], $vol, $target, $pct, $flow, $fert);
        }

        $this->SetValue('Status', $status);
    }

    // =========================================================================
    // PRIVATE – KONFIGURATIONSKATEGORIE
    // =========================================================================

    private function ensureKonfigurationCategory() {
        $scriptID = $this->ensureActionScript();

        $konfCatID = @IPS_GetObjectIDByIdent('KonfCat', $this->InstanceID);
        if (!$konfCatID) {
            $konfCatID = IPS_CreateCategory();
            IPS_SetParent($konfCatID, $this->InstanceID);
            IPS_SetIdent($konfCatID, 'KonfCat');
            IPS_SetIcon($konfCatID, 'Settings');
        }
        IPS_SetName($konfCatID, 'Konfiguration');
        IPS_SetPosition($konfCatID, 10);

        $zoneIcons = [
            'Rasen'  => 'Lawn',
            'Hecke'  => 'Plant',
            'Hang'   => 'Irrigation',
            'Garage' => 'Garage',
        ];
        $dayLabels = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

        // ── Allgemein-Kategorie (Position 0 – steht ganz oben) ──────────────
        $allgCatID = @IPS_GetObjectIDByIdent('KonfAllgemein', $konfCatID);
        if (!$allgCatID) {
            $allgCatID = IPS_CreateCategory();
            IPS_SetParent($allgCatID, $konfCatID);
            IPS_SetIdent($allgCatID, 'KonfAllgemein');
            IPS_SetIcon($allgCatID, 'Settings');
        }
        IPS_SetName($allgCatID, 'Allgemein');
        IPS_SetPosition($allgCatID, 0);

        // Allgemein / Düngen
        $allgDueCatID = @IPS_GetObjectIDByIdent('KonfAllgDue', $allgCatID);
        if (!$allgDueCatID) {
            $allgDueCatID = IPS_CreateCategory();
            IPS_SetParent($allgDueCatID, $allgCatID);
            IPS_SetIdent($allgDueCatID, 'KonfAllgDue');
            IPS_SetIcon($allgDueCatID, 'Leaf');
        }
        IPS_SetName($allgDueCatID, 'Düngen');
        IPS_SetPosition($allgDueCatID, 0);

        $this->ensureKonfVar($allgDueCatID, 'KonfFertRatioPercent',
            'Dünger/Wasser-Verhältnis (%)', 2, 'GardenIrr.FertPercentInput', 0, $scriptID);
        $this->ensureKonfVar($allgDueCatID, 'KonfFertDelaySeconds',
            'Verzögerung nach Ventilöffnung', 1, 'GardenIrr.Seconds', 1, $scriptID);

        // Allgemein / Bewässern
        $allgBewCatID = @IPS_GetObjectIDByIdent('KonfAllgBew', $allgCatID);
        if (!$allgBewCatID) {
            $allgBewCatID = IPS_CreateCategory();
            IPS_SetParent($allgBewCatID, $allgCatID);
            IPS_SetIdent($allgBewCatID, 'KonfAllgBew');
            IPS_SetIcon($allgBewCatID, 'Irrigation');
        }
        IPS_SetName($allgBewCatID, 'Bewässern');
        IPS_SetPosition($allgBewCatID, 1);

        $this->ensureKonfVar($allgBewCatID, 'KonfRainBlockHours',
            'Regen-Sperrzeit',              1, 'GardenIrr.Hours',       0, $scriptID);
        $this->ensureKonfVar($allgBewCatID, 'KonfTempBoostThreshold',
            'Temperatur-Boost ab',          2, 'GardenIrr.Temperature',  1, $scriptID);
        $this->ensureKonfVar($allgBewCatID, 'KonfTempBoostPercent',
            'Boost pro 5°C über Schwelle',  2, 'GardenIrr.BoostPercent', 2, $scriptID);
        $this->ensureKonfVar($allgBewCatID, 'KonfLeakFlowThreshold',
            'Leck-Alarm ab',                2, 'GardenIrr.FlowRate',     3, $scriptID);
        $this->ensureKonfVar($allgBewCatID, 'KonfMaxZoneRuntimeMin',
            'Sicherheits-Timeout je Zone',  1, 'GardenIrr.Minutes',      4, $scriptID);
        $this->ensureKonfVar($allgBewCatID, 'KonfWaterPrice',
            'Wasserpreis',                  2, 'GardenIrr.WaterPrice',   5, $scriptID);

        // Allgemein / Push-Benachrichtigungen
        $allgPushCatID = @IPS_GetObjectIDByIdent('KonfAllgPush', $allgCatID);
        if (!$allgPushCatID) {
            $allgPushCatID = IPS_CreateCategory();
            IPS_SetParent($allgPushCatID, $allgCatID);
            IPS_SetIdent($allgPushCatID, 'KonfAllgPush');
            IPS_SetIcon($allgPushCatID, 'Mobile');
        }
        IPS_SetName($allgPushCatID, 'Push-Benachrichtigungen');
        IPS_SetPosition($allgPushCatID, 2);

        $this->ensureKonfVar($allgPushCatID, 'KonfPushTargetID',    'WebFront-ID',        1, '',                        0, $scriptID);
        $this->ensureKonfVar($allgPushCatID, 'KonfPushOnStart',     'Push bei Start',     0, '~Switch',                1, $scriptID);
        $this->ensureKonfVar($allgPushCatID, 'KonfPushOnEnd',       'Push bei Ende',      0, '~Switch',                2, $scriptID);
        $this->ensureKonfVar($allgPushCatID, 'KonfPushOnProblem',   'Push bei Problemen', 0, '~Switch',                3, $scriptID);
        $this->ensureKonfVar($allgPushCatID, 'KonfPushOnRain',      'Push bei Regen',     0, '~Switch',                4, $scriptID);
        $this->ensureKonfVar($allgPushCatID, 'KonfFlowWatchdogMin', 'Kein-Fluss-Alarm',   1, 'GardenIrr.ShortMinutes', 5, $scriptID);

        // Allgemein synchronisieren
        $this->syncKonfAllgemein($allgDueCatID, $allgBewCatID);

        // Zonen-Kategorien beginnen ab Position 1
        $pos = 1;

        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            // ── Zonen-Kategorie ──────────────────────────────────────────────
            $zoneCatID = @IPS_GetObjectIDByIdent('KonfZone_' . $prefix, $konfCatID);
            if (!$zoneCatID) {
                $zoneCatID = IPS_CreateCategory();
                IPS_SetParent($zoneCatID, $konfCatID);
                IPS_SetIdent($zoneCatID, 'KonfZone_' . $prefix);
            }
            IPS_SetName($zoneCatID, self::ZONE_NAMES[$zone]);
            IPS_SetIcon($zoneCatID, $zoneIcons[$prefix] ?? 'Plant');
            IPS_SetPosition($zoneCatID, $pos++);

            // ── Bewässern-Kategorie ──────────────────────────────────────────
            $bewCatID = @IPS_GetObjectIDByIdent('KonfBew_' . $prefix, $zoneCatID);
            if (!$bewCatID) {
                $bewCatID = IPS_CreateCategory();
                IPS_SetParent($bewCatID, $zoneCatID);
                IPS_SetIdent($bewCatID, 'KonfBew_' . $prefix);
                IPS_SetIcon($bewCatID, 'Irrigation');
            }
            IPS_SetName($bewCatID, 'Bewässern');
            IPS_SetPosition($bewCatID, 0);

            $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'TargetLiters',
                'Ziel-Volumen', 2, 'GardenIrr.VolumeEdit', 0, $scriptID);
            $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'MaxLiters',
                'Max-Volumen (Sicherheit)', 2, 'GardenIrr.VolumeEdit', 1, $scriptID);
            $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'ScheduleTime',
                'Bewässerungszeit', 1, '~UnixTimestampTime', 2, $scriptID);

            // Bodenfeuchte-Schwelle nur für Zonen mit Sensor (nicht Rasen)
            $dayOffset = 3;
            if ($prefix !== 'Rasen') {
                $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'MoistureThreshold',
                    'Bodenfeuchte-Schwelle', 2, 'GardenIrr.Moisture', 3, $scriptID);
                $dayOffset = 4;
            } else {
                // Alte Variable entfernen falls vorhanden
                $oldID = @IPS_GetObjectIDByIdent('KonfRasenMoistureThreshold', $bewCatID);
                if ($oldID) IPS_DeleteVariable($oldID);
            }

            // Regen-Schwelle (alle Zonen)
            $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'RainThresholdMm',
                'Regen-Schwelle (0 = deaktiviert)', 2, 'GardenIrr.RainMm', $dayOffset, $scriptID);
            $dayOffset++;

            // Wochentage Bewässern
            foreach ($dayLabels as $dayIdx => $label) {
                $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'Day' . self::DAY_PROPS[$dayIdx],
                    $label, 0, '~Switch', $dayOffset + $dayIdx, $scriptID);
            }

            // Countdown (nur für die 2 Ausgänge des Verteilerventils)
            if ($prefix === 'Hang' || $prefix === 'Garage') {
                $this->ensureKonfVar($bewCatID, 'Konf' . $prefix . 'CountdownMin',
                    'Countdown (0 = nur nach Volumen)', 1, 'GardenIrr.CountdownMin', $dayOffset + 7, $scriptID);
            }

            // ── Düngen-Kategorie ─────────────────────────────────────────────
            $dueCatID = @IPS_GetObjectIDByIdent('KonfDue_' . $prefix, $zoneCatID);
            if (!$dueCatID) {
                $dueCatID = IPS_CreateCategory();
                IPS_SetParent($dueCatID, $zoneCatID);
                IPS_SetIdent($dueCatID, 'KonfDue_' . $prefix);
                IPS_SetIcon($dueCatID, 'Leaf');
            }
            IPS_SetName($dueCatID, 'Düngen');
            IPS_SetPosition($dueCatID, 1);

            $this->ensureKonfVar($dueCatID, 'Konf' . $prefix . 'FertEnabled',
                'Düngung aktiv', 0, '~Switch', 0, $scriptID);
            // Wochentage Düngen
            foreach ($dayLabels as $dayIdx => $label) {
                $this->ensureKonfVar($dueCatID, 'Konf' . $prefix . 'FertDay' . self::DAY_PROPS[$dayIdx],
                    $label, 0, '~Switch', 1 + $dayIdx, $scriptID);
            }
            $this->ensureKonfVar($dueCatID, 'Konf' . $prefix . 'FertMl',
                'Düngermenge absolut (ml)', 2, 'GardenIrr.FertMl', 8, $scriptID);

            // Werte aus Properties synchronisieren
            $this->syncKonfZone($prefix, $bewCatID, $dueCatID);
        }
    }

    private function ensureKonfVar(int $catID, string $ident, string $name, int $type, string $profile, int $pos, int $scriptID) {
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if ($varID) {
            // Typ stimmt nicht überein → Variable löschen und neu anlegen
            if (IPS_GetVariable($varID)['VariableType'] !== $type) {
                IPS_DeleteVariable($varID);
                $varID = 0;
            }
        }
        if (!$varID) {
            $varID = IPS_CreateVariable($type);
            IPS_SetParent($varID, $catID);
            IPS_SetIdent($varID, $ident);
        }
        IPS_SetName($varID, $name);
        IPS_SetPosition($varID, $pos);
        IPS_SetVariableCustomProfile($varID, $profile);
        IPS_SetVariableCustomAction($varID, $scriptID);
    }

    /**
     * Synchronisiert Konfigurationsvariablen einer Zone aus den Properties.
     * Wird bei ApplyChanges aufgerufen, damit die App immer den aktuellen Stand zeigt.
     */
    private function syncKonfZone(string $prefix, int $bewCatID, int $dueCatID) {
        // Bewässern
        $target = $this->ReadPropertyFloat($prefix . 'TargetLiters');
        $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'TargetLiters', $target);

        $max = $this->ReadPropertyFloat($prefix . 'MaxLiters');
        $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'MaxLiters', $max);

        // ScheduleTime: Property = Sekunden seit Mitternacht → Unix-Timestamp für ~UnixTimestampTime
        $secs = $this->ReadPropertyInteger($prefix . 'ScheduleTime');
        $h    = intdiv($secs, 3600);
        $m    = intdiv($secs % 3600, 60);
        $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'ScheduleTime', mktime($h, $m, 0));

        // Bodenfeuchte-Schwelle: nur für Zonen mit Sensor (nicht Rasen)
        if ($prefix !== 'Rasen') {
            $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'MoistureThreshold',
                $this->ReadPropertyFloat($prefix . 'MoistureThreshold'));
        }

        // Regen-Schwelle (alle Zonen)
        $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'RainThresholdMm',
            $this->ReadPropertyFloat($prefix . 'RainThresholdMm'));

        foreach (self::DAY_PROPS as $day) {
            $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'Day' . $day,
                $this->ReadPropertyBoolean($prefix . 'Day' . $day));
        }

        // Düngen
        $this->setVarInCat($dueCatID, 'Konf' . $prefix . 'FertEnabled',
            $this->ReadPropertyBoolean($prefix . 'FertEnabled'));

        foreach (self::DAY_PROPS as $day) {
            $this->setVarInCat($dueCatID, 'Konf' . $prefix . 'FertDay' . $day,
                $this->ReadPropertyBoolean($prefix . 'FertDay' . $day));
        }

        $this->setVarInCat($dueCatID, 'Konf' . $prefix . 'FertMl',
            $this->ReadPropertyFloat($prefix . 'FertMl'));

        // Countdown (nur Hang + Garage)
        if ($prefix === 'Hang' || $prefix === 'Garage') {
            $this->setVarInCat($bewCatID, 'Konf' . $prefix . 'CountdownMin',
                $this->ReadPropertyInteger($prefix . 'CountdownMin'));
        }
    }

    private function syncKonfAllgemein(int $dueCatID, int $bewCatID) {
        // Düngen
        $this->setVarInCat($dueCatID, 'KonfFertRatioPercent',
            $this->ReadPropertyFloat('FertRatioPercent'));
        $this->setVarInCat($dueCatID, 'KonfFertDelaySeconds',
            $this->ReadPropertyInteger('FertDelaySeconds'));

        // Bewässern
        $this->setVarInCat($bewCatID, 'KonfRainBlockHours',
            $this->ReadPropertyInteger('RainBlockHours'));
        $this->setVarInCat($bewCatID, 'KonfTempBoostThreshold',
            $this->ReadPropertyFloat('TempBoostThreshold'));
        $this->setVarInCat($bewCatID, 'KonfTempBoostPercent',
            $this->ReadPropertyFloat('TempBoostPercent'));
        $this->setVarInCat($bewCatID, 'KonfLeakFlowThreshold',
            $this->ReadPropertyFloat('LeakFlowThreshold'));
        $this->setVarInCat($bewCatID, 'KonfMaxZoneRuntimeMin',
            $this->ReadPropertyInteger('MaxZoneRuntimeMin'));
        $this->setVarInCat($bewCatID, 'KonfWaterPrice',
            $this->ReadPropertyFloat('WaterPrice'));

        // Push-Benachrichtigungen
        $konfCatID = @IPS_GetObjectIDByIdent('KonfCat',     $this->InstanceID);
        $allgCatID = $konfCatID ? @IPS_GetObjectIDByIdent('KonfAllgemein', $konfCatID) : 0;
        $pushCatID = $allgCatID ? @IPS_GetObjectIDByIdent('KonfAllgPush',  $allgCatID) : 0;
        if ($pushCatID) {
            $this->setVarInCat($pushCatID, 'KonfPushTargetID',    $this->ReadPropertyInteger('PushTargetID'));
            $this->setVarInCat($pushCatID, 'KonfPushOnStart',     $this->ReadPropertyBoolean('PushOnStart'));
            $this->setVarInCat($pushCatID, 'KonfPushOnEnd',       $this->ReadPropertyBoolean('PushOnEnd'));
            $this->setVarInCat($pushCatID, 'KonfPushOnProblem',   $this->ReadPropertyBoolean('PushOnProblem'));
            $this->setVarInCat($pushCatID, 'KonfPushOnRain',      $this->ReadPropertyBoolean('PushOnRain'));
            $this->setVarInCat($pushCatID, 'KonfFlowWatchdogMin', $this->ReadPropertyInteger('FlowWatchdogMin'));
        }
    }

    private function setVarInCat(int $catID, string $ident, $value) {
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if (!$varID) return;
        $type = IPS_GetVariable($varID)['VariableType'];
        switch ($type) {
            case 0: SetValueBoolean($varID, (bool)$value);   break;
            case 1: SetValueInteger($varID, (int)$value);    break;
            case 2: SetValueFloat($varID,   (float)$value);  break;
            case 3: SetValueString($varID,  (string)$value); break;
        }
    }

    /**
     * Wertet eine Änderung einer Konfigurationsvariable aus,
     * schreibt sie als Property und triggert ApplyChanges.
     */
    private function handleKonfChange(string $ident, $value) {
        // Konf-Prefix entfernen → Property-Name
        $propName = substr($ident, 4);

        // ScheduleTime: Variable hat Unix-Timestamp, Property erwartet Sekunden seit Mitternacht
        if (substr($propName, -12) === 'ScheduleTime') {
            $h     = (int)date('G', (int)$value);
            $m     = (int)date('i', (int)$value);
            $value = $h * 3600 + $m * 60;
        }

        // Typ des Properties ermitteln und passend setzen
        // Bei Float-Properties: Eingabe kann als String "4.2" oder "4,2" kommen
        $info = IPS_GetProperty($this->InstanceID, $propName);
        switch (gettype($info)) {
            case 'boolean': IPS_SetProperty($this->InstanceID, $propName, (bool)$value);   break;
            case 'integer': IPS_SetProperty($this->InstanceID, $propName, (int)$value);    break;
            case 'double':
                $fval = (float)str_replace(',', '.', trim((string)$value));
                IPS_SetProperty($this->InstanceID, $propName, $fval);
                break;
            default:        IPS_SetProperty($this->InstanceID, $propName, (string)$value); break;
        }

        IPS_ApplyChanges($this->InstanceID);
    }

    private function ensureStatistikCategory() {
        $statCatID = @IPS_GetObjectIDByIdent('StatCat', $this->InstanceID);
        if (!$statCatID) {
            $statCatID = IPS_CreateCategory();
            IPS_SetParent($statCatID, $this->InstanceID);
            IPS_SetIdent($statCatID, 'StatCat');
            IPS_SetIcon($statCatID, 'Graph');
        }
        IPS_SetName($statCatID, 'Statistik');
        IPS_SetPosition($statCatID, 9);

        // Gesamtwerte
        $this->ensureStatVarObj($statCatID, 'StatTodayTotal', 'Heute gesamt',              0, 'GardenIrr.Volume');
        $this->ensureStatVarObj($statCatID, 'StatLast7Total', 'Letzte 7 Tage gesamt',      1, 'GardenIrr.Volume');
        $this->ensureStatVarObj($statCatID, 'StatYearTotal',  date('Y') . ' gesamt (L)',   2, 'GardenIrr.Volume');
        $this->ensureStatVarObj($statCatID, 'StatYearCost',   date('Y') . ' Kosten',       3, 'GardenIrr.Cost');

        // Zonen-Unterkategorien
        $icons = [
            'Rasen'  => 'Lawn',
            'Hecke'  => 'Plant',
            'Hang'   => 'Irrigation',
            'Garage' => 'Garage',
        ];
        $pos = 2;
        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            $zoneCatID = @IPS_GetObjectIDByIdent('StatZone_' . $prefix, $statCatID);
            if (!$zoneCatID) {
                $zoneCatID = IPS_CreateCategory();
                IPS_SetParent($zoneCatID, $statCatID);
                IPS_SetIdent($zoneCatID, 'StatZone_' . $prefix);
            }
            IPS_SetName($zoneCatID, self::ZONE_NAMES[$zone]);
            IPS_SetPosition($zoneCatID, $pos++);
            IPS_SetIcon($zoneCatID, $icons[$prefix] ?? 'Plant');

            $this->ensureStatVarObj($zoneCatID, 'StatToday_' . $prefix, 'Heute',         0);
            $this->ensureStatVarObj($zoneCatID, 'StatLast7_' . $prefix, 'Letzte 7 Tage', 1);
        }
    }

    private function ensureStatVarObj(int $catID, string $ident, string $name, int $pos, string $profile = 'GardenIrr.Volume') {
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if (!$varID) {
            $varID = IPS_CreateVariable(2); // Float
            IPS_SetParent($varID, $catID);
            IPS_SetIdent($varID, $ident);
        }
        IPS_SetName($varID, $name);
        IPS_SetPosition($varID, $pos);
        IPS_SetVariableCustomProfile($varID, $profile);
    }

    /**
     * Entfernt veraltete direkt-registrierte Statistik-Variablen,
     * die durch die StatCat-Kategorie abgelöst wurden.
     */
    private function cleanupOldVariables() {
        // Veraltete direkt-registrierte Variablen entfernen
        foreach (['DailyVolume', 'WeeklyVolume', 'DailyCost',
                  'SoilHeckeValue', 'SoilHangValue', 'TempValue'] as $ident) {
            $varID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            if ($varID && IPS_VariableExists($varID)) {
                IPS_DeleteVariable($varID);
            }
        }
    }

    /**
     * Legt einen IPS-Link auf eine Sensor-Variable an (oder aktualisiert ihn).
     * Links zeigen direkt auf die Original-Variable → Archiv/History ist in der App zugänglich.
     * Wenn targetVarID = 0 oder nicht existent, wird der Link ausgeblendet.
     */
    private function ensureSensorLink(string $ident, string $name, string $icon, int $pos, int $targetVarID) {
        $linkID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);

        if ($targetVarID == 0 || !IPS_VariableExists($targetVarID)) {
            // Sensor nicht konfiguriert: Link ausblenden falls vorhanden
            if ($linkID) {
                IPS_SetHidden($linkID, true);
            }
            return;
        }

        if (!$linkID) {
            $linkID = IPS_CreateLink();
            IPS_SetParent($linkID, $this->InstanceID);
            IPS_SetIdent($linkID, $ident);
        }
        IPS_SetName($linkID, $name);
        IPS_SetIcon($linkID, $icon);
        IPS_SetPosition($linkID, $pos);
        IPS_SetLinkTargetID($linkID, $targetVarID);
        IPS_SetHidden($linkID, false);
    }

    private function ensureManualCategory() {
        $scriptID = $this->ensureActionScript();

        $catID = @IPS_GetObjectIDByIdent('ManualCat', $this->InstanceID);
        if (!$catID) {
            $catID = IPS_CreateCategory();
            IPS_SetParent($catID, $this->InstanceID);
            IPS_SetIdent($catID, 'ManualCat');
            IPS_SetIcon($catID, 'Gear');
        }
        IPS_SetName($catID, 'Manuell starten');
        IPS_SetPosition($catID, 2);

        $this->ensureVar($catID, 'ManualZone',   1, 'Zone',           'GardenIrr.Zone',   0, $scriptID);
        $this->ensureVar($catID, 'ManualLiters', 2, 'Menge (Liter)',  'GardenIrr.Volume', 1, $scriptID);
        $this->ensureVar($catID, 'ManualStart',  0, 'Jetzt starten',  '~Switch',          2, $scriptID);
        IPS_SetIcon(@IPS_GetObjectIDByIdent('ManualStart', $catID), 'Play');

        // Standardwert Liter
        $litersID = @IPS_GetObjectIDByIdent('ManualLiters', $catID);
        if ($litersID && GetValueFloat($litersID) == 0.0) {
            SetValueFloat($litersID, 20.0);
        }
    }

    private function ensureVar(int $catID, string $ident, int $type, string $name, string $profile, int $pos, int $scriptID) {
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if (!$varID) {
            $varID = IPS_CreateVariable($type);
            IPS_SetParent($varID, $catID);
            IPS_SetIdent($varID, $ident);
        }
        IPS_SetName($varID, $name);
        IPS_SetPosition($varID, $pos);
        IPS_SetVariableCustomProfile($varID, $profile);
        IPS_SetVariableCustomAction($varID, $scriptID);
    }

    private function ensureActionScript(): int {
        $scriptID = @IPS_GetObjectIDByIdent('ActionScript', $this->InstanceID);
        if (!$scriptID) {
            $scriptID = IPS_CreateScript(0);
            IPS_SetParent($scriptID, $this->InstanceID);
            IPS_SetIdent($scriptID, 'ActionScript');
            IPS_SetName($scriptID, 'Aktion');
            IPS_SetHidden($scriptID, true);
        }
        IPS_SetScriptContent($scriptID,
            '<?php' . "\n" .
            'IPS_RequestAction(' . $this->InstanceID . ', IPS_GetObject($_IPS[\'VARIABLE\'])[\'ObjectIdent\'], $_IPS[\'VALUE\']);'
        );
        return $scriptID;
    }

    private function ensureProfiles() {
        // Zonen-Profil
        if (!IPS_VariableProfileExists('GardenIrr.Zone')) {
            IPS_CreateVariableProfile('GardenIrr.Zone', 1);
            IPS_SetVariableProfileAssociation('GardenIrr.Zone', self::ZONE_NONE,          'Keine',         'Close',      -1);
            IPS_SetVariableProfileAssociation('GardenIrr.Zone', self::ZONE_RASEN,         'Rasen',         'Lawn',        -1);
            IPS_SetVariableProfileAssociation('GardenIrr.Zone', self::ZONE_HECKE,         'Hecke',         'Plant',       -1);
            IPS_SetVariableProfileAssociation('GardenIrr.Zone', self::ZONE_HANG,          'Hang',          'Irrigation',  -1);
            IPS_SetVariableProfileAssociation('GardenIrr.Zone', self::ZONE_HECKE_GARAGE,  'Hecke Garage',  'Garage',      -1);
        }

        // Durchfluss [l/min]
        if (!IPS_VariableProfileExists('GardenIrr.FlowRate')) {
            IPS_CreateVariableProfile('GardenIrr.FlowRate', 2);
            IPS_SetVariableProfileValues('GardenIrr.FlowRate', 0, 35, 0.5);
            IPS_SetVariableProfileText('GardenIrr.FlowRate',   '', ' l/min');
            IPS_SetVariableProfileDigits('GardenIrr.FlowRate', 1);
        }

        // Volumen [L]
        if (!IPS_VariableProfileExists('GardenIrr.Volume')) {
            IPS_CreateVariableProfile('GardenIrr.Volume', 2);
            IPS_SetVariableProfileValues('GardenIrr.Volume', 0, 2000, 1);
            IPS_SetVariableProfileText('GardenIrr.Volume',   '', ' L');
            IPS_SetVariableProfileDigits('GardenIrr.Volume', 1);
        }

        // Kosten [€]
        if (!IPS_VariableProfileExists('GardenIrr.Cost')) {
            IPS_CreateVariableProfile('GardenIrr.Cost', 2);
            IPS_SetVariableProfileValues('GardenIrr.Cost', 0, 100, 0.01);
            IPS_SetVariableProfileText('GardenIrr.Cost',   '', ' €');
            IPS_SetVariableProfileDigits('GardenIrr.Cost', 4);
        }

        // Düngermenge [ml] absolut – Pumpe fügt fest 4% (= 40 ml/L) hinzu
        if (!IPS_VariableProfileExists('GardenIrr.FertMl')) {
            IPS_CreateVariableProfile('GardenIrr.FertMl', 2);
            IPS_SetVariableProfileValues('GardenIrr.FertMl', 0, 1000, 10);
            IPS_SetVariableProfileText('GardenIrr.FertMl',   '', ' ml');
            IPS_SetVariableProfileDigits('GardenIrr.FertMl', 0);
        }

        // Ziel-/Max-Volumen editierbar [L]
        if (!IPS_VariableProfileExists('GardenIrr.VolumeEdit')) {
            IPS_CreateVariableProfile('GardenIrr.VolumeEdit', 2);
            IPS_SetVariableProfileValues('GardenIrr.VolumeEdit', 1, 500, 1);
            IPS_SetVariableProfileText('GardenIrr.VolumeEdit',   '', ' L');
            IPS_SetVariableProfileDigits('GardenIrr.VolumeEdit', 1);
        }

        // Bodenfeuchte-Anzeige [%] – Integer (für Display-Variablen SoilHeckeValue / SoilHangValue)
        if (!IPS_VariableProfileExists('GardenIrr.SoilMoisture')) {
            IPS_CreateVariableProfile('GardenIrr.SoilMoisture', 1); // Integer
            IPS_SetVariableProfileValues('GardenIrr.SoilMoisture', 0, 100, 1);
            IPS_SetVariableProfileText('GardenIrr.SoilMoisture',   '', ' %');
            IPS_SetVariableProfileIcon('GardenIrr.SoilMoisture',   'Drops');
        }

        // Feuchtigkeitsschwelle [%] – Float (für Konf-Variablen MoistureThreshold)
        if (!IPS_VariableProfileExists('GardenIrr.Moisture')) {
            IPS_CreateVariableProfile('GardenIrr.Moisture', 2);
            IPS_SetVariableProfileValues('GardenIrr.Moisture', 0, 100, 1);
            IPS_SetVariableProfileText('GardenIrr.Moisture',   '', ' %');
            IPS_SetVariableProfileDigits('GardenIrr.Moisture', 0);
        }

        // Regen-Schwelle [mm] – Werteingabe (step=0)
        if (!IPS_VariableProfileExists('GardenIrr.RainMm')) {
            IPS_CreateVariableProfile('GardenIrr.RainMm', 2);
        }
        IPS_SetVariableProfileValues('GardenIrr.RainMm', 0, 50, 0);
        IPS_SetVariableProfileText('GardenIrr.RainMm',   '', ' mm');
        IPS_SetVariableProfileDigits('GardenIrr.RainMm', 1);

        // Dünger/Wasser-Verhältnis [%] – Werteingabe (step=0 → kein Slider, direktes Eingabefeld)
        if (!IPS_VariableProfileExists('GardenIrr.FertPercentInput')) {
            IPS_CreateVariableProfile('GardenIrr.FertPercentInput', 2);
        }
        IPS_SetVariableProfileValues('GardenIrr.FertPercentInput', 0, 10, 0);
        IPS_SetVariableProfileText('GardenIrr.FertPercentInput',   '', ' %');
        IPS_SetVariableProfileDigits('GardenIrr.FertPercentInput', 1);

        // Verzögerung [s]
        if (!IPS_VariableProfileExists('GardenIrr.Seconds')) {
            IPS_CreateVariableProfile('GardenIrr.Seconds', 1);
            IPS_SetVariableProfileValues('GardenIrr.Seconds', 0, 120, 1);
            IPS_SetVariableProfileText('GardenIrr.Seconds', '', ' s');
        }

        // Stunden [h]
        if (!IPS_VariableProfileExists('GardenIrr.Hours')) {
            IPS_CreateVariableProfile('GardenIrr.Hours', 1);
            IPS_SetVariableProfileValues('GardenIrr.Hours', 0, 72, 1);
            IPS_SetVariableProfileText('GardenIrr.Hours', '', ' h');
        }

        // Minuten [min]
        if (!IPS_VariableProfileExists('GardenIrr.Minutes')) {
            IPS_CreateVariableProfile('GardenIrr.Minutes', 1);
            IPS_SetVariableProfileValues('GardenIrr.Minutes', 5, 180, 5);
            IPS_SetVariableProfileText('GardenIrr.Minutes', '', ' min');
        }

        // Countdown-Minuten [min] – 2-Wege-Ventil Zonen (0 = deaktiviert, 1–120 = Laufzeit)
        if (!IPS_VariableProfileExists('GardenIrr.CountdownMin')) {
            IPS_CreateVariableProfile('GardenIrr.CountdownMin', 1);
            IPS_SetVariableProfileValues('GardenIrr.CountdownMin', 0, 120, 1);
            IPS_SetVariableProfileText('GardenIrr.CountdownMin', '', ' min');
        }

        // Temperatur-Schwelle [°C] – eigenes editierbares Profil
        if (!IPS_VariableProfileExists('GardenIrr.Temperature')) {
            IPS_CreateVariableProfile('GardenIrr.Temperature', 2);
            IPS_SetVariableProfileValues('GardenIrr.Temperature', 15, 45, 0.5);
            IPS_SetVariableProfileText('GardenIrr.Temperature',   '', ' °C');
            IPS_SetVariableProfileDigits('GardenIrr.Temperature', 1);
        }

        // Boost-Prozentsatz [%]
        if (!IPS_VariableProfileExists('GardenIrr.BoostPercent')) {
            IPS_CreateVariableProfile('GardenIrr.BoostPercent', 2);
            IPS_SetVariableProfileValues('GardenIrr.BoostPercent', 0, 50, 1);
            IPS_SetVariableProfileText('GardenIrr.BoostPercent',   '', ' %');
            IPS_SetVariableProfileDigits('GardenIrr.BoostPercent', 0);
        }

        // Wasserpreis [€/m³]
        if (!IPS_VariableProfileExists('GardenIrr.WaterPrice')) {
            IPS_CreateVariableProfile('GardenIrr.WaterPrice', 2);
            IPS_SetVariableProfileValues('GardenIrr.WaterPrice', 0, 10, 0.01);
            IPS_SetVariableProfileText('GardenIrr.WaterPrice',   '', ' €/m³');
            IPS_SetVariableProfileDigits('GardenIrr.WaterPrice', 2);
        }

        // Kurzzeit-Minuten [1–10] für Kein-Fluss-Watchdog
        if (!IPS_VariableProfileExists('GardenIrr.ShortMinutes')) {
            IPS_CreateVariableProfile('GardenIrr.ShortMinutes', 1);
            IPS_SetVariableProfileValues('GardenIrr.ShortMinutes', 1, 10, 1);
            IPS_SetVariableProfileText('GardenIrr.ShortMinutes',   '', ' min');
        }
    }

    // =========================================================================
    // PRIVATE – PUSH-BENACHRICHTIGUNGEN
    // =========================================================================

    private function sendPush(string $title, string $text) {
        $targetID = $this->ReadPropertyInteger('PushTargetID');
        $this->SendDebug('Push', sprintf('TargetID=%d exists=%s', $targetID, IPS_InstanceExists($targetID) ? 'ja' : 'nein'), 0);
        if ($targetID == 0 || !IPS_InstanceExists($targetID)) {
            $this->SendDebug('Push', 'Kein Push-Ziel konfiguriert', 0);
            return;
        }
        $this->SendDebug('Push', $title . ': ' . $text, 0);
        try {
            WFC_PushNotification($targetID, $title . ': ' . $text);
            $this->SendDebug('Push', 'Gesendet OK', 0);
        } catch (Throwable $e) {
            $this->SendDebug('Push', 'Fehler (' . get_class($e) . '): ' . $e->getMessage(), 0);
        }
    }

    private function getSoilMoistureForZone(int $zone): ?float {
        $propMap = [
            self::ZONE_HECKE        => 'SoilHeckeID',
            self::ZONE_HANG         => 'SoilHangID',
            self::ZONE_HECKE_GARAGE => 'SoilGarageID',
        ];
        if (!isset($propMap[$zone])) return null;
        $id = $this->ReadPropertyInteger($propMap[$zone]);
        if ($id == 0 || !IPS_VariableExists($id)) return null;
        return (float)GetValueInteger($id);
    }

    private function isFertActiveToday(int $zone): bool {
        if (!$this->ReadPropertyBoolean('FertEnabled')) return false;
        $prefix = $this->zonePrefixById($zone);
        if (!$prefix) return false;
        if (!$this->ReadPropertyBoolean($prefix . 'FertEnabled')) return false;
        $day = self::DAY_PROPS[(int)date('N') - 1];
        return $this->ReadPropertyBoolean($prefix . 'FertDay' . $day);
    }

    // =========================================================================
    // PRIVATE – HILFSMETHODEN
    // =========================================================================

    private function zonePrefixById(int $zone): ?string {
        $map = array_flip(self::ZONE_PREFIX); // zone → prefix
        return $map[$zone] ?? null;
    }

    /**
     * Setzt den Wert einer Variablen innerhalb von ManualCat.
     * Nötig weil diese Variablen nicht per RegisterVariable* registriert sind
     * und daher $this->SetValue() für sie nicht funktioniert.
     */
    private function setManualVar(string $ident, $value) {
        $catID = @IPS_GetObjectIDByIdent('ManualCat', $this->InstanceID);
        if (!$catID) return;
        $varID = @IPS_GetObjectIDByIdent($ident, $catID);
        if (!$varID) return;

        $type = IPS_GetVariable($varID)['VariableType'];
        switch ($type) {
            case 0: SetValueBoolean($varID, (bool)$value);   break;
            case 1: SetValueInteger($varID, (int)$value);    break;
            case 2: SetValueFloat($varID,   (float)$value);  break;
            case 3: SetValueString($varID,  (string)$value); break;
        }
    }
}
