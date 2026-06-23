# System-Briefing: SmartLawn AI (IP-Symcon Modul)

Dieses Dokument dient als zentrale Leitlinie für die automatisierte Weiterentwicklung des IP-Symcon Moduls `SmartLawnAI`. Alle Code-Änderungen in `module.php`, `form.json` und dem `/widget`-Ordner müssen strikt den hier definierten regelungstechnischen und softwarearchitektonischen Prinzipien folgen.

## 1. System-Übersicht & Architektur
`SmartLawnAI` ist ein sensorgestütztes, ereignisgesteuertes (Event-Driven) Bewässerungsmodul für IP-Symcon (ab v7.1), das eine native Integration zur Gardena-Cloud nutzt. Das System arbeitet nach dem MVC-Prinzip (Model-View-Controller):
* **Backend (`module.php`):** Verwaltet die thermodynamische Logik, den KI-Lernalgorithmus und die State Machine.
* **Frontend (`/widget`):** Eine reaktive Kachel-Visualisierung basierend auf dem IP-Symcon HTML-SDK (HTML5, CSS3, Vanilla JS). Communication erfolgt asynchron via WebSockets/Meldungen.

## 2. Das Kern-Regelungskonzept
Das System verzichtet bewusst auf starre Zeitfenster und arbeitet rein ereignisgesteuert, um das Gießen erst bei Erreichen der **Bewässerungs-Trigger-Feuchte** zu starten und den Boden bis zur **Ziel-Feuchte** zu sättigen.

1.  **Der Trigger (Bewässerungs-Trigger-Feuchte):** Die Bodenfeuchte sinkt unter einen konfigurierbaren Mindestwert (z. B. 20 %). Erst dann verlässt die Zone den Zustand `IDLE`.
2.  **Das Ziel (Ziel-Feuchte):** Ist der Trigger erreicht, berechnet die KI mathematisch die nötige Wassermenge, um den Boden komplett zu sättigen (z. B. auf 55 %). Dies zwingt Pflanzen zu tiefem Wurzelwachstum.
3.  **Das Fallback-Design (`form.json`):** Es gibt globale Default-Werte für Start- und Ziel-Feuchte. In der dynamischen Zonen-Liste (`List` vom Typ `Zones`) können diese Werte pro Kreis individuell überschrieben werden. Steht der Wert in der Liste auf `0`, greift automatisch der globale Default.

## 3. Die State Machine (Zustandsmatrix)
Jede Bewässerungszone durchläuft eine strikte Zustandsaufteilung, die im lokalen Instanzen-Buffer (`SetBuffer` / `GetBuffer`) gehalten wird:

* `IDLE`: Zone überwacht die Bodenfeuchte.
* `QUEUED`: Schwellwert unterschritten, aber die Leitung ist belegt. Zone wartet in der Warteschlange.
* `VERIFYING_START`: Befehl wurde an die Gardena-API gesendet; das Modul wartet auf die physische Rückmeldung der Hardware.
* `WATERING`: Ventil ist offen. Der Hardware-Timer zählt herunter.
* `WAITING_FOR_RESULT`: Ventil geschlossen. Das System wartet die Sickerpause ab, bis das Wasser die Wurzeln (Sensoren) erreicht.
* `HARDWARE_FEHLER`: Not-Aus-Zustand bei Defekten oder blockierten Ventilen.

### Unumstößliche Sequenz-Regel (Leitungsschutz)
Es darf **niemals mehr als ein Ventil gleichzeitig** geöffnet sein, um den hydraulischen Druck im System konstant zu halten. Wenn eine Zone gießt (`WATERING` oder `VERIFYING_START`), setzt die Variable `$einVentilIstAktiv` ein hartes Interlock. Alle anderen anfordernden Zonen werden zwingend in den Status `QUEUED` versetzt und rücken nach dem Schließen des aktiven Ventils sequenziell nach.

## 4. Thermodynamische KI-Kompensation
Um den realen Wirkungsgrad der Regner anzulernen, berechnet das Modul in der Phase `WAITING_FOR_RESULT` das **Sättigungsdefizit (Vapor Pressure Deficit, VPD)** der Luft sowie die **Globalstrahlung (Helligkeit)**, um atmosphärische Verdunstungsverluste mathematisch zu eliminieren.

### Mathematische Grundlagen für Code-Änderungen:
1.  **Sättigungsdampfdruck (E_s in kPa) via Magnus-Formel bei Lufttemperatur T:**
    E_s = 0.6108 * exp((17.27 * T) / (T + 237.3))
2.  **Vapor Pressure Deficit (VPD) mit relativer Luftfeuchtigkeit RH:**
    VPD = E_s * (1 - (RH / 100))
3.  **Strahlungs-Malus via Helligkeit (Lux):**
    Ab 20.000 Lux wird ein linearer Verdampfungs-Verlustfaktor auf das Lernergebnis addiert.

## 5. Fail-Safe & Hardware-Watchdog (Gardena-Spezifikationen)
1.  **Lokaler Hardware-Timer (`Öffnungsdauer`):** Das Modul darf Ventile nicht über separate Start-/Stopp-Befehle steuern. Die errechnete Gießdauer in Minuten muss vor dem Öffnen direkt in die Gardena-Variable `Öffnungsdauer` geschrieben werden (`RequestAction`). Das Ventil schließt dadurch autark.
2.  **Not-Aus via Hardware-Status:** Vor jedem Start wird die Gardena-Variable `Status` abgefragt. Ist der Wert ungleich `0` oder ungleich `OK`, wechselt die Zone sofort in `HARDWARE_FEHLER` und wird im Sequencer übersprungen.
3.  **Frostschutz:** Liegt die Bodentemperatur unter 5 °C, wird jegliche Aktivität blockiert.

## 6. Entwickler-Richtlinien für Code-Generierung
* **Befehlsausgabe:** Schaltungen von Hardware-Aktoren dürfen *niemals* über `SetValue()` erfolgen. Es ist zwingend `@RequestAction(ID, Value)` zu verwenden.
* **Typisierung:** Alle mathematischen Berechnungen für Temperaturen, VPD und Feuchtigkeit sind strikt als `Float` auszuführen.
* **Widget-Updates:** Das HTML-Frontend darf bei Variablenänderungen nicht neu geladen werden. Die `app.js` abonniert die Variablen über das Symcon-SDK und aktualisiert ausschließlich die betroffenen DOM-Elemente in Echtzeit.