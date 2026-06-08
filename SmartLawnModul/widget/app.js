/**
 * SmartLawn AI - IP-Symcon HTML-SDK Logik
 * Kommuniziert direkt mit dem IP-Symcon Server per WebSockets.
 */

// Die globale Konfiguration, die wir vom PHP-Modul beim Start empfangen
let config = {
    globalVars: {
        airTemp: 0.0,
        vpd: 0.0,
        lux: 0,
        partyModeActive: false
    },
    zones: []
};

// --- INITIALISIERUNG & IP-SYMCON HOOK ---
function onSymconMessage(message) {
    const data = message.data;
    
    // Komplettes Config-Update vom Modul
    if (data.type === 'INITIAL_CONFIG') {
        config = data.payload;
        renderZones();
        updateGlobalData();
    }
    
    // Live-Update einer einzelnen Variable
    if (data.type === 'VARIABLE_UPDATE') {
        handleVariableUpdate(data.variableId, data.value);
    }
}

// Event-Listener für das Symcon SDK anbinden
window.addEventListener("message", onSymconMessage, false);

// --- RENDERING FUNKTIONEN ---

function updateGlobalData() {
    document.getElementById('val-temp').innerText = config.globalVars.airTemp.toFixed(1);
    document.getElementById('val-vpd').innerText = config.globalVars.vpd.toFixed(2);
    document.getElementById('val-lux').innerText = config.globalVars.lux;
    
    const btnParty = document.getElementById('btn-party');
    if (config.globalVars.partyModeActive) {
        btnParty.innerText = "🎉 Party-Modus: Aktiv";
        btnParty.classList.add('btn-active');
    } else {
        btnParty.innerText = "🎉 Party-Modus: Aus";
        btnParty.classList.remove('btn-active');
    }
}

function renderZones() {
    const container = document.getElementById('zones-container');
    container.innerHTML = ''; 

    config.zones.forEach(zone => {
        const fillPercentage = Math.min(100, Math.max(0, zone.currentMoisture));
        const markerPosition = Math.min(100, Math.max(0, zone.startMoisture));

        const card = document.createElement('div');
        card.className = 'zone-card';
        card.setAttribute('data-status', zone.status); 
        card.id = `zone-card-${zone.id}`;

        card.innerHTML = `
            <div class="zone-header">
                <span class="zone-title">${zone.name}</span>
                <span class="zone-status-text" id="status-text-${zone.id}">${zone.status}</span>
            </div>
            
            <div class="progress-container">
                <div class="progress-labels">
                    <span>Ist: <b id="moist-val-${zone.id}">${zone.currentMoisture.toFixed(1)}%</b></span>
                    <span>Ziel: ${zone.targetMoisture}%</span>
                </div>
                <div class="progress-bar-bg">
                    <div class="progress-marker-start" style="left: ${markerPosition}%" title="Trockenstress bei ${zone.startMoisture}%"></div>
                    <div class="progress-bar-fill" id="moist-bar-${zone.id}" style="width: ${fillPercentage}%"></div>
                </div>
            </div>
            
            <div class="zone-stats">
                <span>KI Effizienz: ${zone.efficiency.toFixed(2)} %/min</span>
                <span>Watchdog: ${zone.hardwareOk ? '🟢 OK' : '🔴 FEHLER'}</span>
            </div>
        `;
        container.appendChild(card);
    });
}

// --- LIVE UPDATES ---

function handleVariableUpdate(variableId, newValue) {
    const zoneIndex = config.zones.findIndex(z => z.sensorVarId === variableId);
    if (zoneIndex !== -1) {
        config.zones[zoneIndex].currentMoisture = newValue;
        
        document.getElementById(`moist-val-${config.zones[zoneIndex].id}`).innerText = newValue.toFixed(1) + '%';
        document.getElementById(`moist-bar-${config.zones[zoneIndex].id}`).style.width = newValue + '%';
    }
}

// --- BENUTZER INTERAKTIONEN (Senden an PHP) ---

function togglePartyMode() {
    if (typeof parent.requestMessage === 'function') {
        parent.requestMessage('TOGGLE_PARTY_MODE', {});
    } else {
        console.log("Symcon SDK nicht verbunden. Befehl: TOGGLE_PARTY_MODE");
    }
}

function triggerForceStart() {
    if (confirm("Möchtest du den Sequencer für alle Kreise manuell starten? (Dünger-Modus)")) {
        if (typeof parent.requestMessage === 'function') {
            parent.requestMessage('FORCE_START_SEQUENCE', {});
        } else {
            console.log("Symcon SDK nicht verbunden. Befehl: FORCE_START");
        }
    }
}