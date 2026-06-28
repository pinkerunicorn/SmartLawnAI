<?php

trait SmartLawnAI_Helpers {

    private function SetSummaryStatus(string $status) {
        $id = @$this->GetIDForIdent('SummaryStatus');
        if ($id > 0) {
            $this->SetValue('SummaryStatus', $status);
        }
        
        $vID = @$this->GetIDForIdent('VestaboardStatus');
        if ($vID > 0) {
            $this->SetValue('VestaboardStatus', $this->GetShortStatus($status));
        }
    }

    private function GetShortStatus(string $longStatus): string {
        if (strpos($longStatus, 'HARDWARE-FEHLER') !== false) return 'Fehler (Hardware)';
        if (strpos($longStatus, 'Fehler:') !== false) return 'Fehler (API)';
        if (strpos($longStatus, 'Bewässert:') !== false) {
            if (preg_match('/Bewässert: .*? \((.*?)\) \(noch (\d+(:\d+)?) Min\)/', $longStatus, $m)) {
                return $m[1] . ' (' . $m[2] . ' Min)';
            }
            if (preg_match('/Bewässert: .*? \((.*?)\)/', $longStatus, $m)) {
                return $m[1] . ' läuft';
            }
            return 'Bewässerung läuft';
        }
        if (strpos($longStatus, 'Bewässere:') !== false) {
            return str_replace('Bewässere: ', '', $longStatus) . ' startet';
        }
        if (strpos($longStatus, 'Sickerpause:') !== false) {
            return str_replace('Sickerpause: ', 'Pause ', $longStatus);
        }
        if (strpos($longStatus, 'Standby') !== false) {
            return 'Standby';
        }
        if (strpos($longStatus, 'Berechne') !== false) return 'KI rechnet';
        if (strpos($longStatus, 'Plan berechnet') !== false) return 'Plan fertig';
        if (strpos($longStatus, 'Automatik') !== false) return 'Automatik Aus';
        if (strpos($longStatus, 'Manueller Start') !== false) return 'Start angefragt';
        
        return 'Bereit';
    }

    private function LogAndDebug($Topic, $Payload, $Format = 0) {
        $this->SendDebug($Topic, $Payload, $Format);
        if (is_scalar($Payload)) {
            IPS_LogMessage('SmartLawnAI', $Topic . ': ' . $Payload);
        } else {
            IPS_LogMessage('SmartLawnAI', $Topic . ': ' . json_encode($Payload));
        }
    }



    private function EnableArchive($variableID) {
        if ($variableID > 0) {
            $archiveIDs = IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}');
            if (count($archiveIDs) > 0) {
                $archiveID = $archiveIDs[0];
                if (!AC_GetLoggingStatus($archiveID, $variableID)) {
                    AC_SetLoggingStatus($archiveID, $variableID, true);
                    IPS_ApplyChanges($archiveID);
                }
            }
        }
    }

    private function MaintainScheduleEvents(bool $active) {
        $schedule = $this->ReadPropertyInteger('IrrigationSchedule');
        
        $times = [];
        if ($schedule === 1) {
            $times = [6];
        } else if ($schedule === 2) {
            $times = [6, 18];
        } else if ($schedule === 4) {
            $times = [0, 6, 12, 18];
        } else if ($schedule === 6) {
            $times = [0, 4, 8, 12, 16, 20];
        } else if ($schedule === 8) {
            $times = [0, 3, 6, 9, 12, 15, 18, 21];
        } else {
            $times = [6, 18];
        }

        for ($i = 0; $i <= 23; $i++) {
            $ident = 'SLAI_ScheduleEvent_' . $i;
            $eid = @$this->GetIDForIdent($ident);
            
            if ($active && in_array($i, $times)) {
                if ($eid === false) {
                    $eid = IPS_CreateEvent(1); // Zyklisches Event
                    IPS_SetParent($eid, $this->InstanceID);
                    IPS_SetName($eid, sprintf('Zeitplan Prüfung (%02d:00)', $i));
                    IPS_SetIdent($eid, $ident);
                    IPS_SetEventScript($eid, "SLAI_ScheduledEvaluation(\$_IPS['TARGET']);");
                    IPS_SetEventCyclic($eid, 0, 0, 0, 0, 0, 0); // Täglich
                    IPS_SetEventCyclicTimeFrom($eid, $i, 0, 0);
                }
                IPS_SetEventActive($eid, true);
            } else {
                if ($eid !== false) {
                    IPS_DeleteEvent($eid);
                }
            }
        }
    }

    public function AddLogEvent(string $title, string $details = '', string $color = '#4CAF50') {
        $logVarID = $this->GetIDForIdent('IrrigationLog');
        $currentLog = GetValue($logVarID);
        // Platzhalter entfernen
        $currentLog = str_replace("<div style='padding: 10px; color: #888; font-style: italic;'>Noch keine Bew&auml;sserungsvorg&auml;nge protokolliert.</div>", "", $currentLog);
        
        $date = date('H:i'); // Nur Uhrzeit, Datum wäre zu lang für jeden Schritt
        
        // Timeline-Event Styling
        $newEntry = "<div style='margin-bottom: 5px; padding: 5px 10px; border-left: 3px solid {$color}; background: rgba(255, 255, 255, 0.05); font-size: 0.9em; display: flex; align-items: flex-start;'>";
        $newEntry .= "<div style='color: #888; margin-right: 10px; min-width: 40px; padding-top: 2px;'>{$date}</div>";
        $newEntry .= "<div><div style='font-weight: bold;'>{$title}</div>";
        if (!empty($details)) {
            $newEntry .= "<div style='color: #aaa; font-size: 0.9em; margin-top: 2px;'>{$details}</div>";
        }
        $newEntry .= "</div></div>";

        // Füge neuen Eintrag oben an
        $updatedLog = $newEntry . $currentLog;
        
        // Log-Größe begrenzen auf die letzten 50 Einträge
        $entries = explode("<div style='margin-bottom: 5px;", $updatedLog);
        if (count($entries) > 51) { // 51 weil explode ein leeres erstes Element erzeugt
            $updatedLog = "";
            for ($i = 1; $i <= 50; $i++) {
                $updatedLog .= "<div style='margin-bottom: 5px;" . $entries[$i];
            }
        }

        $this->SetValue('IrrigationLog', $updatedLog);
    }

    public function ResolveSprinklerObject(int $objectId): array {
        $res = [
            'ValveID' => 0,
            'HardwareStatusID' => 0,
            'DurationID' => 0,
            'RemainingSecondsID' => 0,
            'ActivityID' => 0
        ];

        if ($objectId <= 0) return $res;

        if (IPS_VariableExists($objectId)) {
            $res['ValveID'] = $objectId;
            return $res;
        }

        if (IPS_InstanceExists($objectId)) {
            $children = IPS_GetChildrenIDs($objectId);
            foreach ($children as $child) {
                if (!IPS_VariableExists($child)) continue;
                $obj = IPS_GetObject($child);
                $ident = strtolower($obj['ObjectIdent']);
                
                if ($ident === 'action') $res['ValveID'] = $child;
                elseif (in_array($ident, ['state', 'status'])) $res['HardwareStatusID'] = $child;
                elseif ($res['HardwareStatusID'] === 0 && in_array($ident, ['lasterror', 'errorcode'])) $res['HardwareStatusID'] = $child;
                elseif ($ident === 'duration') $res['DurationID'] = $child;
                elseif (in_array($ident, ['remaining', 'remainingtime'])) $res['RemainingSecondsID'] = $child;
                elseif ($ident === 'activity') $res['ActivityID'] = $child;
            }
        }
        return $res;
    }
}
