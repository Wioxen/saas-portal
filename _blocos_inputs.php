<?php
/**
 * Render dos 8 blocos de prompt (form universal).
 * Dados vêm de _blocos_data.php.
 * Campos POST: bloco1..bloco8.
 */
require __DIR__ . '/_blocos_data.php';
?>
<style>
.blocos-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}
.blocos-grid .bloco-header{display:flex;justify-content:space-between;align-items:center}
.blocos-grid .bloco-header label{margin:8px 0 4px;font-size:12px;color:#bbb;font-weight:600;display:block}
.blocos-grid .bloco-header small{color:#555;font-size:10px}
.blocos-grid textarea{width:100%;padding:10px;background:#0f1115;border:1px solid #2a2e38;border-radius:6px;color:#ddd;font-size:12px;font-family:inherit;min-height:120px;resize:vertical;line-height:1.45}
.blocos-grid textarea:focus{outline:none;border-color:#6366f1}
@media(max-width:768px){.blocos-grid{grid-template-columns:1fr}}
</style>
<div class="box">
  <h2>🧠 Prompt da IA — 8 blocos universais <span style="font-weight:normal;color:#555;font-size:12px">(edite se quiser)</span></h2>
  <p style="color:#555;font-size:12px;margin-bottom:8px">Pré-preenchido com instruções universais para qualquer nicho (eletrônico, perfume, roupa, suplemento etc.). Apague ou ajuste à vontade. Blocos vazios são ignorados.</p>
  <div class="blocos-grid">
    <?php for ($i = 1; $i <= 8; $i++): $bl = $blocoLabels[$i]; ?>
      <div>
        <div class="bloco-header">
          <label>Bloco <?= $i ?> — <?= $bl[0] ?></label>
          <small><?= $bl[1] ?></small>
        </div>
        <textarea name="bloco<?= $i ?>"><?= htmlspecialchars($_POST["bloco{$i}"] ?? $blocoDefaults[$i]) ?></textarea>
      </div>
    <?php endfor; ?>
  </div>
</div>
