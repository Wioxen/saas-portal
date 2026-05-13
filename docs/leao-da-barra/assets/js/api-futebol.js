/**
 * Leão da Barra - API Futebol Integration (Frontend)
 * 
 * Busca dados da API via REST proxy do WordPress
 * Renderiza tabelas, placares ao vivo, fixtures
 * 
 * @package LeaoDaBarra
 */

(function() {
    'use strict';

    const config = window.ldbConfig || {};
    const API_BASE = config.restUrl || '/wp-json/ldb/v1/';
    const VITORIA_ID = config.vitoriaId || 50;

    // ============================================================
    // API HELPER
    // ============================================================
    async function apiFetch(endpoint) {
        try {
            const response = await fetch(API_BASE + endpoint, {
                headers: {
                    'X-WP-Nonce': config.restNonce || '',
                },
            });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            return await response.json();
        } catch (error) {
            console.warn('LDB API Error:', error);
            return null;
        }
    }

    // ============================================================
    // CLASSIFICAÇÃO (Sidebar)
    // ============================================================
    async function loadTabela() {
        const container = document.getElementById('ldb-sidebar-tabela');
        if (!container) return;

        const campeonatoId = container.closest('[data-campeonato]')?.dataset.campeonato || 10;
        const data = await apiFetch(`tabela/${campeonatoId}`);

        if (!data || !Array.isArray(data)) {
            container.innerHTML = '<p class="ldb-no-data">Tabela indisponível</p>';
            return;
        }

        const limit = 10;
        const rows = data.slice(0, limit);

        let html = `
            <table class="ldb-mini-table">
                <thead>
                    <tr>
                        <th style="text-align:left;">Time</th>
                        <th>P</th>
                        <th>J</th>
                        <th>V</th>
                        <th>SG</th>
                    </tr>
                </thead>
                <tbody>
        `;

        rows.forEach((team, i) => {
            const pos = team.posicao || (i + 1);
            const isVitoria = team.time?.time_id === VITORIA_ID;
            const posClass = pos <= 4 ? 'ldb-pos-lib' : (pos <= 6 ? 'ldb-pos-sula' : (pos >= 17 ? 'ldb-pos-rebaixamento' : ''));
            const rowClass = isVitoria ? 'ldb-row-highlight' : '';

            const escudo = team.time?.escudo 
                ? `<img src="${team.time.escudo}" alt="${team.time.sigla}" class="ldb-team-shield" width="20" height="20" loading="lazy">`
                : `<span class="ldb-team-shield" style="background:#eee;width:20px;height:20px;display:inline-block;border-radius:3px;"></span>`;

            html += `
                <tr class="${rowClass}">
                    <td>
                        <div class="ldb-team-row">
                            <span class="ldb-team-pos ${posClass}">${pos}</span>
                            ${escudo}
                            <span>${team.time?.sigla || '???'}</span>
                        </div>
                    </td>
                    <td style="font-weight:600;">${team.pontos ?? 0}</td>
                    <td>${team.jogos ?? 0}</td>
                    <td>${team.vitorias ?? 0}</td>
                    <td>${team.saldo_gols > 0 ? '+' : ''}${team.saldo_gols ?? 0}</td>
                </tr>
            `;
        });

        html += '</tbody></table>';
        container.innerHTML = html;
    }

    // ============================================================
    // JOGOS AO VIVO
    // ============================================================
    async function loadAoVivo() {
        const container = document.getElementById('ldb-live-content');
        const liveBar = document.getElementById('ldb-live-bar');
        const liveBarContent = document.getElementById('ldb-live-bar-content');

        const data = await apiFetch('ao-vivo');

        if (!data || !Array.isArray(data) || data.length === 0) {
            if (container) {
                container.innerHTML = '<div class="ldb-no-live">Nenhum jogo ao vivo no momento</div>';
            }
            return;
        }

        // Sidebar widget
        if (container) {
            let html = '';
            data.forEach(match => {
                html += renderLiveMatch(match);
            });
            container.innerHTML = html;

            const widget = document.getElementById('ldb-sidebar-live');
            if (widget) widget.style.display = 'block';
        }

        // Live bar (topo)
        if (liveBar && liveBarContent) {
            let barHtml = `<div class="ldb-live-header"><span class="ldb-live-dot"></span><span class="ldb-live-label">Ao Vivo</span></div>`;
            data.forEach(match => {
                barHtml += `
                    <div class="ldb-live-match">
                        <span class="ldb-live-teams">${match.time_mandante?.sigla || '?'}</span>
                        <span class="ldb-live-score-num">${match.placar_mandante ?? 0} x ${match.placar_visitante ?? 0}</span>
                        <span class="ldb-live-teams">${match.time_visitante?.sigla || '?'}</span>
                    </div>
                `;
            });
            liveBarContent.innerHTML = barHtml;
            liveBar.style.display = 'block';
        }

        // Auto-refresh a cada 60s
        setTimeout(loadAoVivo, 60000);
    }

    function renderLiveMatch(match) {
        const mandante = match.time_mandante || {};
        const visitante = match.time_visitante || {};
        const campeonato = match.campeonato?.nome_popular || match.campeonato?.nome || '';

        const escudoM = mandante.escudo 
            ? `<img src="${mandante.escudo}" alt="${mandante.sigla}" width="32" height="32" loading="lazy">`
            : `<span style="font-family:'Oswald',sans-serif;font-weight:700;font-size:12px;">${mandante.sigla || '?'}</span>`;

        const escudoV = visitante.escudo 
            ? `<img src="${visitante.escudo}" alt="${visitante.sigla}" width="32" height="32" loading="lazy">`
            : `<span style="font-family:'Oswald',sans-serif;font-weight:700;font-size:12px;">${visitante.sigla || '?'}</span>`;

        return `
            <div class="ldb-live-match-card" style="padding:14px 0;border-bottom:1px solid rgba(255,255,255,0.08);">
                <div style="font-size:10px;color:rgba(255,255,255,0.4);margin-bottom:6px;">${campeonato}</div>
                <div style="display:flex;align-items:center;justify-content:center;gap:12px;">
                    <div style="text-align:center;min-width:60px;">
                        <div style="width:32px;height:32px;margin:0 auto 4px;display:flex;align-items:center;justify-content:center;">${escudoM}</div>
                        <span style="font-family:'Oswald',sans-serif;font-size:11px;color:rgba(255,255,255,0.8);text-transform:uppercase;">${mandante.sigla || '?'}</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <span style="font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:#fff;">${match.placar_mandante ?? 0}</span>
                        <span style="font-size:14px;color:rgba(255,255,255,0.3);">x</span>
                        <span style="font-family:'Oswald',sans-serif;font-size:28px;font-weight:700;color:#fff;">${match.placar_visitante ?? 0}</span>
                    </div>
                    <div style="text-align:center;min-width:60px;">
                        <div style="width:32px;height:32px;margin:0 auto 4px;display:flex;align-items:center;justify-content:center;">${escudoV}</div>
                        <span style="font-family:'Oswald',sans-serif;font-size:11px;color:rgba(255,255,255,0.8);text-transform:uppercase;">${visitante.sigla || '?'}</span>
                    </div>
                </div>
            </div>
        `;
    }

    // ============================================================
    // PRÓXIMOS JOGOS (Sidebar)
    // ============================================================
    async function loadFixtures() {
        const container = document.getElementById('ldb-sidebar-fixtures');
        if (!container) return;

        // Buscar campeonatos ativos e encontrar jogos do Vitória
        const campeonatos = await apiFetch('campeonatos');
        if (!campeonatos || !Array.isArray(campeonatos)) {
            container.innerHTML = '<p class="ldb-no-data">Jogos indisponíveis</p>';
            return;
        }

        container.innerHTML = `
            <div class="ldb-fixture-item">
                <div class="ldb-fixture-teams-col">
                    <div class="ldb-fixture-team-row">
                        <span style="font-weight:600;color:var(--ldb-red);">VIT</span>
                        <span>Vitória</span>
                    </div>
                    <div class="ldb-fixture-team-row">
                        <span>vs</span>
                        <span>A definir</span>
                    </div>
                </div>
                <div class="ldb-fixture-info-col">
                    <div class="ldb-fixture-date">Em breve</div>
                    <div class="ldb-fixture-comp">-</div>
                </div>
            </div>
        `;
    }

    // ============================================================
    // SHORTCODE: TABELA COMPLETA
    // ============================================================
    async function loadShortcodeTabela() {
        const containers = document.querySelectorAll('.ldb-tabela-shortcode');
        for (const el of containers) {
            const campId = el.dataset.campeonato || 10;
            const limit = parseInt(el.dataset.limit) || 20;
            const highlight = el.dataset.highlight || 'vitoria';
            const container = el.querySelector('.ldb-tabela-container');

            const data = await apiFetch(`tabela/${campId}`);
            if (!data || !Array.isArray(data)) {
                container.innerHTML = '<p class="ldb-no-data" style="padding:20px;text-align:center;">Tabela indisponível</p>';
                continue;
            }

            const rows = data.slice(0, limit);
            let html = `
                <table class="ldb-mini-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding-left:12px;">Time</th>
                            <th>P</th>
                            <th>J</th>
                            <th>V</th>
                            <th>E</th>
                            <th>D</th>
                            <th>GP</th>
                            <th>GC</th>
                            <th>SG</th>
                            <th>%</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            rows.forEach((team, i) => {
                const pos = team.posicao || (i + 1);
                const isVitoria = team.time?.time_id === VITORIA_ID;
                const posClass = pos <= 4 ? 'ldb-pos-lib' : (pos <= 6 ? 'ldb-pos-sula' : (pos >= 17 ? 'ldb-pos-rebaixamento' : ''));
                const rowClass = isVitoria ? 'ldb-row-highlight' : '';

                const escudo = team.time?.escudo 
                    ? `<img src="${team.time.escudo}" alt="${team.time.sigla}" width="20" height="20" loading="lazy" style="border-radius:3px;">`
                    : '';

                const aproveitamento = team.jogos > 0 ? Math.round((team.pontos / (team.jogos * 3)) * 100) : 0;

                html += `
                    <tr class="${rowClass}">
                        <td style="padding-left:12px;">
                            <div class="ldb-team-row">
                                <span class="ldb-team-pos ${posClass}">${pos}</span>
                                ${escudo}
                                <span>${team.time?.nome_popular || team.time?.sigla || '?'}</span>
                            </div>
                        </td>
                        <td style="font-weight:600;">${team.pontos ?? 0}</td>
                        <td>${team.jogos ?? 0}</td>
                        <td>${team.vitorias ?? 0}</td>
                        <td>${team.empates ?? 0}</td>
                        <td>${team.derrotas ?? 0}</td>
                        <td>${team.gols_pro ?? 0}</td>
                        <td>${team.gols_contra ?? 0}</td>
                        <td>${team.saldo_gols > 0 ? '+' : ''}${team.saldo_gols ?? 0}</td>
                        <td>${aproveitamento}%</td>
                    </tr>
                `;
            });

            html += '</tbody></table>';
            container.innerHTML = html;
        }
    }

    // ============================================================
    // SHORTCODE: PLACAR
    // ============================================================
    async function loadShortcodePlacar() {
        const containers = document.querySelectorAll('.ldb-placar-shortcode');
        for (const el of containers) {
            const partidaId = el.dataset.partida;
            const container = el.querySelector('.ldb-placar-container');

            const data = await apiFetch(`partidas/${partidaId}`);
            if (!data) {
                container.innerHTML = '<p class="ldb-no-data" style="padding:20px;text-align:center;">Placar indisponível</p>';
                continue;
            }

            container.innerHTML = renderLiveMatch(data);
        }
    }

    // ============================================================
    // SHORTCODE: AO VIVO
    // ============================================================
    async function loadShortcodeAoVivo() {
        const container = document.getElementById('ldb-sc-ao-vivo');
        if (!container) return;

        const data = await apiFetch('ao-vivo');
        if (!data || !Array.isArray(data) || data.length === 0) {
            container.innerHTML = '<p style="padding:20px;text-align:center;color:var(--ldb-muted);">Nenhum jogo ao vivo no momento</p>';
            return;
        }

        let html = '';
        data.forEach(match => { html += renderLiveMatch(match); });
        container.innerHTML = html;

        setTimeout(loadShortcodeAoVivo, 60000);
    }

    // ============================================================
    // FIND BRASILEIRÃO ID DYNAMICALLY
    // ============================================================
    let _cachedCampId = null;
    async function findBrasileiraoId() {
        if (_cachedCampId) return _cachedCampId;

        const campeonatos = await apiFetch('campeonatos');
        if (!campeonatos || !Array.isArray(campeonatos)) return null;

        // Priority 1: exact slug match
        let found = campeonatos.find(c =>
            (c.slug || '') === 'brasileiro-serie-a' && c.status === 'andamento'
        );

        // Priority 2: slug contains serie-a
        if (!found) found = campeonatos.find(c =>
            (c.slug || '').includes('serie-a') &&
            (c.slug || '').includes('brasileiro') &&
            c.status === 'andamento'
        );

        // Priority 3: name contains "Série A" and "Brasileiro"
        if (!found) found = campeonatos.find(c =>
            (c.nome || '').includes('Série A') &&
            (c.nome || '').toLowerCase().includes('brasileiro') &&
            c.status === 'andamento'
        );

        // Priority 4: any brasileiro in andamento
        if (!found) found = campeonatos.find(c =>
            (c.nome || '').toLowerCase().includes('brasileiro') &&
            c.status === 'andamento'
        );

        // Priority 5: fallback to ID 10
        if (!found) found = campeonatos.find(c => c.campeonato_id === 10);

        _cachedCampId = found ? found.campeonato_id : null;
        return _cachedCampId;
    }

    // ============================================================
    // INLINE TABLE (sidebar - Vitória position ±3)
    // ============================================================
    async function loadInlineTabela() {
        const container = document.getElementById('g1-inline-tabela');
        if (!container) return;

        const campId = await findBrasileiraoId();
        if (!campId) {
            container.innerHTML = '<p style="text-align:center;color:#999;padding:12px;font-size:13px;">Campeonato não encontrado</p>';
            return;
        }

        const data = await apiFetch(`tabela/${campId}`);

        if (!data || !Array.isArray(data) || data.length === 0) {
            container.innerHTML = '<p style="text-align:center;color:#999;padding:12px;">Tabela indisponível</p>';
            return;
        }

        let vitoriaIndex = data.findIndex(t => t.time && t.time.time_id === VITORIA_ID);
        if (vitoriaIndex === -1) {
            // Tentar por nome
            vitoriaIndex = data.findIndex(t => t.time && (t.time.nome_popular || '').toLowerCase().includes('vitória'));
        }
        if (vitoriaIndex === -1) vitoriaIndex = Math.min(7, data.length - 1);

        const start = Math.max(0, vitoriaIndex - 3);
        const end = Math.min(data.length, vitoriaIndex + 4);
        const slice = data.slice(start, end);

        let html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">';
        html += '<thead><tr>';
        html += '<th style="text-align:left;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">#</th>';
        html += '<th style="text-align:left;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">Time</th>';
        html += '<th style="text-align:center;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">P</th>';
        html += '<th style="text-align:center;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">J</th>';
        html += '<th style="text-align:center;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">V</th>';
        html += '<th style="text-align:center;padding:7px 4px;font-size:11px;color:#666;font-weight:500;border-bottom:2px solid #E5E5E5;">SG</th>';
        html += '</tr></thead><tbody>';

        slice.forEach((team, idx) => {
            const pos = team.posicao || (start + idx + 1);
            const nome = team.time ? (team.time.nome_popular || team.time.sigla || '?') : '?';
            const isV = team.time && (team.time.time_id === VITORIA_ID || nome.toLowerCase().includes('vitória'));
            const escudo = team.time && team.time.escudo
                ? '<img src="' + team.time.escudo + '" width="16" height="16" alt="Escudo ' + nome + '" style="border-radius:2px;vertical-align:middle;" loading="lazy">'
                : '';
            const sg = team.saldo_gols || 0;

            html += `<tr style="${isV ? 'background:#FDF0F1;' : ''}">`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;font-weight:${isV ? '700' : '500'};${isV ? 'color:#C41E2A;' : 'color:#666;'}">${pos}</td>`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;${isV ? 'color:#C41E2A;font-weight:700;' : ''}"><span style="display:inline-flex;align-items:center;gap:5px;">${escudo} ${nome}</span></td>`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:center;font-weight:700;${isV ? 'color:#C41E2A;' : ''}">${team.pontos || 0}</td>`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:center;color:#666;">${team.jogos || 0}</td>`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:center;color:#666;">${team.vitorias || 0}</td>`;
            html += `<td style="padding:8px 4px;border-bottom:1px solid #f0f0f0;text-align:center;color:#666;">${sg > 0 ? '+' : ''}${sg}</td>`;
            html += '</tr>';
        });

        html += '</tbody></table>';
        container.innerHTML = html;

        // Store globally so feed.injectMobileTable can use it
        window._ldbTabelaHtml = html;

        // Try to copy to mobile inline table if it exists already
        const mobileContainer = document.getElementById('g1-inline-tabela-mobile');
        if (mobileContainer) mobileContainer.innerHTML = html;
    }

    // ============================================================
    // INLINE AGENDA (jogos do dia / próximos)
    // ============================================================
    async function loadInlineAgenda() {
        const container = document.getElementById('g1-inline-agenda');
        if (!container) return;

        const campId = await findBrasileiraoId();
        if (!campId) {
            container.innerHTML = '<p style="text-align:center;color:#999;padding:12px;font-size:13px;">Sem dados disponíveis</p>';
            return;
        }

        // Buscar rodadas para encontrar a próxima
        const campData = await apiFetch(`campeonatos`);
        const camp = campData && Array.isArray(campData)
            ? campData.find(c => c.campeonato_id === campId)
            : null;

        if (!camp || !camp.rodada_atual) {
            container.innerHTML = '<p style="text-align:center;color:#999;padding:12px;font-size:13px;">Rodada não disponível</p>';
            return;
        }

        const rodadaNum = camp.rodada_atual.rodada || 1;
        // Buscar a rodada atual e a próxima
        const rodadaData = await apiFetch(`rodadas/${campId}/${rodadaNum}`);

        if (!rodadaData || !rodadaData.partidas || !Array.isArray(rodadaData.partidas)) {
            container.innerHTML = '<p style="text-align:center;color:#999;padding:12px;font-size:13px;">Jogos não disponíveis</p>';
            return;
        }

        let html = '';
        const partidas = rodadaData.partidas.slice(0, 6);

        partidas.forEach(p => {
            const mandante = p.time_mandante || {};
            const visitante = p.time_visitante || {};
            const escM = mandante.escudo ? `<img src="${mandante.escudo}" width="20" height="20" style="border-radius:3px;" loading="lazy">` : '';
            const escV = visitante.escudo ? `<img src="${visitante.escudo}" width="20" height="20" style="border-radius:3px;" loading="lazy">` : '';
            const isVitoria = mandante.time_id === VITORIA_ID || visitante.time_id === VITORIA_ID
                || (mandante.nome_popular || '').toLowerCase().includes('vitória')
                || (visitante.nome_popular || '').toLowerCase().includes('vitória');

            const placarOuHora = p.status === 'finalizado'
                ? `<span style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:#1A1A1A;">${p.placar_mandante ?? 0} x ${p.placar_visitante ?? 0}</span>`
                : `<span style="font-family:'Oswald',sans-serif;font-size:16px;font-weight:700;color:#C41E2A;">${p.hora_realizacao || '--:--'}</span>`;

            html += `
                <div class="g1-agenda-item" style="${isVitoria ? 'background:#FDF0F1;margin:0 -14px;padding:12px 14px;border-radius:6px;' : ''}">
                    <div class="g1-agenda-teams">
                        <div class="g1-agenda-team" style="${mandante.time_id === VITORIA_ID || (mandante.nome_popular || '').toLowerCase().includes('vitória') ? 'font-weight:700;color:#C41E2A;' : ''}">
                            <div class="g1-agenda-shield">${escM}</div>
                            <span>${mandante.nome_popular || mandante.sigla || '?'}</span>
                        </div>
                        <div class="g1-agenda-team" style="${visitante.time_id === VITORIA_ID || (visitante.nome_popular || '').toLowerCase().includes('vitória') ? 'font-weight:700;color:#C41E2A;' : ''}">
                            <div class="g1-agenda-shield">${escV}</div>
                            <span>${visitante.nome_popular || visitante.sigla || '?'}</span>
                        </div>
                    </div>
                    <div class="g1-agenda-right">
                        ${placarOuHora}
                        <div class="g1-agenda-date">${p.data_realizacao || ''}</div>
                    </div>
                </div>
            `;
        });

        if (!html) {
            html = '<p style="text-align:center;color:#999;padding:12px;font-size:13px;">Nenhum jogo na rodada</p>';
        }

        container.innerHTML = html;
    }

    // ============================================================
    // FIXTURES LIST (próximos jogos do Vitória - on demand)
    // ============================================================
    async function loadFixturesCarousel() {
        const container = document.getElementById('g1-fixtures-content');
        if (!container) return;

        container.innerHTML = '<div class="ldb-loading"><div class="ldb-spinner"></div><span>Carregando jogos...</span></div>';

        const campId = await findBrasileiraoId();
        if (!campId) {
            container.innerHTML = '<p style="color:#555;padding:16px;font-size:14px;text-align:center;">Dados indisponíveis no momento</p>';
            return;
        }

        const campData = await apiFetch('campeonatos');
        const camp = campData && Array.isArray(campData)
            ? campData.find(c => c.campeonato_id === campId)
            : null;

        if (!camp || !camp.rodada_atual) {
            container.innerHTML = '<p style="color:#555;padding:16px;font-size:14px;text-align:center;">Rodada indisponível</p>';
            return;
        }

        const rodadaAtual = camp.rodada_atual.rodada || 1;
        let vitoriaGames = [];

        for (let r = rodadaAtual; r <= rodadaAtual + 5 && vitoriaGames.length < 5; r++) {
            const rodada = await apiFetch('rodadas/' + campId + '/' + r);
            if (!rodada || !rodada.partidas) continue;

            rodada.partidas.forEach(function(p) {
                const m = p.time_mandante || {};
                const v = p.time_visitante || {};
                const isVit = m.time_id === VITORIA_ID || v.time_id === VITORIA_ID
                    || (m.nome_popular || '').toLowerCase().includes('vitória')
                    || (v.nome_popular || '').toLowerCase().includes('vitória');

                if (isVit && p.status !== 'finalizado') {
                    vitoriaGames.push({
                        comp: (camp.nome_popular || camp.nome || 'Brasileirão') + ' · ' + r + 'ª Rod.',
                        mandante: m,
                        visitante: v,
                        hora: p.hora_realizacao || '--:--',
                        data: p.data_realizacao || ''
                    });
                }
            });
        }

        if (vitoriaGames.length === 0) {
            container.innerHTML = '<p style="color:#555;padding:16px;font-size:14px;text-align:center;">Nenhum jogo agendado</p>';
            return;
        }

        let html = '<div class="g1-fixtures-list">';
        vitoriaGames.slice(0, 5).forEach(function(g) {
            const m = g.mandante;
            const v = g.visitante;
            const mName = m.nome_popular || m.sigla || '?';
            const vName = v.nome_popular || v.sigla || '?';
            const mIsVit = m.time_id === VITORIA_ID || mName.toLowerCase().includes('vitória');
            const vIsVit = v.time_id === VITORIA_ID || vName.toLowerCase().includes('vitória');

            const escM = m.escudo
                ? '<img src="' + m.escudo + '" width="22" height="22" alt="Escudo ' + mName + '" style="border-radius:3px;" loading="lazy">'
                : '<span style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;">' + (m.sigla || '?') + '</span>';
            const escV = v.escudo
                ? '<img src="' + v.escudo + '" width="22" height="22" alt="Escudo ' + vName + '" style="border-radius:3px;" loading="lazy">'
                : '<span style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;">' + (v.sigla || '?') + '</span>';

            html += '<div class="g1-fixture-item">';
            html += '<div class="g1-fixture-item-teams">';
            html += '<div class="g1-fixture-item-comp">' + g.comp + '</div>';
            html += '<div class="g1-fixture-item-team" style="' + (mIsVit ? 'font-weight:700;color:#C41E2A;' : '') + '">' + escM + ' ' + mName + '</div>';
            html += '<div class="g1-fixture-item-team" style="' + (vIsVit ? 'font-weight:700;color:#C41E2A;' : '') + '">' + escV + ' ' + vName + '</div>';
            html += '</div>';
            html += '<div class="g1-fixture-item-right">';
            html += '<div class="g1-fixture-item-time">' + g.hora + '</div>';
            html += '<div class="g1-fixture-item-date">' + g.data + '</div>';
            html += '</div>';
            html += '</div>';
        });
        html += '</div>';

        container.innerHTML = html;
    }

    // ============================================================
    // INIT
    // ============================================================
    function init() {
        loadTabela();
        loadAoVivo();
        loadInlineTabela();
        loadShortcodeTabela();
        loadShortcodePlacar();
        loadShortcodeAoVivo();
    }

    // Fixtures loaded on-demand when user clicks CTA
    window.loadFixturesOnDemand = function() {
        loadFixturesCarousel();
    };

    document.addEventListener('ldb:loadFixtures', function() {
        loadFixturesCarousel();
    });

    // Executar quando DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
