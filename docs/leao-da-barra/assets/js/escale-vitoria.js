/**
 * Escale o Vitória - Interactive Lineup Builder
 * Vanilla JS, zero dependencies
 * 
 * @package LeaoDaBarra
 */

(function() {
    'use strict';

    const root = document.getElementById('escale-vitoria-app');
    if (!root) return;

    const FORMATIONS = {
        "4-3-3": [
            { x: 50, y: 90, role: "GOL", label: "Goleiro" },
            { x: 15, y: 72, role: "LE", label: "Lateral esq." }, { x: 37, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 63, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 85, y: 72, role: "LD", label: "Lateral dir." },
            { x: 25, y: 52, role: "VOL", label: "Volante" }, { x: 50, y: 46, role: "MEI", label: "Meia" }, { x: 75, y: 52, role: "VOL", label: "Volante" },
            { x: 18, y: 28, role: "PE", label: "Ponta esq." }, { x: 50, y: 20, role: "ATA", label: "Centroavante" }, { x: 82, y: 28, role: "PD", label: "Ponta dir." },
        ],
        "4-4-2": [
            { x: 50, y: 90, role: "GOL", label: "Goleiro" },
            { x: 15, y: 72, role: "LE", label: "Lateral esq." }, { x: 37, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 63, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 85, y: 72, role: "LD", label: "Lateral dir." },
            { x: 15, y: 48, role: "ME", label: "Meia esq." }, { x: 38, y: 50, role: "VOL", label: "Volante" }, { x: 62, y: 50, role: "VOL", label: "Volante" }, { x: 85, y: 48, role: "MD", label: "Meia dir." },
            { x: 35, y: 24, role: "ATA", label: "Atacante" }, { x: 65, y: 24, role: "ATA", label: "Atacante" },
        ],
        "3-5-2": [
            { x: 50, y: 90, role: "GOL", label: "Goleiro" },
            { x: 25, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 50, y: 78, role: "ZAG", label: "Zagueiro" }, { x: 75, y: 75, role: "ZAG", label: "Zagueiro" },
            { x: 10, y: 52, role: "ALE", label: "Ala esq." }, { x: 32, y: 55, role: "VOL", label: "Volante" }, { x: 50, y: 46, role: "MEI", label: "Meia" }, { x: 68, y: 55, role: "VOL", label: "Volante" }, { x: 90, y: 52, role: "ALD", label: "Ala dir." },
            { x: 35, y: 24, role: "ATA", label: "Atacante" }, { x: 65, y: 24, role: "ATA", label: "Atacante" },
        ],
        "4-2-3-1": [
            { x: 50, y: 90, role: "GOL", label: "Goleiro" },
            { x: 15, y: 72, role: "LE", label: "Lateral esq." }, { x: 37, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 63, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 85, y: 72, role: "LD", label: "Lateral dir." },
            { x: 35, y: 55, role: "VOL", label: "Volante" }, { x: 65, y: 55, role: "VOL", label: "Volante" },
            { x: 18, y: 37, role: "ME", label: "Meia esq." }, { x: 50, y: 33, role: "MEI", label: "Meia" }, { x: 82, y: 37, role: "MD", label: "Meia dir." },
            { x: 50, y: 18, role: "ATA", label: "Centroavante" },
        ],
        "4-1-4-1": [
            { x: 50, y: 90, role: "GOL", label: "Goleiro" },
            { x: 15, y: 72, role: "LE", label: "Lateral esq." }, { x: 37, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 63, y: 75, role: "ZAG", label: "Zagueiro" }, { x: 85, y: 72, role: "LD", label: "Lateral dir." },
            { x: 50, y: 57, role: "VOL", label: "Volante" },
            { x: 15, y: 40, role: "ME", label: "Meia esq." }, { x: 38, y: 38, role: "MEI", label: "Meia" }, { x: 62, y: 38, role: "MEI", label: "Meia" }, { x: 85, y: 40, role: "MD", label: "Meia dir." },
            { x: 50, y: 18, role: "ATA", label: "Centroavante" },
        ],
    };

    const PLAYERS = [
        { id: 1, name: "Lucas Arcanjo", num: 1, pos: "GOL" },
        { id: 2, name: "Gabriel", num: 22, pos: "GOL" },
        { id: 3, name: "Fintelman", num: 35, pos: "GOL" },
        { id: 4, name: "Yuri Sena", num: 71, pos: "GOL" },
        { id: 5, name: "Claudinho", num: 2, pos: "LD" },
        { id: 6, name: "Nathan Mendes", num: 45, pos: "LD" },
        { id: 7, name: "Matheusinho", num: 98, pos: "LD" },
        { id: 8, name: "Camutanga", num: 4, pos: "ZAG" },
        { id: 9, name: "Riccieli", num: 5, pos: "ZAG" },
        { id: 10, name: "Neris", num: 77, pos: "ZAG" },
        { id: 11, name: "Edu", num: 43, pos: "ZAG" },
        { id: 12, name: "Ramon", num: 13, pos: "LE" },
        { id: 13, name: "Luan Candido", num: 36, pos: "LE" },
        { id: 14, name: "Jamerson", num: 83, pos: "LE" },
        { id: 15, name: "Ronald", num: 8, pos: "VOL" },
        { id: 16, name: "Ruben Ismael", num: 16, pos: "VOL" },
        { id: 17, name: "Gabriel Baralhas", num: 44, pos: "VOL" },
        { id: 18, name: "Caique Goncalves", num: 95, pos: "VOL" },
        { id: 19, name: "Dudu", num: 21, pos: "VOL" },
        { id: 20, name: "Matheuzinho", num: 10, pos: "MEI" },
        { id: 21, name: "Emmanuel Martinez", num: 32, pos: "MEI" },
        { id: 22, name: "Lucas Silva", num: 20, pos: "MEI" },
        { id: 23, name: "Aitor Cantalapiedra", num: 17, pos: "MEI" },
        { id: 24, name: "Carlos de Menezes", num: 25, pos: "MEI" },
        { id: 25, name: "Pablo", num: 62, pos: "MEI" },
        { id: 26, name: "Marinho", num: 7, pos: "PE" },
        { id: 27, name: "Kike Saverio", num: 19, pos: "PD" },
        { id: 28, name: "Osvaldo", num: 11, pos: "ATA" },
        { id: 29, name: "Pedro Henrique", num: 9, pos: "ATA" },
        { id: 30, name: "Erick", num: 33, pos: "ATA" },
        { id: 31, name: "Renato Kayzer", num: 79, pos: "ATA" },
        { id: 32, name: "Diego Tarzia", num: 12, pos: "ATA" },
        { id: 33, name: "Fabri", num: 23, pos: "ATA" },
        { id: 34, name: "Renzo Lopez", num: 31, pos: "ATA" },
    ];

    const ROLE_MAP = {
        GOL: ["GOL"], ZAG: ["ZAG"], LD: ["LD"], LE: ["LE"],
        ALE: ["LE", "ME"], ALD: ["LD", "MD"],
        VOL: ["VOL"], MEI: ["MEI", "ME", "MD"],
        ME: ["MEI", "ME", "PE"], MD: ["MEI", "MD", "PD"],
        PE: ["PE", "ME", "ATA"], PD: ["PD", "MD", "ATA"],
        ATA: ["ATA", "PE", "PD"],
    };

    const COL = {
        GOL: "#D4A843",
        LD: "#1B6BA5", LE: "#1B6BA5", ZAG: "#1B6BA5", ALE: "#1B6BA5", ALD: "#1B6BA5",
        VOL: "#0F6E56", MEI: "#0F6E56", ME: "#0F6E56", MD: "#0F6E56",
        PE: "#C41E2A", PD: "#C41E2A", ATA: "#C41E2A",
    };

    const FIELD_SVG = '<svg viewBox="0 0 680 1000" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;opacity:0.45"><rect x="30" y="20" width="620" height="960" fill="none" stroke="#fff" stroke-width="2.5"/><line x1="30" y1="500" x2="650" y2="500" stroke="#fff" stroke-width="2"/><circle cx="340" cy="500" r="85" fill="none" stroke="#fff" stroke-width="2"/><circle cx="340" cy="500" r="3.5" fill="#fff"/><rect x="175" y="20" width="330" height="165" fill="none" stroke="#fff" stroke-width="2"/><rect x="235" y="20" width="210" height="60" fill="none" stroke="#fff" stroke-width="2"/><rect x="175" y="815" width="330" height="165" fill="none" stroke="#fff" stroke-width="2"/><rect x="235" y="920" width="210" height="60" fill="none" stroke="#fff" stroke-width="2"/><path d="M 260 185 A 85 85 0 0 0 420 185" fill="none" stroke="#fff" stroke-width="2"/><path d="M 260 815 A 85 85 0 0 1 420 815" fill="none" stroke="#fff" stroke-width="2"/></svg>';

    let state = {
        formation: "4-3-3",
        slots: Array(11).fill(null),
        modalIndex: null,
    };

    function getPositions() { return FORMATIONS[state.formation]; }
    function getUsedIds() { return state.slots.filter(Boolean).map(p => p.id); }
    function getShortName(name) { const parts = name.split(" "); return parts.length > 1 ? parts[parts.length - 1] : name; }

    function render() {
        const pos = getPositions();
        const usedIds = getUsedIds();
        const filled = state.slots.filter(Boolean).length;

        let html = '';

        // Header
        html += '<div style="text-align:center;padding:20px 16px 6px">';
        html += '<h1 style="font-family:Oswald,sans-serif;font-size:26px;font-weight:700;color:#1A1A1A;text-transform:uppercase;margin:0">Escale o <span style="color:#C41E2A">Vitoria</span></h1>';
        html += '<p style="font-family:Source Sans 3,sans-serif;font-size:13px;color:#999;margin:4px 0 0">Toque numa posicao para escalar</p>';
        html += '</div>';

        // Formation buttons
        html += '<div style="display:flex;gap:4px;justify-content:center;padding:8px 16px 10px;flex-wrap:wrap">';
        Object.keys(FORMATIONS).forEach(f => {
            const active = f === state.formation;
            html += `<button data-formation="${f}" style="font-family:Oswald,sans-serif;font-size:12px;font-weight:${active?700:500};padding:5px 12px;border:${active?'2px solid #C41E2A':'1px solid #ddd'};background:${active?'#C41E2A':'#fff'};color:${active?'#fff':'#888'};border-radius:20px;cursor:pointer">${f}</button>`;
        });
        html += '</div>';

        // Field
        html += '<div style="position:relative;aspect-ratio:68/100;margin:0 16px;background:#2d8c3c;border-radius:10px;overflow:hidden;border:3px solid #1a6b2a">';
        html += FIELD_SVG;

        pos.forEach((p, i) => {
            const player = state.slots[i];
            const isEmpty = !player;
            const col = player ? (COL[player.pos] || '#C41E2A') : 'rgba(0,0,0,0.25)';
            const border = player ? '2.5px solid #fff' : '2.5px dashed rgba(255,255,255,0.6)';
            const shadow = player ? '0 2px 8px rgba(0,0,0,0.3)' : 'none';
            const label = player ? getShortName(player.name) : p.label;
            const inner = player ? player.num : p.role;
            const labelSize = player ? '11px' : '9px';
            const labelOpacity = player ? '1' : '0.6';

            html += `<div data-slot="${i}" style="position:absolute;left:${p.x}%;top:${p.y}%;transform:translate(-50%,-50%);cursor:pointer;text-align:center;z-index:10;-webkit-tap-highlight-color:transparent">`;
            html += `<div style="width:44px;height:44px;border-radius:50%;margin:0 auto;background:${col};border:${border};display:flex;align-items:center;justify-content:center;color:#fff;font-size:${player?16:10}px;font-weight:700;font-family:Oswald,sans-serif;box-shadow:${shadow}">${inner}</div>`;
            html += `<div style="font-size:${labelSize};color:#fff;font-weight:600;text-shadow:0 1px 4px rgba(0,0,0,0.9);margin-top:3px;white-space:nowrap;font-family:Oswald,sans-serif;text-transform:uppercase;letter-spacing:0.3px;opacity:${labelOpacity}">${label}</div>`;
            html += '</div>';
        });

        // Counter
        html += `<div style="position:absolute;bottom:8px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.65);color:#fff;padding:4px 16px;border-radius:14px;font-size:12px;font-weight:600;font-family:Oswald,sans-serif">${filled}/11</div>`;
        html += '</div>';

        // Buttons
        html += '<div style="display:flex;gap:8px;justify-content:center;padding:14px 16px 20px">';
        html += '<button id="ev-clear" style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;padding:9px 22px;border:1px solid #ddd;background:#fff;color:#888;border-radius:6px;cursor:pointer;text-transform:uppercase">Limpar</button>';
        html += '<button id="ev-share" style="font-family:Oswald,sans-serif;font-size:13px;font-weight:600;padding:9px 22px;border:none;background:#C41E2A;color:#fff;border-radius:6px;cursor:pointer;text-transform:uppercase">Compartilhar</button>';
        html += '</div>';

        // Modal
        if (state.modalIndex !== null) {
            const mi = state.modalIndex;
            const role = pos[mi].role;
            const recommended = ROLE_MAP[role] || [];
            const avail = PLAYERS.filter(p => !usedIds.includes(p.id));
            const rec = avail.filter(p => recommended.includes(p.pos));
            const oth = avail.filter(p => !recommended.includes(p.pos));

            html += '<div id="ev-modal-overlay" style="position:absolute;inset:0;z-index:100;background:rgba(0,0,0,0.5);display:flex;align-items:flex-end;justify-content:center;min-height:100%">';
            html += '<div style="background:#fff;border-radius:16px 16px 0 0;width:100%;max-height:70%;display:flex;flex-direction:column;overflow:hidden">';

            // Modal header
            html += '<div style="padding:14px 16px 10px;border-bottom:2px solid #C41E2A;display:flex;align-items:center;justify-content:space-between">';
            html += `<div><div style="font-family:Oswald,sans-serif;font-size:16px;font-weight:700;color:#1A1A1A;text-transform:uppercase">${pos[mi].label}</div>`;
            html += `<div style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">Posicao ${mi + 1} - ${state.formation}</div></div>`;
            html += '<button id="ev-modal-close" style="background:none;border:none;font-size:22px;color:#999;cursor:pointer;padding:4px 8px;line-height:1">x</button>';
            html += '</div>';

            // Modal body
            html += '<div style="overflow-y:auto;flex:1">';

            if (rec.length > 0) {
                html += '<div style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;color:#0F6E56;text-transform:uppercase;letter-spacing:1.2px;padding:10px 12px 4px">Para esta posicao</div>';
                rec.forEach(p => { html += playerRow(p, true); });
            }

            if (oth.length > 0) {
                html += `<div style="font-family:Oswald,sans-serif;font-size:10px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:1.2px;padding:12px 12px 4px;${rec.length > 0 ? 'border-top:1px solid #eee' : ''}">Improvisar com</div>`;
                oth.forEach(p => { html += playerRow(p, false); });
            }

            html += '</div></div></div>';
        }

        root.style.position = 'relative';
        root.innerHTML = html;
        bindEvents();
    }

    function playerRow(player, isRec) {
        const col = COL[player.pos] || '#999';
        return `<div data-pick="${player.id}" style="display:flex;align-items:center;gap:10px;padding:10px 12px;cursor:pointer;border-bottom:1px solid #f0f0f0;background:${isRec ? '#FAFAFA' : '#fff'}" onmouseover="this.style.background='#FDF0F1'" onmouseout="this.style.background='${isRec ? '#FAFAFA' : '#fff'}'">` +
            `<div style="width:34px;height:34px;border-radius:50%;background:${col};display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:700;font-family:Oswald,sans-serif;flex-shrink:0">${player.num}</div>` +
            `<div style="flex:1"><div style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;color:#1A1A1A">${player.name}</div>` +
            `<div style="font-family:Source Sans 3,sans-serif;font-size:11px;color:#999">${player.pos}</div></div>` +
            (isRec ? '<div style="font-family:Oswald,sans-serif;font-size:9px;font-weight:600;color:#0F6E56;background:#E1F5EE;padding:2px 6px;border-radius:3px;text-transform:uppercase;letter-spacing:0.5px">Ideal</div>' : '') +
            '</div>';
    }

    function bindEvents() {
        // Formation buttons
        root.querySelectorAll('[data-formation]').forEach(btn => {
            btn.addEventListener('click', function() {
                state.formation = this.dataset.formation;
                state.slots = Array(11).fill(null);
                state.modalIndex = null;
                render();
            });
        });

        // Slot clicks
        root.querySelectorAll('[data-slot]').forEach(el => {
            el.addEventListener('click', function() {
                const i = parseInt(this.dataset.slot);
                if (state.slots[i]) {
                    state.slots[i] = null;
                    render();
                } else {
                    state.modalIndex = i;
                    render();
                }
            });
        });

        // Clear
        const clearBtn = document.getElementById('ev-clear');
        if (clearBtn) clearBtn.addEventListener('click', function() {
            state.slots = Array(11).fill(null);
            state.modalIndex = null;
            render();
        });

        // Share
        const shareBtn = document.getElementById('ev-share');
        if (shareBtn) shareBtn.addEventListener('click', function() {
            const pos = getPositions();
            const lines = state.slots.map((s, i) => {
                return s ? `${pos[i].role}: #${s.num} ${s.name}` : `${pos[i].role}: -`;
            });
            const txt = `Minha escalacao do Vitoria (${state.formation}):\n\n${lines.join("\n")}\n\nvia leaodabarra.com.br`;
            if (navigator.share) {
                navigator.share({ title: "Escale o Vitoria", text: txt });
            } else if (navigator.clipboard) {
                navigator.clipboard.writeText(txt);
                alert("Escalacao copiada!");
            }
        });

        // Modal overlay close
        const overlay = document.getElementById('ev-modal-overlay');
        if (overlay) overlay.addEventListener('click', function(e) {
            if (e.target === this) { state.modalIndex = null; render(); }
        });

        // Modal close button
        const closeBtn = document.getElementById('ev-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', function() {
            state.modalIndex = null;
            render();
        });

        // Player pick
        root.querySelectorAll('[data-pick]').forEach(el => {
            el.addEventListener('click', function() {
                const playerId = parseInt(this.dataset.pick);
                const player = PLAYERS.find(p => p.id === playerId);
                if (!player || state.modalIndex === null) return;

                const dup = state.slots.findIndex(s => s && s.id === player.id);
                if (dup !== -1) state.slots[dup] = null;

                state.slots[state.modalIndex] = player;
                state.modalIndex = null;
                render();
            });
        });
    }

    // Initial render
    render();
})();
