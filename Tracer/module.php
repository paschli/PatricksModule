<?

declare(strict_types=1);

class DeviceMonitor extends IPSModule
{
    // --- Modul-Grundfunktionen ---

    public function Create(): void
    {
        parent::Create();
        $this->RegisterPropertyString('DeviceList', '[]');
        $this->RegisterPropertyString('LogTag', 'DeviceMonitor');
    }

    public function Destroy(): void
    {
        // Alle Kind-Ereignisse werden von Symcon automatisch gelöscht
        // wenn die Instanz gelöscht wird
        parent::Destroy();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();
        $this->SyncEvents();
    }

    // --- Ereignisse synchronisieren ---

    public function SyncEvents(): void
    {
        $deviceList = json_decode($this->ReadPropertyString('DeviceList'), true);

        if (!is_array($deviceList)) {
            $this->LogMessage('DeviceList ist kein gültiges JSON', KL_ERROR);
            return;
        }

        // Alle bestehenden Kind-Ereignisse dieser Instanz einlesen
        $existingEvents = $this->getExistingEvents();

        // Aktive VariableIDs aus der Konfiguration sammeln
        $configuredVariableIDs = [];
        foreach ($deviceList as $device) {
            if (!$device['Active'] || $device['VariableID'] <= 0) {
                continue;
            }
            $configuredVariableIDs[] = (int) $device['VariableID'];
        }

        // Ereignisse löschen, deren Variable nicht mehr in der Liste ist
        foreach ($existingEvents as $variableID => $eventID) {
            if (!in_array($variableID, $configuredVariableIDs)) {
                IPS_DeleteEvent($eventID);
                $this->LogMessage(
                    "Ereignis für Variable $variableID (EventID: $eventID) gelöscht",
                    KL_NOTIFY
                );
            }
        }

        // Ereignisse anlegen oder aktualisieren
        foreach ($deviceList as $device) {
            if (!$device['Active'] || $device['VariableID'] <= 0) {
                continue;
            }

            $variableID = (int) $device['VariableID'];
            $deviceName = $device['Name'];

            if (!IPS_VariableExists($variableID)) {
                $this->LogMessage(
                    "Variable $variableID für Gerät '$deviceName' existiert nicht",
                    KL_WARNING
                );
                continue;
            }

            if (isset($existingEvents[$variableID])) {
                // Ereignis existiert bereits, nichts zu tun
                continue;
            }

            // Neues Ereignis anlegen
            $eventID = IPS_CreateEvent(0); // 0 = Auslöseereignis
            IPS_SetName($eventID, 'Monitor: ' . $deviceName);
            IPS_SetParent($eventID, $this->InstanceID);
            IPS_SetEventTrigger($eventID, 1, $variableID); // 1 = Bei Änderung
            IPS_SetEventScript($eventID, 'DM_HandleChange(' . $this->InstanceID . ', ' . $variableID . ');');
            IPS_SetEventActive($eventID, true);

            $this->LogMessage(
                "Ereignis für Variable $variableID ('$deviceName') angelegt (EventID: $eventID)",
                KL_NOTIFY
            );
        }

        $this->SetStatus(102);
    }

    // --- Zustandswechsel verarbeiten ---

    public function HandleChange(int $variableID): void
    {
        $deviceList  = json_decode($this->ReadPropertyString('DeviceList'), true);
        $logTag      = $this->ReadPropertyString('LogTag');

        // Gerät in der Liste finden
        $device = null;
        foreach ($deviceList as $entry) {
            if ((int) $entry['VariableID'] === $variableID) {
                $device = $entry;
                break;
            }
        }

        if ($device === null || !$device['Active']) {
            return;
        }

        $deviceName = $device['Name'];

        // Alter und neuer Wert
        $variable = IPS_GetVariable($variableID);
        $newValue  = GetValue($variableID);
        $oldValue  = $variable['VariableValue']; // letzter gecachter Wert

        $newValueStr = $this->formatValue($newValue);
        $oldValueStr = $this->formatValue($oldValue);

        // Auslöser ermitteln
        $senderInfo = $this->resolveSender();

        // Log-Eintrag schreiben
        $deviceTag = $logTag . '_' . preg_replace('/\s+/', '_', $deviceName);
        $message   = sprintf(
            "[%s] Zustandswechsel: %s -> %s | Auslöser: %s",
            $deviceName,
            $oldValueStr,
            $newValueStr,
            $senderInfo
        );

        IPS_LogMessage($logTag,   $message); // gemeinsamer Tag zum Filtern
        IPS_LogMessage($deviceTag, $message); // gerätespezifischer Tag
    }

    // --- Hilfsfunktionen ---

    private function getExistingEvents(): array
    {
        // Gibt ein Array [VariableID => EventID] aller Kind-Ereignisse zurück
        $events = [];
        $children = IPS_GetChildrenIDs($this->InstanceID);

        foreach ($children as $childID) {
            if (IPS_EventExists($childID)) {
                $event = IPS_GetEvent($childID);
                $triggerVariableID = $event['TriggerVariableID'] ?? 0;
                if ($triggerVariableID > 0) {
                    $events[$triggerVariableID] = $childID;
                }
            }
        }

        return $events;
    }

    private function resolveSender(): string
    {
        $sender = $_IPS['SENDER'] ?? 'Unbekannt';
        $info   = "Sender: $sender";

        switch ($sender) {
            case 'Variable':
                $sourceID = $_IPS['VARIABLE'] ?? 0;
                if ($sourceID > 0 && IPS_VariableExists($sourceID)) {
                    $name  = IPS_GetName($sourceID);
                    $info .= " | Variable: '$name' (ID: $sourceID)";
                }
                break;

            case 'RunScript':
                $scriptID = $_IPS['SCRIPT'] ?? 0;
                if ($scriptID > 0 && IPS_ScriptExists($scriptID)) {
                    $name  = IPS_GetName($scriptID);
                    $info .= " | Skript: '$name' (ID: $scriptID)";
                }
                break;

            case 'TimerEvent':
            case 'Event':
                $eventID = $_IPS['EVENT'] ?? 0;
                if ($eventID > 0 && IPS_EventExists($eventID)) {
                    $name  = IPS_GetName($eventID);
                    $info .= " | Ereignis: '$name' (ID: $eventID)";
                }
                break;

            case 'WebFront':
                $userID = $_IPS['WEBFRONT_USER_ID'] ?? 0;
                $info  .= $userID > 0 ? " | Benutzer-ID: $userID" : " | Benutzer unbekannt";
                break;

            case 'Execute':
                $info .= " | Manuell ausgeführt";
                break;
        }

        return $info;
    }

    private function formatValue(mixed $value): string
    {
        if (is_bool($value))   return $value ? 'true' : 'false';
        if (is_null($value))   return 'null';
        if (is_float($value))  return number_format($value, 2, '.', '');
        return (string) $value;
    }
}
