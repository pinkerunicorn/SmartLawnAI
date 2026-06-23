// IP-Symcon HTML SDK Integration
document.addEventListener('DOMContentLoaded', () => {
    if (typeof CONFIG === 'undefined') {
        document.getElementById('summaryStatus').innerText = 'Fehler: config.js nicht geladen.';
        return;
    }

    initDashboard();
});

function initDashboard() {
    // 1. Wetter-Daten registrieren
    if (CONFIG.forecastRainTodayVarId) {
        Symcon.registerVariable(CONFIG.forecastRainTodayVarId, (val) => {
            document.getElementById('rainToday').innerText = parseFloat(val).toFixed(1) + ' mm';
        });
    }

    if (CONFIG.forecastRainTomorrowVarId) {
        Symcon.registerVariable(CONFIG.forecastRainTomorrowVarId, (val) => {
            document.getElementById('rainTomorrow').innerText = parseFloat(val).toFixed(1) + ' mm';
        });
    }

    // 2. Globaler Summary Status
    if (CONFIG.summaryStatusVarId) {
        Symcon.registerVariable(CONFIG.summaryStatusVarId, (val) => {
            document.getElementById('summaryStatus').innerText = val;
            const indicator = document.getElementById('statusIndicator');
            
            if (val.includes('Bewässert:')) {
                indicator.classList.add('active');
                document.getElementById('statusBanner').style.color = 'var(--text-main)';
            } else {
                indicator.classList.remove('active');
                document.getElementById('statusBanner').style.color = 'var(--text-muted)';
            }
        });
    }

    // 3. Dynamische Zonen-Karten generieren
    const container = document.getElementById('zonesContainer');
    
    CONFIG.zones.forEach(zone => {
        // Karte im DOM erstellen
        const cardHtml = `
            <div class="glass-panel zone-card" id="card_${zone.id}">
                <div class="zone-header">
                    <div class="zone-name">${zone.name}</div>
                    <div class="zone-status" id="statusLabel_${zone.id}">IDLE</div>
                </div>
                
                <div class="moisture-display">
                    <div class="moisture-label">
                        <span>Bodenfeuchte</span>
                        <span class="moisture-value" id="moistureVal_${zone.id}">0%</span>
                    </div>
                    <div class="progress-track">
                        <div class="progress-bar" id="progressBar_${zone.id}"></div>
                    </div>
                </div>

                <div class="zone-footer">
                    <div class="stat-item">
                        <span class="stat-label">Lern-Effizienz</span>
                        <span class="stat-value" id="effVal_${zone.id}">1.0x</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-label">Letzte Dauer</span>
                        <span class="stat-value" id="durVal_${zone.id}">0 Min</span>
                    </div>
                </div>
            </div>
        `;
        container.insertAdjacentHTML('beforeend', cardHtml);

        // Variablen registrieren
        if (zone.statusVarId) {
            Symcon.registerVariable(zone.statusVarId, (val) => {
                const label = document.getElementById(`statusLabel_${zone.id}`);
                label.innerText = val;
                
                label.classList.remove('watering', 'waiting');
                if (val === 'WATERING' || val === 'VERIFYING_START') {
                    label.classList.add('watering');
                } else if (val === 'WAITING_FOR_RESULT') {
                    label.classList.add('waiting');
                }
            });
        }

        if (zone.moistureVarId) {
            Symcon.registerVariable(zone.moistureVarId, (val) => {
                const percent = Math.min(100, Math.max(0, parseFloat(val)));
                document.getElementById(`moistureVal_${zone.id}`).innerText = percent.toFixed(1) + '%';
                document.getElementById(`progressBar_${zone.id}`).style.width = percent + '%';
                
                // Color mapping: red if dry (< 25%), green if okay
                const bar = document.getElementById(`progressBar_${zone.id}`);
                if (percent < 25) {
                    bar.style.background = 'linear-gradient(90deg, var(--accent-orange), #ef4444)';
                } else {
                    bar.style.background = 'linear-gradient(90deg, var(--accent-green), var(--accent-blue))';
                }
            });
        }

        if (zone.efficiencyVarId) {
            Symcon.registerVariable(zone.efficiencyVarId, (val) => {
                document.getElementById(`effVal_${zone.id}`).innerText = parseFloat(val).toFixed(2) + 'x';
            });
        }

        if (zone.durationVarId) {
            Symcon.registerVariable(zone.durationVarId, (val) => {
                document.getElementById(`durVal_${zone.id}`).innerText = parseFloat(val).toFixed(1) + ' Min';
            });
        }
    });
}
