<?php
/**
 * CSS pré-construído para landing pages de alta conversão.
 * O Claude gera HTML usando essas classes — nunca escreve CSS.
 * Isso economiza ~4000 tokens por geração.
 */
class LandingCSS
{
    public static function get(): string
    {
        return <<<'CSS'
<style>
/* === LANDING PAGE — HIGH CONVERSION REVIEW === */
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1a1a1a;line-height:1.6;background:#fff}
.lp{max-width:800px;margin:0 auto;padding:0 16px}

/* Above the fold */
.lp-hero{text-align:center;padding:32px 0 24px;border-bottom:2px solid #f0f0f0}
.lp-hero h2{font-size:28px;line-height:1.25;color:#111;margin-bottom:12px}
.lp-hero .lp-sub{font-size:17px;color:#555;margin-bottom:16px}
.lp-eeat{display:flex;justify-content:center;gap:16px;flex-wrap:wrap;margin:16px 0}
.lp-eeat span{display:inline-flex;align-items:center;gap:4px;background:#f0f7ff;color:#1a56db;font-size:13px;font-weight:600;padding:6px 14px;border-radius:20px}
.lp-atualizado{font-size:12px;color:#888;margin-top:8px}

/* Badges */
.badge-best{display:inline-block;background:#059669;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:4px;text-transform:uppercase;letter-spacing:.5px}
.badge-value{display:inline-block;background:#d97706;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:4px;text-transform:uppercase;letter-spacing:.5px}
.badge-pick{display:inline-block;background:#6366f1;color:#fff;font-size:11px;font-weight:700;padding:4px 12px;border-radius:4px;text-transform:uppercase;letter-spacing:.5px}

/* Quick picks — above the fold cards */
.lp-picks{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin:20px 0 28px}
.lp-pick{border:2px solid #e5e7eb;border-radius:10px;padding:16px;text-align:center;transition:border-color .2s}
.lp-pick:first-child{border-color:#059669}
.lp-pick img{width:120px;height:120px;object-fit:contain;margin-bottom:8px}
.lp-pick h3{font-size:15px;margin-bottom:4px}
.lp-pick .lp-pick-price{font-size:18px;font-weight:700;color:#111}
.lp-pick .lp-pick-label{margin-bottom:8px}

/* Tabela comparativa */
.lp-table-wrap{overflow-x:auto;margin:24px 0}
.lp-table{width:100%;border-collapse:collapse;font-size:14px}
.lp-table th{background:#f8f9fa;padding:12px 14px;text-align:left;font-weight:700;border-bottom:2px solid #e5e7eb;white-space:nowrap}
.lp-table td{padding:12px 14px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.lp-table tr:hover{background:#f8f9fa}
.lp-table tr.lp-table-best{background:#f0fdf4}
.lp-table .lp-nota{display:inline-block;background:#059669;color:#fff;font-weight:700;padding:2px 10px;border-radius:12px;font-size:13px}
.lp-table .lp-nota-mid{background:#d97706}
.lp-table .lp-nota-low{background:#dc2626}
.lp-table a{color:#1a56db;text-decoration:none;font-weight:600;white-space:nowrap}
.lp-table a:hover{text-decoration:underline}

/* Seções */
.lp-section{margin:36px 0;padding-top:24px;border-top:1px solid #f0f0f0}
.lp-section h2{font-size:24px;color:#111;margin-bottom:16px}
.lp-section h3{font-size:18px;color:#111;margin-bottom:8px}
.lp-section p{margin-bottom:12px;color:#333}

/* Mini review card */
.lp-review{border:1px solid #e5e7eb;border-radius:12px;padding:24px;margin:20px 0;position:relative;overflow:hidden}
.lp-review.lp-review-best{border-color:#059669;border-width:2px}
.lp-review.lp-review-best::before{content:"MELHOR ESCOLHA";position:absolute;top:0;right:0;background:#059669;color:#fff;font-size:10px;font-weight:700;padding:4px 14px;border-radius:0 10px 0 8px;letter-spacing:.5px}
.lp-review-header{display:flex;gap:20px;align-items:flex-start;margin-bottom:16px}
.lp-review-header img{width:140px;height:140px;object-fit:contain;background:#f8f9fa;border-radius:8px;flex-shrink:0}
.lp-review-header-info h3{font-size:20px;margin-bottom:6px}
.lp-review-header-info .lp-review-for{font-size:14px;color:#666;margin-bottom:8px}
.lp-review-header-info .lp-review-price{font-size:22px;font-weight:700;color:#111}

/* Pros/Cons */
.lp-proscons{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:16px 0}
.lp-pros h4,.lp-cons h4{font-size:14px;text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.lp-pros h4{color:#059669}
.lp-cons h4{color:#dc2626}
.lp-pros ul,.lp-cons ul{list-style:none;padding:0}
.lp-pros li::before{content:"✓ ";color:#059669;font-weight:700}
.lp-cons li::before{content:"✗ ";color:#dc2626;font-weight:700}
.lp-pros li,.lp-cons li{font-size:14px;padding:4px 0;color:#444}

/* Por que recomendamos */
.lp-why{background:#f8f9fa;padding:14px 18px;border-radius:8px;margin:14px 0;border-left:4px solid #1a56db;font-size:14px;color:#333}

/* CTA button */
.lp-cta{display:block;background:#059669;color:#fff;text-decoration:none;text-align:center;font-size:16px;font-weight:700;padding:16px 24px;border-radius:10px;margin:16px 0;transition:all .2s}
.lp-cta:hover{background:#047857;transform:translateY(-1px);box-shadow:0 4px 12px rgba(5,150,105,.3)}
.lp-cta-secondary{background:#1a56db}
.lp-cta-secondary:hover{background:#1e40af;box-shadow:0 4px 12px rgba(26,86,219,.3)}
.lp-cta small{display:block;font-weight:400;font-size:12px;color:rgba(255,255,255,.8);margin-top:4px}

/* Gatilhos */
.lp-trigger{display:flex;align-items:center;gap:8px;font-size:13px;color:#666;margin:8px 0}
.lp-trigger-urgency{color:#d97706;font-weight:600}
.lp-trigger-safety{color:#059669}

/* Specs mini table */
.lp-specs{display:grid;grid-template-columns:1fr 1fr;gap:0;font-size:13px;margin:12px 0;border:1px solid #f0f0f0;border-radius:6px;overflow:hidden}
.lp-specs dt{background:#f8f9fa;padding:6px 12px;font-weight:600;border-bottom:1px solid #f0f0f0}
.lp-specs dd{padding:6px 12px;border-bottom:1px solid #f0f0f0;color:#444}

/* FAQ */
.lp-faq{margin:32px 0}
.lp-faq h2{margin-bottom:16px}
.lp-faq details{background:#f8f9fa;border:1px solid #e5e7eb;padding:14px 18px;border-radius:8px;margin-bottom:8px}
.lp-faq summary{font-weight:700;cursor:pointer;color:#111;font-size:15px}
.lp-faq summary:hover{color:#1a56db}
.lp-faq p{margin-top:10px;font-size:14px;color:#444}

/* Links educação */
.lp-links{background:#f0f7ff;padding:20px 24px;border-radius:10px;margin:24px 0}
.lp-links h3{font-size:16px;color:#1a56db;margin-bottom:10px}
.lp-links a{display:block;color:#1a56db;font-weight:600;padding:6px 0;font-size:14px;text-decoration:none}
.lp-links a:hover{text-decoration:underline}

/* CTA final */
.lp-final{text-align:center;background:#111;color:#fff;padding:32px;border-radius:12px;margin:32px 0}
.lp-final h2{color:#fff;margin-bottom:12px}
.lp-final p{color:#aaa;margin-bottom:16px}
.lp-final .lp-cta{display:inline-block;width:auto;padding:16px 40px}

/* Rodapé */
.lp-footer{text-align:center;padding:20px 0;font-size:12px;color:#aaa;border-top:1px solid #f0f0f0;margin-top:32px}

/* Mobile */
@media(max-width:640px){
  .lp-hero h2{font-size:22px}
  .lp-eeat{flex-direction:column;align-items:center}
  .lp-picks{grid-template-columns:1fr}
  .lp-proscons{grid-template-columns:1fr}
  .lp-review-header{flex-direction:column;align-items:center;text-align:center}
  .lp-review-header img{width:100%;max-width:200px;height:auto}
  .lp-specs{grid-template-columns:1fr 1fr}
}
</style>
CSS;
    }

    /** Lista de classes disponíveis pra incluir no prompt do Claude. */
    public static function classReference(): string
    {
        return <<<'TXT'
CLASSES CSS DISPONÍVEIS (use exatamente esses nomes — o CSS já existe, NÃO gere CSS):

LAYOUT:
- .lp = container principal (max-width 800px)

HERO (above the fold):
- .lp-hero = bloco hero, centralizado
- .lp-hero h2 = headline
- .lp-sub = subtítulo
- .lp-eeat = flex container pra badges de prova social (cada item é <span>)
- .lp-atualizado = data de atualização

BADGES:
- .badge-best = verde "MELHOR GERAL"
- .badge-value = laranja "MELHOR CUSTO-BENEFÍCIO"
- .badge-pick = roxo "ESCOLHA DO EDITOR"

QUICK PICKS (cards above the fold):
- .lp-picks = grid de cards rápidos
- .lp-pick = cada card (imagem + nome + preço)
- .lp-pick-label = badge dentro do card
- .lp-pick-price = preço destaque

TABELA COMPARATIVA:
- .lp-table-wrap = wrapper com overflow
- .lp-table = tabela (th/td)
- .lp-table-best = classe na <tr> do melhor produto (fundo verde)
- .lp-nota = nota alta (verde), .lp-nota-mid = média (laranja), .lp-nota-low = baixa (vermelha)

SEÇÕES:
- .lp-section = bloco de seção com border-top
- .lp-section h2, h3 = títulos de seção

MINI REVIEW:
- .lp-review = card de review individual
- .lp-review-best = destaque (borda verde + badge "MELHOR ESCOLHA")
- .lp-review-header = flex: imagem + info
- .lp-review-header img = foto do produto
- .lp-review-header-info = wrapper de textos
- .lp-review-for = "Pra quem é: ..."
- .lp-review-price = preço
- .lp-proscons = grid 2 colunas
- .lp-pros / .lp-cons = lista com ícone ✓/✗ (use <h4> + <ul><li>)
- .lp-why = bloco "Por que recomendamos" (fundo cinza, borda azul)

BOTÕES CTA:
- .lp-cta = botão verde principal (use <a>)
- .lp-cta-secondary = botão azul secundário
- .lp-cta small = subtext dentro do botão

GATILHOS:
- .lp-trigger = linha de gatilho
- .lp-trigger-urgency = urgência (laranja)
- .lp-trigger-safety = segurança (verde)

SPECS:
- .lp-specs = grid <dl> com <dt> (label) + <dd> (valor)

FAQ:
- .lp-faq = container FAQ
- use <details><summary>pergunta</summary><p>resposta</p></details>

LINKS EDUCAÇÃO:
- .lp-links = bloco azul claro com links
- use <h3> + <a> dentro

CTA FINAL:
- .lp-final = bloco escuro centralizado com CTA final
- use h2 + p + .lp-cta dentro

RODAPÉ:
- .lp-footer = rodapé simples
TXT;
    }
}
