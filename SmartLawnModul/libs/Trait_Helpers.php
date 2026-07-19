<?php

trait SmartLawnAI_Helpers {

    private function SetSummaryStatus(string $status): void {
        $id = @$this->GetIDForIdent('SummaryStatus');
        if ($id > 0) {
            $this->SetValue('SummaryStatus', $status);
        }
        
        $vID = @$this->GetIDForIdent('VestaboardMessage');
        if ($vID > 0) {
            $this->SetValue('VestaboardMessage', $this->GetShortStatus($status));
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
        if (strpos($longStatus, 'Wartet auf Ventil:') !== false) {
            if (preg_match('/Wartet auf Ventil: (.*?) \(/', $longStatus, $m)) {
                return 'Wartet: ' . $m[1];
            }
            return 'Wartet auf Ventil';
        }
        if (strpos($longStatus, 'Bewässere:') !== false) {
            return str_replace('Bewässere: ', '', $longStatus) . ' startet';
        }
        if (strpos($longStatus, 'Sickerpause:') !== false) {
            if (preg_match('/Sickerpause: (.*)/', $longStatus, $m)) {
                return 'Pause ' . $m[1];
            }
            return str_replace('Sickerpause: ', 'Pause ', $longStatus);
        }
        if (strpos($longStatus, 'Standby') !== false) {
            return 'Standby';
        }
        if (strpos($longStatus, 'Berechne') !== false) return 'KI rechnet';
        if (strpos($longStatus, 'Plan berechnet') !== false) return 'Plan fertig';
        if (strpos($longStatus, 'Automatik') !== false) return 'Automatik Aus';
        if (strpos($longStatus, 'Manueller Start') !== false) return 'Start angefragt';
        
        if (strpos($longStatus, 'Bereit (Nächste Ausführung:') !== false) {
            if (preg_match('/Nächste Ausführung: (.*?) um (.*?) Uhr/', $longStatus, $m)) {
                return 'Wasser: ' . $m[1] . ' ' . $m[2];
            }
        }
        
        return 'Bereit';
    }

    private function LogAndDebug(string $Topic, string $Payload, int $Format = 0): void {
        $this->SendDebug($Topic, $Payload, $Format);
        if (is_scalar($Payload)) {
            IPS_LogMessage('SmartVillaKunterbunt', 'SmartLawnAI: ' . $Topic . ': ' . $Payload);
        } else {
            IPS_LogMessage('SmartVillaKunterbunt', 'SmartLawnAI: ' . $Topic . ': ' . json_encode($Payload));
        }
    }



    private function EnableArchive(int $variableID): void {
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

    private function MaintainScheduleEvents(bool $active): void {
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

    public function AddLogEvent(string $title, string $details = '', string $color = '#2196F3') {
        $logVarID = $this->GetIDForIdent('IrrigationLog');
        $currentLog = GetValue($logVarID);
        
        // Alten Plaintext bereinigen, wenn kein HTML-Tag vorhanden
        if (strpos($currentLog, 'sl-log-entry') === false) {
            $currentLog = '';
        }
        
        $dateStr = date('d.m.Y');
        $timeStr = date('H:i:s'); 
        
        $newEntry = '
        <div class="sl-log-entry" style="margin-bottom: 8px; padding: 10px; background: rgba(128, 128, 128, 0.1); border-left: 4px solid '.$color.'; border-radius: 4px; font-family: sans-serif;">
            <div style="font-size: 11px; opacity: 0.6; margin-bottom: 4px;">'.$dateStr.' &middot; '.$timeStr.' Uhr</div>
            <div style="font-weight: 600; font-size: 14px; margin-bottom: 3px;">'.$title.'</div>
            <div style="font-size: 13px; opacity: 0.8; line-height: 1.4;">'.$details.'</div>
        </div>';
        
        // Log-Größe begrenzen auf die letzten 30 Einträge (Split am Marker)
        $entries = explode('<div class="sl-log-entry"', $currentLog);
        $entries = array_filter($entries, function($e) { return trim($e) !== ''; });
        
        $htmlEntries = [];
        $htmlEntries[] = $newEntry;
        $count = 1;
        foreach ($entries as $e) {
            if ($count >= 30) break;
            $htmlEntries[] = '<div class="sl-log-entry"' . $e;
            $count++;
        }
        
        $updatedLog = implode("", $htmlEntries);
        $this->SetValue('IrrigationLog', $updatedLog);
    }

    protected function SafeRequestAction(int $variableID, $value): bool {
        if (!IPS_VariableExists($variableID)) return false;
        try {
            return RequestAction($variableID, $value);
        } catch (\Throwable $e) {
            $this->LogAndDebug('SafeRequestAction', 'Fehler beim Senden an ID ' . $variableID . ': ' . $e->getMessage(), 0);
            IPS_LogMessage('SmartVillaKunterbunt', 'SmartLawnAI: ' . 'Sende-Fehler an ID ' . $variableID . ': ' . $e->getMessage());
            return false;
        }
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
                
                if (in_array($ident, ['action', 'valvecontrol', 'control'])) $res['ValveID'] = $child;
                elseif (in_array($ident, ['state', 'status'])) $res['HardwareStatusID'] = $child;
                elseif ($res['HardwareStatusID'] === 0 && in_array($ident, ['lasterror', 'errorcode', 'lasterrorcode'])) $res['HardwareStatusID'] = $child;
                elseif (in_array($ident, ['duration', 'valveduration'])) {
                    if ($ident === 'valveduration') {
                        if ($res['DurationID'] !== 0) {
                            $res['RemainingSecondsID'] = $res['DurationID'];
                        }
                        $res['DurationID'] = $child;
                    } elseif ($res['DurationID'] === 0) {
                        $res['DurationID'] = $child; 
                    } else {
                        $res['RemainingSecondsID'] = $child; 
                    }
                }
                elseif (in_array($ident, ['remaining', 'remainingtime'])) $res['RemainingSecondsID'] = $child;
                elseif ($ident === 'activity') $res['ActivityID'] = $child;
            }
        }
        return $res;
    }
}
