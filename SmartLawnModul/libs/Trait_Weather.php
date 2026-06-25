<?php

trait SmartLawnAI_Weather {

    public function UpdateWeather(): void {
        $lat = (float)$this->ReadPropertyFloat('Latitude');
        $lon = (float)$this->ReadPropertyFloat('Longitude');
        if ($lat == 0.0 && $lon == 0.0) {
            return;
        }

        $omUrl = "https://api.open-meteo.com/v1/forecast?latitude=" . number_format($lat, 6, '.', '') . "&longitude=" . number_format($lon, 6, '.', '') . "&daily=precipitation_sum&timezone=auto&forecast_days=3";
        $omContent = @Sys_GetURLContent($omUrl);
        if ($omContent !== false) {
            $omData = json_decode($omContent, true);
            if (isset($omData['daily']) && isset($omData['daily']['precipitation_sum'])) {
                $sums = $omData['daily']['precipitation_sum'];
                if (isset($sums[0])) SetValue($this->GetIDForIdent('ForecastRainToday'), (float)$sums[0]);
                if (isset($sums[1])) SetValue($this->GetIDForIdent('ForecastRainTomorrow'), (float)$sums[1]);
                $this->LogAndDebug('Weather', 'Open-Meteo Regen-Vorhersage aktualisiert: Heute ' . (float)$sums[0] . 'mm, Morgen ' . (float)$sums[1] . 'mm', 0);
            }
        } else {
            $this->LogAndDebug('Weather', 'Fehler beim Abrufen der Open-Meteo Wetterdaten.', 0);
        }
    }
}
