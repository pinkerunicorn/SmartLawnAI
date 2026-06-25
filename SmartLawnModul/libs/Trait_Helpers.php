<?php

trait SmartLawnAI_Helpers {

    private function SetSummaryStatus(string $status) {
        $id = @$this->GetIDForIdent('SummaryStatus');
        if ($id > 0) {
            SetValue($id, $status);
        }
        
        $vID = @$this->GetIDForIdent('VestaboardStatus');
        if ($vID > 0) {
            SetValue($vID, $this->GetShortStatus($status));
        }
    }

    private function GetShortStatus(string $longStatus): string {
        if (strpos($longStatus, 'HARDWARE-FEHLER') !== false) return 'Fehler (Hardware)';
        if (strpos($longStatus, 'Fehler:') !== false) return 'Fehler (API)';
        if (strpos($longStatus, 'Bewässert:') !== false) {
            if (preg_match('/Bewässert: .*? \((.*?)\) \(noch (\d+) Min\)/', $longStatus, $m)) {
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
        if (strpos($longStatus, 'Nächste Prüfung: In Kürze') !== false) {
            return 'Prüfung in Kürze';
        }
        if (preg_match('/Nächste Prüfung: (\d{2}:\d{2})/', $longStatus, $m)) {
            return 'Prüfung ' . $m[1];
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
}
