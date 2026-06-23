let isInitialized = false;

function handleMessage(dataRaw) {
    let data;
    try {
        data = typeof dataRaw === 'string' ? JSON.parse(dataRaw) : dataRaw;
    } catch (e) {
        console.error("Invalid data format", e);
        return;
    }

    if (!isInitialized && data.zones) {
        initDashboard(data);
        isInitialized = true;
    }

    updateDashboard(data);
}

function initDashboard(data) {
    const container = document.getElementById('zonesContainer');
    if (!container) return;
    container.innerHTML = '';
    
    data.zones.forEach(zone => {
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
    });
}

function updateDashboard(data) {
    if (data.forecastRainToday !== undefined) {
        const el = document.getElementById('rainToday');
        if (el) el.innerText = parseFloat(data.forecastRainToday).toFixed(1) + ' mm';
    }
    if (data.forecastRainTomorrow !== undefined) {
        const el = document.getElementById('rainTomorrow');
        if (el) el.innerText = parseFloat(data.forecastRainTomorrow).toFixed(1) + ' mm';
    }
    if (data.summaryStatus !== undefined) {
        const el = document.getElementById('summaryStatus');
        if (el) el.innerText = data.summaryStatus;
        const indicator = document.getElementById('statusIndicator');
        if (indicator) {
            if (data.summaryStatus.includes('Bewässert:')) {
                indicator.classList.add('active');
                document.getElementById('statusBanner').style.color = 'var(--text-main)';
            } else {
                indicator.classList.remove('active');
                document.getElementById('statusBanner').style.color = 'var(--text-muted)';
            }
        }
    }

    if (data.zones && Array.isArray(data.zones)) {
        data.zones.forEach(zone => {
            if (zone.status !== undefined && zone.status !== false) {
                const label = document.getElementById(`statusLabel_${zone.id}`);
                if (label) {
                    label.innerText = zone.status;
                    label.classList.remove('watering', 'waiting');
                    if (zone.status === 'WATERING' || zone.status === 'VERIFYING_START') {
                        label.classList.add('watering');
                    } else if (zone.status === 'WAITING_FOR_RESULT') {
                        label.classList.add('waiting');
                    }
                }
            }
            if (zone.moisture !== undefined && zone.moisture !== false) {
                const percent = Math.min(100, Math.max(0, parseFloat(zone.moisture)));
                const valEl = document.getElementById(`moistureVal_${zone.id}`);
                const barEl = document.getElementById(`progressBar_${zone.id}`);
                if (valEl) valEl.innerText = percent.toFixed(1) + '%';
                if (barEl) {
                    barEl.style.width = percent + '%';
                    if (percent < 25) {
                        barEl.style.background = 'linear-gradient(90deg, var(--accent-orange), #ef4444)';
                    } else {
                        barEl.style.background = 'linear-gradient(90deg, var(--accent-green), var(--accent-blue))';
                    }
                }
            }
            if (zone.efficiency !== undefined && zone.efficiency !== false) {
                const effEl = document.getElementById(`effVal_${zone.id}`);
                if (effEl) effEl.innerText = parseFloat(zone.efficiency).toFixed(2) + 'x';
            }
            if (zone.duration !== undefined && zone.duration !== false) {
                const durEl = document.getElementById(`durVal_${zone.id}`);
                if (durEl) durEl.innerText = parseFloat(zone.duration).toFixed(1) + ' Min';
            }
        });
    }
}
