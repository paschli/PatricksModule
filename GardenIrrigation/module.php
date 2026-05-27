<?php

/**
 * GardenIrrigation – Bewässerungssteuerung für IP-Symcon
 *
 * Zonen:
 *   1 = Rasen        → Hauptventil + ValveRasen
 *   2 = Hecke        → Hauptventil + ValveHecke
 *   3 = Hang         → Hauptventil + ValveHang + ValveY(false)
 *   4 = Hecke Garage → Hauptventil + ValveHang + ValveY(true)
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
        $this->RegisterPropertyInteger('ValveHangID',   0);
        $this->RegisterPropertyInteger('ValveYID',      0);
        $this->RegisterPropertyInteger('FertPumpID',    0);

        // ── Sensoren ─────────────────────────────────────────────────────────
        $this->RegisterPropertyInteger('SoilHeckeID',   0);
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
            $this->RegisterPropertyFloat(  $z . 'FertMlPerL',   5.0);
            $this->RegisterPropertyInteger($z . 'ScheduleTime', 21600); // 06:00
            // Tage (Mo-So)
            foreach (self::DAY_PROPS as $day) {
                $default = ($z === 'Hecke' || $z === 'Garage')
                    ? in_array($day, ['Mo', 'Mi', 'Fr'])
                    : true;
                $this->RegisterPropertyBoolean($z . 'Day' . $day, $default);
            }
            // Feuchtigkeitsschwelle nur für Hecke + Hang
            if (in_array($z, ['Hecke', 'Hang'])) {
                $this->RegisterPropertyFloat($z . 'MoistureThreshold', 70.0);
            }
        }

        // ── Dünger global ────────────────────────────────────────────────────
        $this->RegisterPropertyBoolean('FertEnabled',       false);
        $this->RegisterPropertyInteger('FertDelaySeconds',  15);
        $this->RegisterPropertyFloat(  'FertFlushLiters',   2.0);

        // ── Globale Einstellungen ─────────────────────────────────────────────
        $this->RegisterPropertyInteger('RainBlockHours',     24);
        $this->RegisterPropertyFloat(  'TempBoostThreshold', 30.0);
        $this->RegisterPropertyFloat(  'TempBoostPercent',   10.0);
        $this->RegisterPropertyFloat(  'LeakFlowThreshold',  1.0);
        $this->RegisterPropertyInteger('MaxZoneRuntimeMin',  60);
        $this->RegisterPropertyFloat(  'WaterPrice',         2.0);

        // ── Laufzeit-Attribute ────────────────────────────────────────────────
        $this->RegisterAttributeInteger('CurrentZone',        0);
        $this->RegisterAttributeString( 'ZoneQueue',          '[]');
        $this->RegisterAttributeInteger('ZoneStartTime',      0);
        $this->RegisterAttributeFloat(  'ZoneVolumeLiters',   0.0);
        $this->RegisterAttributeFloat(  'ZoneTargetLiters',   0.0);
        $this->RegisterAttributeInteger('LastPulseCount',     0);
        $this->RegisterAttributeInteger('LastPulseTime',      0);
        $this->RegisterAttributeFloat(  'CurrentFlowRate',    0.0);
        $this->RegisterAttributeInteger('RainBlockUntil',     0);
        $this->RegisterAttributeFloat(  'TodayVolumeLiters',  0.0);
        $this->RegisterAttributeString( 'TodayDate',          '');
        $this->RegisterAttributeFloat(  'WeekVolumeLiters',   0.0);
        $this->RegisterAttributeString( 'WeekStart',          '');
        $this->RegisterAttributeBoolean('FertPumpRunning',    false);
        $this->RegisterAttributeInteger('RegisteredRainID',   0);

        // ── Variablen ────────────────────────────────────────────────────────
        $this->RegisterVariableString( 'Status',       'Status',         '',                    0);
        $this->RegisterVariableInteger('ActiveZone',   'Aktive Zone',    'GardenIrr.Zone',      1);
        $this->RegisterVariableFloat(  'FlowRate',     'Durchfluss',     'GardenIrr.FlowRate',  2);
        $this->RegisterVariableFloat(  'ZoneVolume',   'Volumen Zone',   'GardenIrr.Volume',    3);
        $this->RegisterVariableFloat(  'TargetVolume', 'Ziel-Volumen',   'GardenIrr.Volume',    4);
        $this->RegisterVariableFloat(  'DailyVolume',  'Heute gesamt',   'GardenIrr.Volume',    5);
        $this->RegisterVariableFloat(  'WeeklyVolume', 'Diese Woche',    'GardenIrr.Volume',    6);
        $this->RegisterVariableFloat(  'DailyCost',    'Kosten heute',   'GardenIrr.Cost',      7);
        $this->RegisterVariableBoolean('RainBlocked',  'Regen-Sperre',   '~Switch',             8);
        $this->RegisterVariableBoolean('LeakDetected', 'Leck erkannt',   '~Alert',              9);
        $this->RegisterVariableBoolean('AutoMode',     'Automatik',      '~Switch',             10);
        $this->RegisterVariableBoolean('EmergencyStop','Notaus',         '~Switch',             11);

        IPS_SetIcon($this->GetIDForIdent('Status'),        'Plant');
        IPS_SetIcon($this->GetIDForIdent('ActiveZone'),    'Irrigation');
        IPS_SetIcon($this->GetIDForIdent('FlowRate'),      'Gauge');
        IPS_SetIcon($this->GetIDForIdent('DailyVolume'),   'Information');
        IPS_SetIcon($this->GetIDForIdent('WeeklyVolume'),  'Calendar');
        IPS_SetIcon($this->GetIDForIdent('RainBlocked'),   'Cloud');
        IPS_SetIcon($this->GetIDForIdent('LeakDetected'),  'Alert');
        IPS_SetIcon($this->GetIDForIdent('AutoMode'),      'Execute');
        IPS_SetIcon($this->GetIDForIdent('EmergencyStop'), 'Alert');

        // ── Timer ─────────────────────────────────────────────────────────────
        $id = $this->InstanceID;
        $this->RegisterTimer('ScheduleTimer',  0, "GardenIrr_ScheduleTick($id);");
        $this->RegisterTimer('ZoneTimer',      0, "GardenIrr_ZoneSafetyTick($id);");
        $this->RegisterTimer('FlowTimer',      0, "GardenIrr_FlowTick($id);");
        $this->RegisterTimer('LeakTimer',      0, "GardenIrr_LeakTick($id);");
        $this->RegisterTimer('FertStartTimer', 0, "GardenIrr_FertStartTick($id);");
    }

    public function ApplyChanges() {
        parent::ApplyChanges();

        $this->ensureProfiles();
        $this->ensureManualCategory();

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

        // Puls-Startwert merken
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID != 0 && IPS_VariableExists($flowID)) {
            $this->WriteAttributeInteger('LastPulseCount', GetValueInteger($flowID));
            $this->WriteAttributeInteger('LastPulseTime',  time());
        }

        // Leck-Timer starten wenn keine Zone läuft
        if ($this->ReadAttributeInteger('CurrentZone') == self::ZONE_NONE) {
            $this->SetTimerInterval('LeakTimer', 30 * 1000);
        }

        $this->scheduleNext();
        $this->updateStatus();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data) {
        if ($Message != VM_UPDATE) return;

        $rainID = $this->ReadAttributeInteger('RegisteredRainID');
        if ($rainID != 0 && $SenderID == $rainID) {
            $this->onRainUpdate();
        }
    }

    public function RequestAction($ident, $value) {
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
                    $this->StopAll();
                    $this->SetValue('EmergencyStop', false);
                }
                break;

            case 'ManualStart':
                if ((bool)$value) {
                    $this->handleManualStart();
                    $this->SetValue('ManualStart', false);
                }
                break;

            case 'ManualZone':
                $this->SetValue('ManualZone', (int)$value);
                break;

            case 'ManualLiters':
                $this->SetValue('ManualLiters', (float)$value);
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

        // Puls-Referenz
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID != 0 && IPS_VariableExists($flowID)) {
            $this->WriteAttributeInteger('LastPulseCount', GetValueInteger($flowID));
            $this->WriteAttributeInteger('LastPulseTime',  time());
        }

        // Anzeige
        $this->SetValue('ActiveZone',   $zone);
        $this->SetValue('ZoneVolume',   0.0);
        $this->SetValue('TargetVolume', $adjusted);

        // Ventile öffnen
        $this->openZoneValves($zone);

        // Sicherheits-Timer
        $this->SetTimerInterval('ZoneTimer', $this->ReadPropertyInteger('MaxZoneRuntimeMin') * 60 * 1000);

        // Flow-Messung starten (alle 5 Sek.)
        $this->SetTimerInterval('FlowTimer', 5000);

        // Leck-Timer pausieren
        $this->SetTimerInterval('LeakTimer', 0);

        // Dünger planen
        $this->scheduleFertStart($zone);

        $this->updateStatus();
    }

    /**
     * Stoppt alle Ventile sofort (Notaus / Programmende).
     */
    public function StopAll() {
        $this->SendDebug('StopAll', 'Alle Ventile werden geschlossen', 0);

        $this->SetTimerInterval('ZoneTimer',      0);
        $this->SetTimerInterval('FlowTimer',      0);
        $this->SetTimerInterval('FertStartTimer', 0);

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

        $this->SetTimerInterval('LeakTimer', 30 * 1000);
        $this->updateStatus();
    }

    /**
     * Zeitplan-Tick – aufgerufen vom ScheduleTimer.
     */
    public function ScheduleTick() {
        if (!$this->GetValue('AutoMode')) return;

        $this->SendDebug('Schedule', 'Tick ausgelöst', 0);

        if ($this->isRainBlocked()) {
            $this->SendDebug('Schedule', 'Regen-Sperre – überspringe', 0);
            $this->scheduleNext();
            return;
        }

        if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) {
            $this->scheduleNext();
            return;
        }

        // Queue aufbauen oder nächste Zone entnehmen
        $queue = json_decode($this->ReadAttributeString('ZoneQueue'), true);
        if (!is_array($queue) || empty($queue)) {
            $queue = $this->buildDailyQueue();
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
     * Sicherheits-Timeout – maximale Zonenlaufzeit überschritten.
     */
    public function ZoneSafetyTick() {
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone == self::ZONE_NONE) return;
        $this->SendDebug('Safety', 'Timeout Zone ' . self::ZONE_NAMES[$zone], 0);
        $this->finishZone($zone, true);
    }

    /**
     * Durchfluss-Tick – alle 5 Sek. Volumen akkumulieren.
     */
    public function FlowTick() {
        $flowID = $this->ReadPropertyInteger('FlowCounterID');
        if ($flowID == 0 || !IPS_VariableExists($flowID)) return;

        $now          = time();
        $currentCount = GetValueInteger($flowID);
        $lastCount    = $this->ReadAttributeInteger('LastPulseCount');
        $lastTime     = $this->ReadAttributeInteger('LastPulseTime');
        $deltaTime    = max(1, $now - $lastTime);
        $deltaPulses  = $currentCount - $lastCount;

        $this->WriteAttributeInteger('LastPulseCount', $currentCount);
        $this->WriteAttributeInteger('LastPulseTime',  $now);

        if ($deltaPulses <= 0) {
            $this->SetValue('FlowRate', 0.0);
            $this->WriteAttributeFloat('CurrentFlowRate', 0.0);
            return;
        }

        // Durchfluss berechnen
        $pulsesPerSec = $deltaPulses / $deltaTime;
        $flowRate     = $this->calculateFlowRate($pulsesPerSec);
        $this->WriteAttributeFloat('CurrentFlowRate', $flowRate);
        $this->SetValue('FlowRate', round($flowRate, 1));

        // Volumen nur akkumulieren wenn Zone aktiv
        $zone = $this->ReadAttributeInteger('CurrentZone');
        if ($zone == self::ZONE_NONE) return;

        $K           = $this->calculateK($flowRate);
        $deltaLiters = ($K > 0) ? ($deltaPulses / $K) : 0.0;
        $newVolume   = $this->ReadAttributeFloat('ZoneVolumeLiters') + $deltaLiters;

        $this->WriteAttributeFloat('ZoneVolumeLiters', $newVolume);
        $this->SetValue('ZoneVolume', round($newVolume, 1));
        $this->accumulateDailyVolume($deltaLiters);

        $target = $this->ReadAttributeFloat('ZoneTargetLiters');

        $this->SendDebug('Flow', sprintf(
            'Q=%.1f l/min | K=%.0f P/L | ΔV=%.3f L | %.1f / %.1f L',
            $flowRate, $K, $deltaLiters, $newVolume, $target
        ), 0);

        // Düngerpumpe X Liter vor Ende stoppen (Spülung)
        $flushLiters = $this->ReadPropertyFloat('FertFlushLiters');
        if ($this->ReadAttributeBoolean('FertPumpRunning') && $newVolume >= ($target - $flushLiters)) {
            $this->SendDebug('Fert', 'Spülung – stoppe Pumpe', 0);
            $this->stopFertPump();
        }

        // Zielvolumen erreicht?
        if ($newVolume >= $target) {
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

        $now          = time();
        $currentCount = GetValueInteger($flowID);
        $lastCount    = $this->ReadAttributeInteger('LastPulseCount');
        $lastTime     = $this->ReadAttributeInteger('LastPulseTime');
        $deltaTime    = max(1, $now - $lastTime);
        $deltaPulses  = $currentCount - $lastCount;

        $this->WriteAttributeInteger('LastPulseCount', $currentCount);
        $this->WriteAttributeInteger('LastPulseTime',  $now);

        if ($deltaPulses <= 0) {
            $this->SetValue('LeakDetected', false);
            return;
        }

        $pulsesPerSec = $deltaPulses / $deltaTime;
        $flowRate     = $this->calculateFlowRate($pulsesPerSec);
        $threshold    = $this->ReadPropertyFloat('LeakFlowThreshold');

        if ($flowRate >= $threshold) {
            $this->SendDebug('Leak', sprintf('Leck! %.1f l/min bei geschlossenen Ventilen', $flowRate), 0);
            $this->SetValue('LeakDetected', true);
            // Hauptventil als Schutz schließen
            $mainID = $this->ReadPropertyInteger('MainValveID');
            $this->setValve($mainID, false);
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

        if ($this->isMoistureOk($zone)) {
            $this->SendDebug('Zone', self::ZONE_NAMES[$zone] . ': Feuchte ausreichend – überspringe', 0);
            $this->dequeueNext();
            return;
        }

        $this->StartZone($zone, $targetLiters);
    }

    private function finishZone(int $zone, bool $forced) {
        $volume   = $this->ReadAttributeFloat('ZoneVolumeLiters');
        $duration = time() - $this->ReadAttributeInteger('ZoneStartTime');

        $this->SendDebug('Zone', sprintf(
            '%s beendet: %.1f L in %d Sek.%s',
            self::ZONE_NAMES[$zone], $volume, $duration, $forced ? ' [Timeout]' : ''
        ), 0);

        $this->SetTimerInterval('ZoneTimer',      0);
        $this->SetTimerInterval('FlowTimer',      0);
        $this->SetTimerInterval('FertStartTimer', 0);

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
            $this->scheduleNext();
            $this->updateStatus();
        }
    }

    // =========================================================================
    // PRIVATE – VENTILSTEUERUNG
    // =========================================================================

    private function openZoneValves(int $zone) {
        $mainID  = $this->ReadPropertyInteger('MainValveID');
        $rasenID = $this->ReadPropertyInteger('ValveRasenID');
        $heckeID = $this->ReadPropertyInteger('ValveHeckeID');
        $hangID  = $this->ReadPropertyInteger('ValveHangID');
        $yID     = $this->ReadPropertyInteger('ValveYID');

        // Erst alle Zonenventile schließen
        $this->setValve($rasenID, false);
        $this->setValve($heckeID, false);
        $this->setValve($hangID,  false);
        usleep(100000); // 100 ms

        // Zonenventil öffnen
        switch ($zone) {
            case self::ZONE_RASEN:
                $this->setValve($rasenID, true);
                break;
            case self::ZONE_HECKE:
                $this->setValve($heckeID, true);
                break;
            case self::ZONE_HANG:
                $this->setValve($yID,    false); // Y → Hang
                $this->setValve($hangID, true);
                break;
            case self::ZONE_HECKE_GARAGE:
                $this->setValve($yID,    true);  // Y → Hecke Garage
                $this->setValve($hangID, true);
                break;
        }

        // Hauptventil zuletzt öffnen
        usleep(200000); // 200 ms – Zonenventil öffnet zuerst
        $this->setValve($mainID, true);

        $this->SendDebug('Valves', 'Geöffnet: Zone ' . self::ZONE_NAMES[$zone], 0);
    }

    private function closeAllValves() {
        $mainID  = $this->ReadPropertyInteger('MainValveID');
        $rasenID = $this->ReadPropertyInteger('ValveRasenID');
        $heckeID = $this->ReadPropertyInteger('ValveHeckeID');
        $hangID  = $this->ReadPropertyInteger('ValveHangID');
        $yID     = $this->ReadPropertyInteger('ValveYID');

        // Hauptventil zuerst – kein Druck mehr in Leitung
        $this->setValve($mainID,  false);
        usleep(300000); // 300 ms Druckabbau

        $this->setValve($rasenID, false);
        $this->setValve($heckeID, false);
        $this->setValve($hangID,  false);
        $this->setValve($yID,     false); // Y-Ventil in Ruhestellung (Hang)

        $this->SendDebug('Valves', 'Alle Ventile geschlossen', 0);
    }

    private function setValve(int $varID, bool $state) {
        if ($varID == 0 || !IPS_VariableExists($varID)) return;
        try {
            RequestAction($varID, $state);
        } catch (Exception $e) {
            $this->SendDebug('Valve', 'Fehler VarID ' . $varID . ': ' . $e->getMessage(), 0);
        }
    }

    private function startFertPump() {
        $pumpID = $this->ReadPropertyInteger('FertPumpID');
        $this->setValve($pumpID, true);
    }

    private function stopFertPump() {
        if (!$this->ReadAttributeBoolean('FertPumpRunning')) return;
        $this->WriteAttributeBoolean('FertPumpRunning', false);
        $pumpID = $this->ReadPropertyInteger('FertPumpID');
        $this->setValve($pumpID, false);
        $this->SendDebug('Fert', 'Pumpe gestoppt', 0);
    }

    private function scheduleFertStart(int $zone) {
        if (!$this->ReadPropertyBoolean('FertEnabled')) return;

        $prefix = $this->zonePrefixById($zone);
        if ($prefix === null) return;

        if (!$this->ReadPropertyBoolean($prefix . 'FertEnabled')) return;

        $delayMs = $this->ReadPropertyInteger('FertDelaySeconds') * 1000;
        $this->SetTimerInterval('FertStartTimer', max(1000, $delayMs));
        $this->SendDebug('Fert', 'Pumpenstart in ' . $this->ReadPropertyInteger('FertDelaySeconds') . ' Sek.', 0);
    }

    // =========================================================================
    // PRIVATE – ZEITPLAN
    // =========================================================================

    private function buildDailyQueue(): array {
        $dowIdx = (int)date('N') - 1; // 0=Mo … 6=So
        $day    = self::DAY_PROPS[$dowIdx];
        $queue  = [];

        foreach (self::ZONE_PREFIX as $prefix => $zone) {
            if (!$this->ReadPropertyBoolean($prefix . 'Enabled'))       continue;
            if (!$this->ReadPropertyBoolean($prefix . 'Day' . $day))    continue;

            $queue[] = [
                'zone'         => $zone,
                'targetLiters' => $this->ReadPropertyFloat($prefix . 'TargetLiters'),
            ];
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

        $raining = GetValueBoolean($rainID);
        if ($raining) {
            $until = time() + $this->ReadPropertyInteger('RainBlockHours') * 3600;
            $this->WriteAttributeInteger('RainBlockUntil', $until);
            $this->SetValue('RainBlocked', true);
            $this->SendDebug('Rain', 'Regen – Sperre bis ' . date('d.m.Y H:i', $until), 0);

            if ($this->ReadAttributeInteger('CurrentZone') != self::ZONE_NONE) {
                $this->SendDebug('Rain', 'Bewässerung wird gestoppt', 0);
                $this->StopAll();
            }
        }
        // Regen aufgehört: Sperre bleibt bis Ablauf (isRainBlocked prüft Zeit)
    }

    private function isRainBlocked(): bool {
        $until = $this->ReadAttributeInteger('RainBlockUntil');
        if ($until == 0) return false;

        if (time() >= $until) {
            $this->WriteAttributeInteger('RainBlockUntil', 0);
            $this->SetValue('RainBlocked', false);
            return false;
        }
        return true;
    }

    /**
     * Gibt true zurück wenn die Bodenfeuchte ausreichend ist (Zone überspringen).
     */
    private function isMoistureOk(int $zone): bool {
        $prefix   = $this->zonePrefixById($zone);
        $sensorID = 0;
        $threshold = 70.0;

        if ($zone == self::ZONE_HECKE) {
            $sensorID  = $this->ReadPropertyInteger('SoilHeckeID');
            $threshold = $prefix ? $this->ReadPropertyFloat($prefix . 'MoistureThreshold') : 70.0;
        } elseif ($zone == self::ZONE_HANG) {
            $sensorID  = $this->ReadPropertyInteger('SoilHangID');
            $threshold = $prefix ? $this->ReadPropertyFloat($prefix . 'MoistureThreshold') : 70.0;
        } elseif ($zone == self::ZONE_HECKE_GARAGE) {
            $sensorID  = $this->ReadPropertyInteger('SoilHeckeID'); // Näherungswert
            $threshold = 80.0; // etwas toleranter da kein eigener Sensor
        }

        if ($sensorID == 0 || !IPS_VariableExists($sensorID)) return false;

        $moisture = GetValueFloat($sensorID);
        $this->SendDebug('Moisture', sprintf('%s: %.1f%% (Schwelle %.1f%%)', self::ZONE_NAMES[$zone], $moisture, $threshold), 0);
        return $moisture >= $threshold;
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

    private function accumulateDailyVolume(float $liters) {
        $today = date('Y-m-d');
        if ($this->ReadAttributeString('TodayDate') !== $today) {
            $this->WriteAttributeString('TodayDate',        $today);
            $this->WriteAttributeFloat( 'TodayVolumeLiters', 0.0);
        }

        $newDaily = $this->ReadAttributeFloat('TodayVolumeLiters') + $liters;
        $this->WriteAttributeFloat('TodayVolumeLiters', $newDaily);
        $this->SetValue('DailyVolume', round($newDaily, 1));

        // Kosten
        $price = $this->ReadPropertyFloat('WaterPrice');
        $this->SetValue('DailyCost', round($newDaily / 1000.0 * $price, 4));

        // Wochenvolumen
        $week = date('o-W');
        if ($this->ReadAttributeString('WeekStart') !== $week) {
            $this->WriteAttributeString('WeekStart',        $week);
            $this->WriteAttributeFloat( 'WeekVolumeLiters', 0.0);
        }
        $newWeekly = $this->ReadAttributeFloat('WeekVolumeLiters') + $liters;
        $this->WriteAttributeFloat('WeekVolumeLiters', $newWeekly);
        $this->SetValue('WeeklyVolume', round($newWeekly, 1));
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
            } elseif ($this->isRainBlocked()) {
                $until  = $this->ReadAttributeInteger('RainBlockUntil');
                $status = '🌧 Regen-Sperre bis ' . date('H:i', $until);
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
        IPS_SetPosition($catID, 12);

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
    }

    // =========================================================================
    // PRIVATE – HILFSMETHODEN
    // =========================================================================

    private function zonePrefixById(int $zone): ?string {
        $map = array_flip(self::ZONE_PREFIX); // zone → prefix
        return $map[$zone] ?? null;
    }
}
