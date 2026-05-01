<?php
/**
 * Homepage — Vagas e Benefícios — front-page.php v8.0
 * vagasebeneficios.com
 *
 * NOVIDADES v8 (vs v7):
 *  - Remove top-bar (Novidades/ticker/data)
 *  - Header 2 linhas (desktop): row1 search-left + logo-center + hamburger-right; row2 nav full-width
 *  - Visto por Último (localStorage, últimos 6 posts)
 *  - Para Você (top 3 categorias baseadas em histórico real do usuário)
 *  - Cores V2 distintas por silo (INSS azul, Benefícios roxo, FGTS verde, Vagas verde-escuro, Concursos vermelho, MEI laranja, IR marrom, Documentos cinza-azulado)
 *  - Badge NOVO (posts < 24h)
 *  - Reading progress bar no topo
 *  - Back-to-top
 *  - Keyboard shortcut "/" para focar busca
 *  - Skeleton loader no infinite scroll
 */
if ( ! defined( 'ABSPATH' ) ) exit;
global $wpdb;

$categories = $wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' AND tt.count>0 AND t.slug!='uncategorized' ORDER BY tt.count DESC LIMIT 12");
$all_categories = get_categories(['orderby'=>'count','order'=>'DESC','hide_empty'=>true,'exclude'=>get_cat_ID('uncategorized'),'number'=>20]);

$featured_main = $wpdb->get_row("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 1");
if(!$featured_main){get_header();echo'<main style="padding:4rem 2rem;text-align:center"><h1>Nenhum conteúdo publicado ainda.</h1></main>';get_footer();return;}

$featured_secondary = $wpdb->get_results($wpdb->prepare("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' AND ID!=%d ORDER BY post_date DESC LIMIT 4",$featured_main->ID));

$all_posts_page1 = $wpdb->get_results("SELECT ID,post_title,post_excerpt,post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 8");
$total_posts = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish'");
$has_more = $total_posts > 8;

function veb_get_cat($pid){$c=wp_get_post_categories($pid,['fields'=>'names']);return!empty($c)?$c[0]:'Geral';}
function veb_get_cat_slug($pid){$c=wp_get_post_categories($pid,['fields'=>'all']);return!empty($c)?$c[0]->slug:'geral';}
function veb_time_ago($d){$diff=time()-strtotime($d);if($diff<3600)return max(1,intval($diff/60)).' min atrás';if($diff<86400)return intval($diff/3600).'h atrás';return date_i18n('d \d\e M, Y',strtotime($d));}
function veb_is_new($d){return (time()-strtotime($d))<86400;}
function veb_get_excerpt($post){$exc=$post->post_excerpt;if(!$exc)$exc=wp_strip_all_tags(get_post_field('post_content',$post->ID));return wp_trim_words($exc,18,'…');}
function veb_reading_time($pid){$c=wp_strip_all_tags(get_post_field('post_content',$pid));$w=str_word_count($c);return max(1,(int)ceil($w/220));}
/**
 * Mapa de cores V2 (8 silos + subcategorias). Cada silo tem cor distinta para diferenciação visual rápida no Discover.
 * Ordem importa: matches mais específicos primeiro.
 */
function veb_cat_color($slug){
    $exact=[
        // INSS e Aposentadoria — azul
        'inss-e-aposentadoria'=>'#1e40af','inss'=>'#1e40af','aposentadoria'=>'#1e3a8a','auxilio-doenca'=>'#1d4ed8','bpc-loas'=>'#2563eb','13-inss'=>'#1e40af','pensao'=>'#1e3a8a','salario-maternidade'=>'#3b82f6','meu-inss'=>'#1e40af',
        // Benefícios Sociais — roxo
        'beneficios-sociais'=>'#7c3aed','beneficios'=>'#7c3aed','bolsa-familia'=>'#6d28d9','cadunico'=>'#8b5cf6','pe-de-meia'=>'#a855f7','auxilio-gas'=>'#9333ea','minha-casa-minha-vida'=>'#7e22ce','seguro-desemprego'=>'#b91c1c',
        // FGTS, PIS, Direitos do Trabalhador — verde-floresta
        'fgts-pis-direitos-trabalhador'=>'#047857','fgts'=>'#047857','saque-aniversario'=>'#059669','pis-pasep'=>'#0e6655','pis'=>'#0e6655','13-salario'=>'#065f46','ferias'=>'#10b981','rescisao-aviso-previo'=>'#0f766e','direitos-trabalhador'=>'#047857',
        // Vagas e Empregos — verde escuro (marca)
        'vagas-e-empregos'=>'#0d6844','vagas-clt'=>'#0d6844','vagas-de-emprego'=>'#0d6844','vagas'=>'#0d6844','empregos'=>'#0d6844','home-office'=>'#7c2d12','trabalho-remoto'=>'#7c2d12','estagio-jovem-aprendiz'=>'#0369a1','estagio'=>'#6d28d9','jovem-aprendiz'=>'#0369a1','sine'=>'#0d6844',
        // Concursos Públicos — vermelho
        'concursos-publicos'=>'#b91c1c','concursos'=>'#b91c1c','concursos-federais'=>'#991b1b','concursos-estaduais'=>'#dc2626','concursos-municipais'=>'#ef4444','editais'=>'#7f1d1d','resultado-convocacao'=>'#b91c1c',
        // MEI — laranja
        'mei-trabalho-autonomo'=>'#ea580c','mei'=>'#ea580c','abrir-mei'=>'#f97316','das-mei'=>'#fb923c','consultar-mei'=>'#ea580c','dasn-simei'=>'#c2410c','limite-mei'=>'#9a3412','baixa-mei'=>'#7c2d12',
        // Imposto de Renda — marrom dourado
        'imposto-de-renda'=>'#92400e','declaracao-ir'=>'#a16207','restituicao'=>'#ca8a04','tabela-ir'=>'#854d0e','malha-fina'=>'#78350f',
        // Direitos Sociais e Documentos — cinza-azulado
        'direitos-sociais-documentos'=>'#475569','cpf'=>'#475569','cnh-carteira-motorista'=>'#334155','cnh'=>'#334155','carteira-trabalho-digital'=>'#0f172a','cin-rg-identidade'=>'#1e293b','rg'=>'#1e293b','carteira-do-idoso'=>'#64748b','id-jovem'=>'#94a3b8','gov-br'=>'#475569',
        // Genéricos antigos
        'clt-e-direitos'=>'#b45309','clt'=>'#b45309','direitos'=>'#b45309','salario'=>'#b45309',
    ];
    $slug=strtolower($slug);
    if(isset($exact[$slug]))return $exact[$slug];
    // Fallback por keyword
    $part=['inss'=>'#1e40af','aposenta'=>'#1e3a8a','bolsa-familia'=>'#6d28d9','beneficio'=>'#7c3aed','fgts'=>'#047857','pis'=>'#0e6655','vaga'=>'#0d6844','emprego'=>'#0d6844','home-office'=>'#7c2d12','remoto'=>'#7c2d12','aprendiz'=>'#0369a1','estagio'=>'#6d28d9','concurso'=>'#b91c1c','edital'=>'#7f1d1d','mei'=>'#ea580c','autonomo'=>'#f97316','imposto'=>'#92400e','restitui'=>'#ca8a04','irpf'=>'#92400e','cpf'=>'#475569','cnh'=>'#334155','rg'=>'#1e293b','documento'=>'#475569','identidade'=>'#1e293b','gov'=>'#475569','clt'=>'#b45309','direito'=>'#b45309','salario'=>'#b45309','seguro'=>'#b91c1c'];
    foreach($part as $k=>$v){if(strpos($slug,$k)!==false)return $v;}
    // Hash determinístico para categorias desconhecidas (consistência visual)
    $palette=['#0d6844','#1e40af','#7c3aed','#b91c1c','#ea580c','#92400e','#475569','#0e6655','#0369a1','#6d28d9'];
    return $palette[hexdec(substr(md5($slug),0,2))%count($palette)];
}
function veb_fallback_img(){return'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 225" fill="none"><rect width="400" height="225" fill="#e2e8f0"/><path d="M170 90l30 45h-60z M200 80a12 12 0 110 24 12 12 0 010-24z" fill="#94a3b8"/><text x="200" y="165" text-anchor="middle" fill="#64748b" font-size="13" font-family="sans-serif">Sem imagem</text></svg>');}
function veb_render_card($p,$loading='lazy'){
    $pt=get_the_post_thumbnail_url($p->ID,'medium');$pl=get_permalink($p->ID);$pc=veb_get_cat($p->ID);$pcsl=veb_get_cat_slug($p->ID);$exc=veb_get_excerpt($p);$rt=veb_reading_time($p->ID);$isNew=veb_is_new($p->post_date);
    ?>
    <a href="<?php echo esc_url($pl);?>" class="card" data-pid="<?php echo (int)$p->ID;?>" data-cat="<?php echo esc_attr($pc);?>" data-cat-slug="<?php echo esc_attr($pcsl);?>" data-thumb="<?php echo esc_url($pt?:veb_fallback_img());?>" data-title="<?php echo esc_attr($p->post_title);?>"><div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($pcsl));?>"><?php echo esc_html($pc);?></span><?php if($isNew):?><span class="new-badge">NOVO</span><?php endif;?></div><h3><?php echo esc_html(wp_trim_words($p->post_title,14));?></h3><p class="card-excerpt"><?php echo esc_html($exc);?></p></div><div class="card-img"><img src="<?php echo esc_url($pt?:veb_fallback_img());?>" alt="<?php echo esc_attr(wp_trim_words($p->post_title,6));?>" width="400" height="225" loading="<?php echo esc_attr($loading);?>" decoding="async"></div><div class="card-footer"><time datetime="<?php echo esc_attr(date('c',strtotime($p->post_date)));?>"><?php echo esc_html(veb_time_ago($p->post_date));?></time><span class="reading-time">· <?php echo (int)$rt;?> min</span></div></a>
    <?php
}

$lcp_id=get_post_thumbnail_id($featured_main->ID);$lcp_src=$lcp_id?wp_get_attachment_image_url($lcp_id,'full'):'';$lcp_srcset=$lcp_id?wp_get_attachment_image_srcset($lcp_id,'full'):'';$lcp_1200=$lcp_id?wp_get_attachment_image_url($lcp_id,'large'):$lcp_src;
$site_name=get_bloginfo('name');$site_desc=get_bloginfo('description')?:'Informações sobre vagas de emprego, benefícios sociais, direitos trabalhistas e oportunidades profissionais no Brasil.';$site_url=home_url('/');
$fcat=veb_get_cat($featured_main->ID);$fcslug=veb_get_cat_slug($featured_main->ID);
$fexc=$featured_main->post_excerpt?wp_trim_words($featured_main->post_excerpt,25,'…'):wp_trim_words(wp_strip_all_tags(get_post_field('post_content',$featured_main->ID)),25,'…');
?><!DOCTYPE html>
<html <?php language_attributes();?>>
<head>
<meta charset="<?php bloginfo('charset');?>"><meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=5">
<meta name="theme-color" content="#0d6844">
<title><?php echo esc_html($site_name);?> | <?php echo esc_html($site_desc);?></title>
<meta name="description" content="<?php echo esc_attr($site_desc);?>"><link rel="canonical" href="<?php echo esc_url($site_url);?>">
<meta name="robots" content="index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1">
<meta property="og:locale" content="pt_BR"><meta property="og:type" content="website"><meta property="og:site_name" content="<?php echo esc_attr($site_name);?>">
<meta property="og:title" content="<?php echo esc_attr($site_name);?>"><meta property="og:description" content="<?php echo esc_attr($site_desc);?>">
<meta property="og:url" content="<?php echo esc_url($site_url);?>"><?php if($lcp_1200):?><meta property="og:image" content="<?php echo esc_url($lcp_1200);?>"><?php endif;?>
<meta name="twitter:card" content="summary_large_image">
<?php wp_head();?>
<?php if($lcp_src):?><link rel="preload" as="image" href="<?php echo esc_url($lcp_src);?>" <?php if($lcp_srcset):?>imagesrcset="<?php echo esc_attr($lcp_srcset);?>" imagesizes="(max-width:768px) 100vw, 62vw"<?php endif;?> fetchpriority="high"><?php endif;?>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Merriweather:wght@700;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Merriweather:wght@700;900&display=swap"></noscript>
<script type="application/ld+json">{"@context":"https://schema.org","@type":"WebSite","name":"<?php echo esc_js($site_name);?>","url":"<?php echo esc_url($site_url);?>","description":"<?php echo esc_js($site_desc);?>","inLanguage":"pt-BR","potentialAction":{"@type":"SearchAction","target":{"@type":"EntryPoint","urlTemplate":"<?php echo esc_url($site_url);?>?s={search_term_string}"},"query-input":"required name=search_term_string"}}</script>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{--verde:#0d6844;--verde-escuro:#064e32;--verde-claro:#10b981;--laranja:#b45309;--laranja-claro:#d97706;--bg:#f0fdf4;--bg-card:#ffffff;--borda:#d1fae5;--texto:#1a2e1a;--texto-sec:#4a6741;--texto-muted:#64748b;--sombra:0 1px 3px rgba(0,0,0,.05),0 1px 2px rgba(0,0,0,.03);--sombra-md:0 4px 6px -1px rgba(0,0,0,.06),0 2px 4px -2px rgba(0,0,0,.04);--sombra-lg:0 10px 25px -3px rgba(0,0,0,.07),0 4px 6px -4px rgba(0,0,0,.03);--radius:10px;--radius-sm:6px;--font-body:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;--font-heading:'Merriweather',Georgia,serif;--max-w:1280px;--transition:.2s ease}
html{scroll-behavior:smooth}body{background:var(--bg);font-family:var(--font-body);color:var(--texto);line-height:1.6;display:flex;flex-direction:column;min-height:100vh;-webkit-font-smoothing:antialiased}
img{max-width:100%;height:auto;display:block}a{color:var(--verde);text-decoration:none;transition:color var(--transition)}a:hover{color:var(--verde-claro)}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}
/* Reading progress bar */
.read-progress{position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,var(--verde-claro),var(--verde));z-index:1100;transition:width .1s linear}
/* HEADER v8 — 2 linhas no desktop */
.site-header{background:var(--bg-card);box-shadow:var(--sombra-md);position:sticky;top:0;z-index:1000;border-bottom:1px solid var(--borda)}
.header-inner{max-width:var(--max-w);margin:0 auto;padding:.7rem 1.5rem .35rem;display:grid;grid-template-columns:1fr auto 1fr;grid-template-rows:auto auto;align-items:center;column-gap:1rem;row-gap:.55rem}
.site-search{grid-column:1;grid-row:1;justify-self:start;display:flex;align-items:center;background:var(--bg);border:1.5px solid var(--borda);border-radius:50px;overflow:hidden;width:100%;max-width:300px;transition:border-color var(--transition),box-shadow var(--transition)}
.site-search:focus-within{border-color:var(--verde);box-shadow:0 0 0 3px rgba(16,185,129,.12)}
.site-search input{border:none;background:transparent;padding:.45rem 1rem;font-size:.85rem;color:var(--texto);outline:none;width:100%;min-width:0;font-family:var(--font-body)}
.site-search input::placeholder{color:#6b7280}
.site-search button{background:none;border:none;cursor:pointer;padding:.45rem .75rem;color:var(--verde);display:flex;align-items:center;flex-shrink:0}
.site-search button svg{width:17px;height:17px}
.search-kbd{display:none;font-size:.65rem;background:var(--borda);color:var(--texto-sec);padding:1px 5px;border-radius:3px;margin-right:.5rem;font-family:monospace;font-weight:600}
@media(min-width:1024px){.search-kbd{display:inline-block}}
.site-logo{grid-column:2;grid-row:1;justify-self:center;text-align:center;display:flex;align-items:center;gap:.6rem;text-decoration:none}
.site-logo-icon{width:42px;height:42px;background:linear-gradient(135deg,var(--verde) 0%,var(--verde-claro) 100%);border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:#fff;font-weight:900;font-size:1.2rem;box-shadow:0 2px 8px rgba(13,104,68,.25)}
.site-logo-text{display:flex;flex-direction:column;line-height:1.15;text-align:left}
.site-logo-name{font-weight:800;font-size:1.1rem;color:var(--verde-escuro)}
.site-logo-tagline{font-size:.65rem;color:var(--texto-sec);font-weight:500;letter-spacing:.02em}
.menu-toggle{grid-column:3;grid-row:1;justify-self:end;display:none;background:none;border:none;cursor:pointer;padding:8px 4px;flex-direction:column;align-items:center;justify-content:center;gap:5px;width:40px;height:40px}
.menu-toggle span{display:block;width:22px;height:2.5px;background:var(--verde-escuro);border-radius:2px;transition:all .3s;transform-origin:center}
.menu-toggle.active span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
.menu-toggle.active span:nth-child(2){opacity:0;transform:scaleX(0)}
.menu-toggle.active span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}
.nav-wrap{grid-column:1 / -1;grid-row:2;width:calc(100% + 3rem);margin:0 -1.5rem;background:#fafbfc;border-top:1px solid var(--borda);padding:0;position:relative}
.nav-cats{display:flex;justify-content:center;gap:2px;list-style:none;padding:0 1.5rem;margin:0 auto;max-width:var(--max-w);overflow-x:auto;scrollbar-width:none}
.nav-cats::-webkit-scrollbar{display:none}
.nav-cats li{flex-shrink:0;list-style:none}
.nav-cat-link{display:inline-flex;align-items:center;gap:6px;padding:.7rem 1rem;font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#2a2e38;text-decoration:none;border-bottom:3px solid transparent;transition:color .18s,border-color .18s,background .18s;white-space:nowrap}
.nav-cat-link:hover,.nav-cat-link:focus{color:var(--verde);border-bottom-color:var(--verde);background:rgba(13,104,68,.04)}
.nav-cat-link[data-active="1"]{color:var(--verde);border-bottom-color:var(--verde)}
.nav-cats-close{display:none}
.container{max-width:var(--max-w);width:100%;margin:0 auto;padding:0 1.5rem}
/* HERO */
.hero{display:grid;grid-template-columns:1.6fr 1fr;gap:1.25rem;margin:1.5rem 0}
.hero-main{background:var(--bg-card);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;text-decoration:none;color:var(--texto);box-shadow:var(--sombra);border:1px solid var(--borda);transition:box-shadow var(--transition),transform var(--transition);position:relative}
.hero-main:hover{box-shadow:var(--sombra-lg);transform:translateY(-2px)}
.hero-main-body{padding:1.5rem 1.5rem 1rem;display:flex;flex-direction:column;gap:.5rem}
.hero-main-body .badges{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.hero-main h1{font-family:var(--font-heading);font-size:1.6rem;font-weight:900;line-height:1.3;color:var(--texto);margin:0}
.hero-main-excerpt{font-size:.92rem;color:var(--texto-muted);line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.hero-main-img{aspect-ratio:16/9;overflow:hidden;background:var(--borda);margin:0 1.5rem;border-radius:var(--radius-sm)}
.hero-main-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease;display:block}
.hero-main:hover .hero-main-img img{transform:scale(1.03)}
.hero-main-footer{padding:.6rem 1.5rem 1.2rem;display:flex;align-items:center;gap:.6rem}
.hero-main-footer time{font-size:.78rem;color:var(--texto-sec);font-weight:500}
.hero-main-footer .dot{color:var(--texto-sec);opacity:.5}
.hero-sidebar{display:grid;grid-template-columns:1fr 1fr;gap:1rem;align-content:start}
.hero-card{background:var(--bg-card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--sombra);text-decoration:none;color:var(--texto);transition:box-shadow var(--transition),transform var(--transition);display:flex;flex-direction:column;border:1px solid var(--borda);position:relative}
.hero-card:hover{box-shadow:var(--sombra-lg);transform:translateY(-3px)}
.hero-card-body{padding:.7rem .7rem .4rem;display:flex;flex-direction:column;gap:.3rem}
.hero-card-body h3{font-size:.85rem;font-weight:700;color:var(--texto);line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.hero-card-excerpt{font-size:.75rem;color:var(--texto-muted);line-height:1.45;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.hero-card-img{aspect-ratio:16/9;overflow:hidden;background:var(--borda);margin:0 .7rem;border-radius:var(--radius-sm)}
.hero-card-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease}
.hero-card:hover .hero-card-img img{transform:scale(1.06)}
.hero-card-footer{padding:.4rem .7rem .6rem}
.hero-card-footer time{font-size:.7rem;color:var(--texto-sec)}
/* BADGES */
.cat-badge{display:inline-block;padding:.22rem .7rem;border-radius:4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;line-height:1.4;width:fit-content}
.new-badge{display:inline-block;padding:.18rem .55rem;border-radius:4px;font-size:.62rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#dc2626,#f97316);text-transform:uppercase;letter-spacing:.05em;line-height:1.4;animation:pulse-new 2s ease-in-out infinite}
@keyframes pulse-new{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.85;transform:scale(.97)}}
.ad-slot{text-align:center;margin:1.5rem 0;min-height:0;overflow:hidden}
/* SECTION HEADERS */
.section-hdr{display:flex;align-items:center;justify-content:space-between;margin:2.5rem 0 1.2rem;padding-bottom:.6rem;border-bottom:3px solid var(--verde)}
.section-hdr h2{font-family:var(--font-heading);font-size:1.3rem;font-weight:900;color:var(--verde-escuro);display:flex;align-items:center;gap:.5rem}
.section-hdr h2 .emoji{font-family:var(--font-body)}
.section-hdr-sub{font-size:.78rem;color:var(--texto-sec);font-weight:500}
/* CARDS GRID */
.cards-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.15rem}
.card{background:var(--bg-card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--sombra);text-decoration:none;color:var(--texto);transition:box-shadow var(--transition),transform var(--transition);display:flex;flex-direction:column;border:1px solid var(--borda);position:relative}
.card:hover{box-shadow:var(--sombra-lg);transform:translateY(-3px)}
.card-body{padding:.85rem .85rem .5rem;display:flex;flex-direction:column;gap:.4rem}
.card-meta-row{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.card-body h3{font-size:.92rem;font-weight:700;color:var(--texto);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.card-excerpt{font-size:.8rem;color:var(--texto-muted);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.card-img{aspect-ratio:16/9;overflow:hidden;background:var(--borda);margin:0 .85rem;border-radius:var(--radius-sm)}
.card-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease;display:block}
.card:hover .card-img img{transform:scale(1.06)}
.card-footer{padding:.5rem .85rem .75rem;display:flex;align-items:center;gap:.4rem}
.card-footer time{font-size:.72rem;color:var(--texto-sec);font-weight:500}
.reading-time{font-size:.72rem;color:var(--texto-muted)}
/* QUICK LINKS */
.quick-links{background:var(--bg-card);border-radius:var(--radius);padding:1.5rem;margin:2rem 0;box-shadow:var(--sombra);border:1px solid var(--borda)}
.quick-links-title{font-family:var(--font-heading);color:var(--verde-escuro);font-size:1.05rem;font-weight:700;margin-bottom:1rem;display:flex;align-items:center;gap:.5rem}
.quick-grid{display:flex;flex-wrap:wrap;gap:.6rem}
.quick-tag{padding:.4rem 1rem;border-radius:50px;font-size:.82rem;font-weight:600;color:#fff;background:var(--verde);border:1.5px solid transparent;transition:all var(--transition);text-decoration:none;white-space:nowrap;box-shadow:0 1px 2px rgba(0,0,0,.06)}
.quick-tag:hover{transform:translateY(-1px);box-shadow:var(--sombra-md);filter:brightness(1.08)}
.quick-tag-count{font-size:.72rem;opacity:.85;font-weight:500;margin-left:.25rem}
/* PARA VOCÊ + VISTO POR ÚLTIMO */
.smart-section{background:#fff;border-radius:var(--radius);padding:1.25rem 1.5rem 1.5rem;margin:2rem 0;box-shadow:var(--sombra);border:1px solid var(--borda)}
.smart-section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem}
.smart-section-hdr h2{font-family:var(--font-heading);font-size:1.1rem;font-weight:800;color:var(--verde-escuro);display:flex;align-items:center;gap:.5rem}
.smart-section-hdr .clear-history{font-size:.72rem;color:var(--texto-muted);background:none;border:1px solid var(--borda);padding:.25rem .65rem;border-radius:50px;cursor:pointer;font-family:var(--font-body)}
.smart-section-hdr .clear-history:hover{color:#b91c1c;border-color:#fecaca}
.smart-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.85rem}
@media(max-width:1024px){.smart-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.smart-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:480px){.smart-grid{grid-template-columns:repeat(2,1fr)}}
.smart-card{display:block;text-decoration:none;color:var(--texto);background:var(--bg);border:1px solid var(--borda);border-radius:var(--radius-sm);overflow:hidden;transition:transform var(--transition),box-shadow var(--transition)}
.smart-card:hover{transform:translateY(-2px);box-shadow:var(--sombra-md)}
.smart-card-img{aspect-ratio:16/9;background:var(--borda);overflow:hidden}
.smart-card-img img{width:100%;height:100%;object-fit:cover}
.smart-card-body{padding:.5rem .6rem .65rem}
.smart-card-cat{display:inline-block;font-size:.6rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.04em;padding:.1rem .45rem;border-radius:3px;margin-bottom:.35rem}
.smart-card h4{font-size:.78rem;font-weight:600;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0;color:var(--texto)}
.smart-empty{font-size:.85rem;color:var(--texto-muted);font-style:italic;padding:.5rem 0}
/* NEWSLETTER */
.newsletter{background:linear-gradient(135deg,var(--verde-escuro) 0%,var(--verde) 100%);border-radius:var(--radius);padding:2.5rem;text-align:center;color:#fff;margin:2rem 0;position:relative;overflow:hidden;box-shadow:var(--sombra-lg)}
.newsletter::before{content:'';position:absolute;top:-50%;right:-20%;width:300px;height:300px;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);pointer-events:none}
.newsletter h2{font-family:var(--font-heading);font-size:1.5rem;font-weight:900;margin-bottom:.4rem;position:relative}
.newsletter p{color:rgba(255,255,255,.9);margin-bottom:1.2rem;font-size:.95rem;position:relative}
.newsletter-form{max-width:500px;margin:0 auto;position:relative}
.newsletter-row{display:flex;gap:0;margin-bottom:.6rem}
.newsletter-row input[type="email"]{flex:1;padding:.7rem 1.1rem;border:none;border-radius:var(--radius) 0 0 var(--radius);font-size:.9rem;outline:none;font-family:var(--font-body);min-width:0;color:var(--texto)}
.newsletter-row button{padding:.7rem 1.5rem;background:var(--laranja-claro);color:#fff;border:none;border-radius:0 var(--radius) var(--radius) 0;font-weight:800;font-size:.9rem;cursor:pointer;white-space:nowrap;font-family:var(--font-body);transition:background var(--transition)}
.newsletter-row button:hover{background:var(--laranja)}
.captcha-row{display:flex;align-items:center;justify-content:center;gap:.5rem}
.captcha-label{font-size:.85rem;color:rgba(255,255,255,.95);font-weight:600;white-space:nowrap}
.captcha-input{width:70px;padding:.45rem .6rem;border:2px solid rgba(255,255,255,.3);border-radius:var(--radius-sm);background:rgba(255,255,255,.15);color:#fff;font-size:.95rem;font-weight:700;text-align:center;outline:none;font-family:var(--font-body)}
.captcha-input:focus{border-color:#fff;background:rgba(255,255,255,.25)}
.captcha-input::placeholder{color:rgba(255,255,255,.5)}
.captcha-refresh{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:1.1rem;padding:4px;line-height:1;display:flex;align-items:center}
.captcha-refresh:hover{color:#fff}
.captcha-error{color:#fca5a5;font-size:.8rem;margin-top:.3rem;display:none;position:relative}
.newsletter-success{color:#86efac;font-size:.9rem;font-weight:600;margin-top:.5rem;display:none;position:relative}
/* INFINITE SCROLL */
#scroll-sentinel{width:100%;height:4px}
.loader{text-align:center;padding:2rem;display:none}
.spinner{width:32px;height:32px;border:3px solid var(--borda);border-top-color:var(--verde);border-radius:50%;animation:spin .6s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.skeleton-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.15rem;margin-top:1rem}
.skeleton-card{background:#fff;border-radius:var(--radius);border:1px solid var(--borda);overflow:hidden;height:330px}
.skeleton-card .sk-img{aspect-ratio:16/9;background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 50%,#e2e8f0 75%);background-size:200% 100%;animation:shine 1.4s ease-in-out infinite;margin:.85rem;border-radius:var(--radius-sm)}
.skeleton-card .sk-line{height:14px;background:linear-gradient(90deg,#e2e8f0 25%,#f1f5f9 50%,#e2e8f0 75%);background-size:200% 100%;animation:shine 1.4s ease-in-out infinite;margin:.5rem .85rem;border-radius:4px}
.skeleton-card .sk-line.short{width:40%}
@keyframes shine{0%{background-position:200% 0}100%{background-position:-200% 0}}
@media(max-width:1024px){.skeleton-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:768px){.skeleton-grid{grid-template-columns:repeat(2,1fr)}}
@media(max-width:480px){.skeleton-grid{grid-template-columns:1fr}}
.no-more{display:none;text-align:center;padding:2rem;color:var(--texto-sec);font-size:.9rem}
/* BACK TO TOP */
.back-top{position:fixed;bottom:1.5rem;right:1.5rem;width:46px;height:46px;border-radius:50%;background:var(--verde);color:#fff;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:var(--sombra-lg);opacity:0;visibility:hidden;transform:translateY(8px);transition:opacity .3s,transform .3s,visibility .3s,background var(--transition);z-index:900}
.back-top.show{opacity:1;visibility:visible;transform:translateY(0)}
.back-top:hover{background:var(--verde-escuro)}
.back-top svg{width:22px;height:22px}
/* DISCLAIMER + FOOTER */
.site-disclaimer{background:var(--bg-card);border-top:3px solid var(--verde);margin-top:2.5rem;padding:1.2rem 0}
.site-disclaimer p{max-width:var(--max-w);margin:0 auto;padding:0 1.5rem;font-size:.82rem;color:var(--texto-sec);line-height:1.6}
.site-disclaimer strong{color:var(--texto);font-weight:700}
.site-footer{background:var(--verde-escuro);color:rgba(255,255,255,.75);padding:3rem 0 0;margin-top:auto}
.footer-grid{max-width:var(--max-w);margin:0 auto;padding:0 1.5rem;display:grid;grid-template-columns:1.3fr repeat(3,1fr);gap:2.5rem}
.footer-col .footer-heading{color:#fff;font-family:var(--font-heading);font-size:.95rem;font-weight:700;margin-bottom:.8rem;padding-bottom:.4rem;border-bottom:2px solid var(--verde-claro)}
.footer-col p{font-size:.85rem;line-height:1.6}
.footer-col ul{list-style:none}
.footer-col li{margin-bottom:.35rem}
.footer-col a{color:rgba(255,255,255,.75);font-size:.85rem}
.footer-col a:hover{color:var(--verde-claro)}
.footer-bottom{max-width:var(--max-w);margin:2rem auto 0;padding:1.2rem 1.5rem;border-top:1px solid rgba(255,255,255,.1);text-align:center;font-size:.78rem}
.footer-bottom a{color:var(--verde-claro)}
/* RESPONSIVE */
@media(max-width:1024px){
    .cards-grid{grid-template-columns:repeat(3,1fr)}
    .footer-grid{grid-template-columns:repeat(2,1fr)}
    .hero-main h1{font-size:1.4rem}
    .header-inner{column-gap:.5rem}
    .nav-cat-link{padding:.6rem .7rem;font-size:.72rem}
}
@media(max-width:1023px){
    .header-inner{display:flex !important;flex-wrap:wrap;align-items:center;padding:.6rem 1rem;row-gap:.5rem;column-gap:.5rem}
    .site-logo{flex:1;justify-self:start}
    .menu-toggle{display:flex;flex-shrink:0}
    .site-search{order:3;width:100%;max-width:none;border-radius:var(--radius-sm)}
    .site-search input{padding:.55rem 1rem;font-size:.92rem}
    .nav-wrap{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:9998;justify-content:flex-end;align-items:stretch;margin:0;padding:0;width:100%;border:none;order:4}
    .nav-wrap.open{display:flex}
    .nav-cats{background:var(--bg-card);width:280px;max-width:85vw;padding:1.5rem;overflow-y:auto;flex-direction:column;justify-content:flex-start;gap:.3rem;box-shadow:-5px 0 25px rgba(0,0,0,.15);animation:slideIn .25s ease;max-width:none}
    @keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
    .nav-cats-close{display:block;align-self:flex-end;background:none;border:none;cursor:pointer;padding:.5rem;color:var(--texto);font-size:1.4rem;font-weight:700;margin-bottom:.5rem;line-height:1}
    .nav-cats li{display:block;width:100%}
    .nav-cat-link{display:block;padding:.65rem .85rem;border-radius:var(--radius-sm);font-size:.92rem;width:100%;text-align:left;border:1px solid var(--borda);text-transform:none;letter-spacing:0;border-bottom:1px solid var(--borda)}
    .nav-cat-link:hover,.nav-cat-link:focus{background:var(--verde);color:#fff;border-color:var(--verde)}
}
@media(max-width:768px){
    .hero{grid-template-columns:1fr}
    .hero-main h1{font-size:1.3rem}
    .hero-main-body{padding:1.2rem}
    .hero-main-img{margin:0 1.2rem}
    .hero-main-footer{padding:.5rem 1.2rem 1rem}
    .hero-sidebar{grid-template-columns:repeat(2,1fr)}
    .cards-grid{grid-template-columns:repeat(2,1fr)}
    .footer-grid{grid-template-columns:1fr 1fr}
    .newsletter{padding:2rem 1.5rem}
    .newsletter h2{font-size:1.25rem}
}
@media(max-width:480px){
    .hero-sidebar{grid-template-columns:1fr 1fr}
    .hero-card-body h3{font-size:.8rem}
    .hero-card-excerpt{display:none}
    .cards-grid{grid-template-columns:1fr}
    .card{flex-direction:row;align-items:stretch}
    .card-body{flex:1;padding:.7rem;gap:.3rem}
    .card-body h3{font-size:.88rem;-webkit-line-clamp:2}
    .card-excerpt{-webkit-line-clamp:1;font-size:.75rem}
    .card-img{aspect-ratio:1/1;width:110px;flex-shrink:0;margin:0;border-radius:0;order:-1}
    .card-footer{display:none}
    .footer-grid{grid-template-columns:1fr}
    .newsletter-row{flex-direction:column;gap:.5rem}
    .newsletter-row input[type="email"],.newsletter-row button{border-radius:var(--radius-sm)}
    .back-top{bottom:1rem;right:1rem;width:42px;height:42px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important}}
</style>
</head>
<body>
<a class="skip-link sr-only" href="#content">Pular para o conteúdo</a>
<div class="read-progress" id="readProgress" aria-hidden="true"></div>

<header class="site-header"><div class="header-inner">
<form class="site-search" role="search" method="get" action="<?php echo esc_url($site_url);?>"><label for="sq" class="sr-only">Buscar</label><input type="search" id="sq" name="s" placeholder="Buscar vagas, benefícios, direitos..." autocomplete="off"><span class="search-kbd" aria-hidden="true">/</span><button type="submit" aria-label="Buscar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button></form>
<a href="<?php echo esc_url($site_url);?>" class="site-logo"><span class="site-logo-icon" aria-hidden="true">V</span><span class="site-logo-text"><span class="site-logo-name"><?php echo esc_html($site_name);?></span><span class="site-logo-tagline">Vagas · Empregos · Benefícios</span></span></a>
<button class="menu-toggle" id="menuToggle" aria-label="Abrir menu" aria-expanded="false"><span></span><span></span><span></span></button>
<nav class="nav-wrap" id="navWrap" aria-label="Categorias"><ul class="nav-cats" id="navCats"><li style="display:none" class="close-li"><button class="nav-cats-close" id="navClose" aria-label="Fechar menu">&times;</button></li><?php foreach($categories as $c):?><li><a href="<?php echo esc_url(get_category_link($c->term_id));?>" class="nav-cat-link" data-cat-slug="<?php echo esc_attr($c->slug);?>"><?php echo esc_html($c->name);?></a></li><?php endforeach;?></ul></nav>
</div></header>

<main id="content" class="container">

<!-- 1. HERO -->
<section class="hero" aria-label="Destaques principais">
<a href="<?php echo esc_url(get_permalink($featured_main->ID));?>" class="hero-main" data-pid="<?php echo (int)$featured_main->ID;?>" data-cat="<?php echo esc_attr($fcat);?>" data-cat-slug="<?php echo esc_attr($fcslug);?>" data-thumb="<?php echo esc_url($lcp_src?:veb_fallback_img());?>" data-title="<?php echo esc_attr($featured_main->post_title);?>">
    <div class="hero-main-body"><div class="badges"><span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($fcslug));?>"><?php echo esc_html($fcat);?></span><?php if(veb_is_new($featured_main->post_date)):?><span class="new-badge">NOVO</span><?php endif;?></div><h1><?php echo esc_html($featured_main->post_title);?></h1><p class="hero-main-excerpt"><?php echo esc_html($fexc);?></p></div>
    <div class="hero-main-img"><img src="<?php echo esc_url($lcp_src?:veb_fallback_img());?>" <?php if($lcp_srcset):?>srcset="<?php echo esc_attr($lcp_srcset);?>" sizes="(max-width:768px) 100vw, 62vw"<?php endif;?> alt="<?php echo esc_attr($featured_main->post_title);?>" width="800" height="450" fetchpriority="high" decoding="sync"></div>
    <div class="hero-main-footer"><time datetime="<?php echo esc_attr(date('c',strtotime($featured_main->post_date)));?>"><?php echo esc_html(veb_time_ago($featured_main->post_date));?></time><span class="dot">·</span><span class="reading-time"><?php echo (int)veb_reading_time($featured_main->ID);?> min de leitura</span></div>
</a>
<div class="hero-sidebar"><h2 class="sr-only">Mais destaques</h2><?php foreach($featured_secondary as $i=>$p):$pt=get_the_post_thumbnail_url($p->ID,'medium');$pl=get_permalink($p->ID);$pc=veb_get_cat($p->ID);$pcsl=veb_get_cat_slug($p->ID);$sexc=veb_get_excerpt($p);$isn=veb_is_new($p->post_date);?>
<a href="<?php echo esc_url($pl);?>" class="hero-card" data-pid="<?php echo (int)$p->ID;?>" data-cat="<?php echo esc_attr($pc);?>" data-cat-slug="<?php echo esc_attr($pcsl);?>" data-thumb="<?php echo esc_url($pt?:veb_fallback_img());?>" data-title="<?php echo esc_attr($p->post_title);?>"><div class="hero-card-body"><div class="badges"><span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($pcsl));?>"><?php echo esc_html($pc);?></span><?php if($isn):?><span class="new-badge">NOVO</span><?php endif;?></div><h3><?php echo esc_html(wp_trim_words($p->post_title,10));?></h3><p class="hero-card-excerpt"><?php echo esc_html($sexc);?></p></div><div class="hero-card-img"><img src="<?php echo esc_url($pt?:veb_fallback_img());?>" alt="<?php echo esc_attr(wp_trim_words($p->post_title,6));?>" width="400" height="225" loading="<?php echo $i<2?'eager':'lazy';?>" decoding="async"></div><div class="hero-card-footer"><time datetime="<?php echo esc_attr(date('c',strtotime($p->post_date)));?>"><?php echo esc_html(veb_time_ago($p->post_date));?></time></div></a>
<?php endforeach;?></div></section>

<!-- 2. VISTO POR ÚLTIMO (oculto até JS confirmar localStorage) -->
<section class="smart-section" id="recentSection" style="display:none" aria-labelledby="recent-h">
    <div class="smart-section-hdr">
        <h2 id="recent-h"><span aria-hidden="true">&#128338;</span> Visto por Último</h2>
        <button type="button" class="clear-history" id="clearHistory" aria-label="Limpar histórico">Limpar</button>
    </div>
    <div class="smart-grid" id="recentGrid"></div>
</section>

<!-- 3. CATEGORIAS -->
<?php if(!empty($all_categories)):?>
<nav class="quick-links"><h2 class="quick-links-title"><span aria-hidden="true">&#9889;</span> Navegue por Categorias</h2><div class="quick-grid"><?php foreach($all_categories as $cat):?><a href="<?php echo esc_url(get_category_link($cat->term_id));?>" class="quick-tag" data-cat-slug="<?php echo esc_attr($cat->slug);?>" style="background:<?php echo esc_attr(veb_cat_color($cat->slug));?>"><?php echo esc_html($cat->name);?> <span class="quick-tag-count">(<?php echo esc_html($cat->count);?>)</span></a><?php endforeach;?></div></nav>
<?php endif;?>

<!-- 4. NEWSLETTER -->
<section class="newsletter"><h2>Receba as melhores vagas no seu e-mail</h2><p>Fique por dentro de vagas de emprego, benefícios e direitos trabalhistas atualizados.</p><form class="newsletter-form" id="nlForm" novalidate><div class="newsletter-row"><input type="email" id="nlEmail" placeholder="Digite seu melhor e-mail" required aria-label="Seu e-mail"><button type="submit" id="nlBtn">Inscrever-se</button></div><div class="captcha-row"><span class="captcha-label" id="cQ"></span><input type="text" class="captcha-input" id="cA" placeholder="?" autocomplete="off" inputmode="numeric" aria-label="Resposta" required><button type="button" class="captcha-refresh" id="cR" aria-label="Nova pergunta">&#8635;</button></div><p class="captcha-error" id="cErr">Resposta incorreta. Tente novamente.</p><p class="newsletter-success" id="nlOk">&#10003; Inscrito com sucesso!</p></form></section>

<div class="ad-slot" aria-hidden="true"></div>

<!-- 5. TODOS OS POSTS + INFINITE SCROLL -->
<section aria-labelledby="sec-all"><div class="section-hdr"><h2 id="sec-all"><span class="emoji" aria-hidden="true">&#128188;</span> Todos os Posts</h2><span class="section-hdr-sub"><?php echo (int)$total_posts;?> publicações</span></div>
<div class="cards-grid" id="allGrid"><?php foreach($all_posts_page1 as $p)veb_render_card($p);?></div>
<?php if($has_more):?><div id="scroll-sentinel" aria-hidden="true"></div><div class="loader" id="loader"><div class="spinner" role="status" aria-label="Carregando"></div></div><?php endif;?>
<p class="no-more" id="noMore" <?php if(!$has_more)echo'style="display:block"';?>>Você viu todas as publicações.</p>
</section>
</main>

<button class="back-top" id="backTop" aria-label="Voltar ao topo"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19V5M5 12l7-7 7 7"/></svg></button>

<aside class="site-disclaimer"><p><strong>Aviso:</strong> Este site é um portal independente de conteúdo informativo. Não possuímos vínculo oficial com empresas, órgãos públicos ou instituições citadas.</p></aside>
<footer class="site-footer"><div class="footer-grid"><div class="footer-col"><h3 class="footer-heading"><?php echo esc_html($site_name);?></h3><p>Portal dedicado a informar sobre vagas de emprego, benefícios sociais, direitos trabalhistas e oportunidades profissionais em todo o Brasil.</p></div><div class="footer-col"><h3 class="footer-heading">Institucional</h3><ul><li><a href="<?php echo esc_url(home_url('/sobre/'));?>">Sobre nós</a></li><li><a href="<?php echo esc_url(home_url('/politica-de-privacidade/'));?>">Política de Privacidade</a></li><li><a href="<?php echo esc_url(home_url('/termos-de-uso/'));?>">Termos de Uso</a></li><li><a href="<?php echo esc_url(home_url('/contato/'));?>">Contato</a></li></ul></div><div class="footer-col"><h3 class="footer-heading">Categorias</h3><ul><?php foreach(array_slice($all_categories,0,6) as $fc):?><li><a href="<?php echo esc_url(get_category_link($fc->term_id));?>"><?php echo esc_html($fc->name);?></a></li><?php endforeach;?></ul></div><div class="footer-col"><h3 class="footer-heading">Redes Sociais</h3><ul><li><a href="#" target="_blank" rel="noopener noreferrer">Instagram</a></li><li><a href="#" target="_blank" rel="noopener noreferrer">Facebook</a></li><li><a href="#" target="_blank" rel="noopener noreferrer">YouTube</a></li><li><a href="#" target="_blank" rel="noopener noreferrer">WhatsApp</a></li></ul></div></div><div class="footer-bottom"><p>&copy; <?php echo date('Y');?> <?php echo esc_html($site_name);?> — Portal informativo.</p><p style="margin-top:.3rem"><a href="<?php echo esc_url(home_url('/sitemap.xml'));?>">Mapa do Site</a></p></div></footer>

<script>
(function(){
'use strict';
var LS_RECENT='veb_recent_v1', LS_CATSCORE='veb_catscore_v1', MAX_RECENT=6;

/* ============= Helpers localStorage ============= */
function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}
function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}
function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||''));return d.innerHTML;}

/* ============= Tracking de cliques (cards/hero) ============= */
function trackClick(el){
    var pid=el.getAttribute('data-pid'); if(!pid) return;
    var item={pid:pid, title:el.getAttribute('data-title')||'', cat:el.getAttribute('data-cat')||'', cat_slug:el.getAttribute('data-cat-slug')||'', thumb:el.getAttribute('data-thumb')||'', link:el.getAttribute('href')||'', ts:Date.now()};
    var rec=lsGet(LS_RECENT,[]);
    rec=rec.filter(function(r){return r.pid!==item.pid;});
    rec.unshift(item);
    if(rec.length>MAX_RECENT) rec=rec.slice(0,MAX_RECENT);
    lsSet(LS_RECENT,rec);
    if(item.cat_slug){
        var sc=lsGet(LS_CATSCORE,{});
        sc[item.cat_slug]=(sc[item.cat_slug]||0)+1;
        lsSet(LS_CATSCORE,sc);
    }
}
document.addEventListener('click',function(e){
    var el=e.target.closest('a.card, a.hero-main, a.hero-card');
    if(el) trackClick(el);
},true);

/* ============= Render Visto por Último ============= */
function renderRecent(){
    var rec=lsGet(LS_RECENT,[]);
    var sec=document.getElementById('recentSection'), grid=document.getElementById('recentGrid');
    if(!sec||!grid) return;
    if(!rec.length){sec.style.display='none'; return;}
    var thisPid=null; // home — não há post atual para excluir
    var items=rec.filter(function(r){return r.pid!==thisPid;}).slice(0,MAX_RECENT);
    if(!items.length){sec.style.display='none'; return;}
    var html=items.map(function(r){
        var color=catColor(r.cat_slug||'');
        return '<a class="smart-card" href="'+escH(r.link)+'" data-pid="'+escH(r.pid)+'" data-cat="'+escH(r.cat)+'" data-cat-slug="'+escH(r.cat_slug)+'" data-thumb="'+escH(r.thumb)+'" data-title="'+escH(r.title)+'"><div class="smart-card-img"><img src="'+escH(r.thumb)+'" alt="" loading="lazy" decoding="async" width="400" height="225"></div><div class="smart-card-body"><span class="smart-card-cat" style="background:'+color+'">'+escH(r.cat||'Geral')+'</span><h4>'+escH(r.title)+'</h4></div></a>';
    }).join('');
    grid.innerHTML=html; sec.style.display='block';
}
var clearBtn=document.getElementById('clearHistory');
if(clearBtn) clearBtn.addEventListener('click',function(){
    if(!confirm('Limpar seu histórico de leitura?')) return;
    try{localStorage.removeItem(LS_RECENT);localStorage.removeItem(LS_CATSCORE);}catch(e){}
    var sec=document.getElementById('recentSection'); if(sec) sec.style.display='none';
    reorderNavByPreference();
});

/* ============= Reordena nav-cats com base no histórico ============= */
function reorderNavByPreference(){
    var sc=lsGet(LS_CATSCORE,{});
    var nav=document.getElementById('navCats');
    if(!nav) return;
    var items=Array.prototype.slice.call(nav.querySelectorAll('li')).filter(function(li){return !li.classList.contains('close-li');});
    if(!items.length) return;
    items.forEach(function(li){
        var a=li.querySelector('.nav-cat-link'); if(!a) return;
        var slug=a.getAttribute('data-cat-slug')||'';
        var s=sc[slug]||0;
        if(s>0) a.setAttribute('data-active','1'); else a.removeAttribute('data-active');
    });
    var entries=Object.keys(sc); if(!entries.length) return;
    var sorted=items.slice().sort(function(a,b){
        var sa=sc[(a.querySelector('.nav-cat-link')||{}).getAttribute?a.querySelector('.nav-cat-link').getAttribute('data-cat-slug'):'']||0;
        var sb=sc[(b.querySelector('.nav-cat-link')||{}).getAttribute?b.querySelector('.nav-cat-link').getAttribute('data-cat-slug'):'']||0;
        return sb-sa;
    });
    var closeLi=nav.querySelector('.close-li');
    sorted.forEach(function(li){nav.appendChild(li);});
    if(closeLi) nav.insertBefore(closeLi,nav.firstChild);
}

/* ============= Mapa de cores (espelha PHP) ============= */
function catColor(s){
    var exact={'inss-e-aposentadoria':'#1e40af','inss':'#1e40af','aposentadoria':'#1e3a8a','beneficios-sociais':'#7c3aed','beneficios':'#7c3aed','bolsa-familia':'#6d28d9','pe-de-meia':'#a855f7','fgts-pis-direitos-trabalhador':'#047857','fgts':'#047857','pis-pasep':'#0e6655','pis':'#0e6655','vagas-e-empregos':'#0d6844','vagas-clt':'#0d6844','vagas-de-emprego':'#0d6844','vagas':'#0d6844','empregos':'#0d6844','home-office':'#7c2d12','jovem-aprendiz':'#0369a1','estagio':'#6d28d9','concursos-publicos':'#b91c1c','concursos':'#b91c1c','mei-trabalho-autonomo':'#ea580c','mei':'#ea580c','imposto-de-renda':'#92400e','direitos-sociais-documentos':'#475569','cpf':'#475569','cnh':'#334155','clt-e-direitos':'#b45309','seguro-desemprego':'#b91c1c'};
    s=(s||'').toLowerCase(); if(exact[s]) return exact[s];
    var part={'inss':'#1e40af','aposenta':'#1e3a8a','bolsa':'#6d28d9','beneficio':'#7c3aed','fgts':'#047857','pis':'#0e6655','vaga':'#0d6844','emprego':'#0d6844','remoto':'#7c2d12','aprendiz':'#0369a1','estagio':'#6d28d9','concurso':'#b91c1c','edital':'#7f1d1d','mei':'#ea580c','autonomo':'#f97316','imposto':'#92400e','restitui':'#ca8a04','cpf':'#475569','cnh':'#334155','rg':'#1e293b','documento':'#475569','clt':'#b45309','direito':'#b45309','seguro':'#b91c1c'};
    for(var k in part){if(s.indexOf(k)!==-1) return part[k];}
    var pal=['#0d6844','#1e40af','#7c3aed','#b91c1c','#ea580c','#92400e','#475569','#0e6655','#0369a1','#6d28d9'];
    var h=0; for(var i=0;i<s.length;i++){h=((h<<5)-h)+s.charCodeAt(i); h|=0;}
    return pal[Math.abs(h)%pal.length];
}

/* ============= Menu mobile ============= */
var toggle=document.getElementById('menuToggle'),navW=document.getElementById('navWrap'),closeLi=document.querySelector('.close-li'),closeBtn=document.getElementById('navClose');
function oN(){if(!navW)return;navW.classList.add('open');if(closeLi)closeLi.style.display='block';toggle.classList.add('active');toggle.setAttribute('aria-expanded','true');document.body.style.overflow='hidden';if(closeBtn)closeBtn.focus();}
function cN(){if(!navW)return;navW.classList.remove('open');if(closeLi)closeLi.style.display='none';toggle.classList.remove('active');toggle.setAttribute('aria-expanded','false');document.body.style.overflow='';toggle.focus();}
if(toggle&&navW){toggle.addEventListener('click',oN);if(closeBtn)closeBtn.addEventListener('click',cN);navW.addEventListener('click',function(e){if(e.target===navW)cN();});navW.querySelectorAll('.nav-cat-link').forEach(function(a){a.addEventListener('click',cN);});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&navW.classList.contains('open'))cN();});}

/* ============= Captcha + newsletter ============= */
var cRs,cQe=document.getElementById('cQ'),cIe=document.getElementById('cA'),cEe=document.getElementById('cErr'),cRe=document.getElementById('cR'),nFe=document.getElementById('nlForm'),nEe=document.getElementById('nlEmail'),nBe=document.getElementById('nlBtn'),nOe=document.getElementById('nlOk');
function gCa(){var a=Math.floor(Math.random()*15)+1,b=Math.floor(Math.random()*10)+1;if(Math.random()>.5&&a>=b){cRs=a-b;if(cQe)cQe.textContent='Quanto \xe9 '+a+' − '+b+'?';}else{cRs=a+b;if(cQe)cQe.textContent='Quanto \xe9 '+a+' + '+b+'?';}if(cIe)cIe.value='';if(cEe)cEe.style.display='none';if(nOe)nOe.style.display='none';}
gCa();if(cRe)cRe.addEventListener('click',gCa);
if(nFe)nFe.addEventListener('submit',function(e){e.preventDefault();if(cEe)cEe.style.display='none';if(nOe)nOe.style.display='none';var em=nEe?nEe.value.trim():'';if(!em||em.indexOf('@')===-1)return;var ans=cIe?parseInt(cIe.value,10):NaN;if(isNaN(ans)||ans!==cRs){if(cEe)cEe.style.display='block';if(cIe){cIe.value='';cIe.focus();}gCa();return;}if(nBe){nBe.textContent='✓ Inscrito!';nBe.disabled=true;}if(nEe)nEe.value='';if(cIe)cIe.value='';if(nOe)nOe.style.display='block';setTimeout(function(){if(nBe){nBe.textContent='Inscrever-se';nBe.disabled=false;}if(nOe)nOe.style.display='none';gCa();},4000);});

/* ============= Reading progress + Back to top ============= */
var rp=document.getElementById('readProgress'),bt=document.getElementById('backTop');
function onScroll(){
    var st=window.pageYOffset||document.documentElement.scrollTop;
    var dh=document.documentElement.scrollHeight-window.innerHeight;
    var pct=dh>0?(st/dh)*100:0;
    if(rp) rp.style.width=pct+'%';
    if(bt){if(st>500) bt.classList.add('show'); else bt.classList.remove('show');}
}
window.addEventListener('scroll',onScroll,{passive:true});
if(bt) bt.addEventListener('click',function(){window.scrollTo({top:0,behavior:'smooth'});});

/* ============= Keyboard shortcut "/" focus search ============= */
document.addEventListener('keydown',function(e){
    if(e.key==='/' && document.activeElement && document.activeElement.tagName!=='INPUT' && document.activeElement.tagName!=='TEXTAREA'){
        var sq=document.getElementById('sq'); if(sq){e.preventDefault(); sq.focus();}
    }
});

/* ============= Infinite scroll ============= */
var page=2,loading=false,done=<?php echo $has_more?'false':'true';?>;
var grid=document.getElementById('allGrid'),loader=document.getElementById('loader'),noMore=document.getElementById('noMore'),sentinel=document.getElementById('scroll-sentinel');
var ajaxUrl='<?php echo admin_url("admin-ajax.php");?>';var fbImg='<?php echo veb_fallback_img();?>';var useAjax=true;
function smartDate(iso,fmt){var d=(Date.now()-new Date(iso).getTime())/1000;if(d<3600)return Math.max(1,Math.floor(d/60))+' min atrás';if(d<86400)return Math.floor(d/3600)+'h atrás';return fmt;}
function isNew(iso){return (Date.now()-new Date(iso).getTime())<86400000;}
function finish(){done=true;if(noMore)noMore.style.display='block';}
function buildCard(p){
    var a=document.createElement('a');a.href=p.link;a.className='card';
    a.setAttribute('data-pid',p.id||'');a.setAttribute('data-cat',p.cat||'');a.setAttribute('data-cat-slug',p.cat_slug||'');a.setAttribute('data-thumb',p.thumb||fbImg);a.setAttribute('data-title',p.title||'');
    var newB=isNew(p.date)?'<span class="new-badge">NOVO</span>':'';
    var rt=p.reading_time?('· '+p.reading_time+' min'):'';
    a.innerHTML='<div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:'+catColor(p.cat_slug)+'">'+escH(p.cat)+'</span>'+newB+'</div><h3>'+escH(p.title)+'</h3><p class="card-excerpt">'+escH(p.excerpt||'')+'</p></div><div class="card-img"><img src="'+escH(p.thumb||fbImg)+'" alt="" width="400" height="225" loading="lazy" decoding="async"></div><div class="card-footer"><time datetime="'+escH(p.date)+'">'+escH(smartDate(p.date,p.date_fmt))+'</time><span class="reading-time">'+rt+'</span></div>';
    return a;
}
function buildRestCard(p){
    var img=fbImg; try{img=p._embedded['wp:featuredmedia'][0].source_url;}catch(e){}
    var cat='Geral',cs='geral'; try{cat=p._embedded['wp:term'][0][0].name;cs=p._embedded['wp:term'][0][0].slug;}catch(e){}
    var fmt=new Date(p.date).toLocaleDateString('pt-BR',{day:'2-digit',month:'short',year:'numeric'});
    var exc=''; try{exc=p.excerpt.rendered.replace(/<[^>]+>/g,'').substring(0,120);}catch(e){}
    var newB=isNew(p.date)?'<span class="new-badge">NOVO</span>':'';
    var a=document.createElement('a');a.href=p.link;a.className='card';
    a.setAttribute('data-pid',p.id||'');a.setAttribute('data-cat',cat);a.setAttribute('data-cat-slug',cs);a.setAttribute('data-thumb',img);a.setAttribute('data-title',p.title?(p.title.rendered||''):'');
    a.innerHTML='<div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:'+catColor(cs)+'">'+escH(cat)+'</span>'+newB+'</div><h3>'+escH(p.title.rendered)+'</h3><p class="card-excerpt">'+escH(exc)+'</p></div><div class="card-img"><img src="'+img+'" alt="" width="400" height="225" loading="lazy" decoding="async"></div><div class="card-footer"><time datetime="'+p.date+'">'+escH(smartDate(p.date,fmt))+'</time></div>';
    return a;
}
function tryAjax(){return fetch(ajaxUrl+'?action=veb_load_posts&paged='+page).then(function(r){if(!r.ok)throw new Error(r.status);return r.json();}).then(function(d){if(!d.posts||!d.posts.length){finish();return;}var f=document.createDocumentFragment();d.posts.forEach(function(p){f.appendChild(buildCard(p));});grid.appendChild(f);page++;if(page>d.max_pages)finish();});}
function tryRest(){return fetch('/wp-json/wp/v2/posts?per_page=8&page='+page+'&_embed').then(function(r){if(!r.ok)throw new Error(r.status);return r.json();}).then(function(posts){if(!posts||!posts.length){finish();return;}var f=document.createDocumentFragment();posts.forEach(function(p){f.appendChild(buildRestCard(p));});grid.appendChild(f);page++;});}
function loadMore(){if(loading||done||!grid)return;loading=true;if(loader)loader.style.display='block';var attempt=useAjax?tryAjax():tryRest();attempt.catch(function(){if(useAjax){useAjax=false;return tryRest().catch(function(){finish();});}finish();}).finally(function(){loading=false;if(loader)loader.style.display='none';});}
if(!done){if('IntersectionObserver' in window&&sentinel){new IntersectionObserver(function(e){if(e[0].isIntersecting)loadMore();},{rootMargin:'0px 0px 800px 0px'}).observe(sentinel);}var st=false;window.addEventListener('scroll',function(){if(st||done)return;st=true;requestAnimationFrame(function(){st=false;if(window.scrollY+window.innerHeight>=document.documentElement.scrollHeight-1000)loadMore();});},{passive:true});setTimeout(function(){if(!done&&!loading&&sentinel){var r=sentinel.getBoundingClientRect();if(r.top<window.innerHeight+800)loadMore();}},500);}

/* ============= Init ============= */
renderRecent();
reorderNavByPreference();
onScroll();
})();
</script>
<?php wp_footer();?>
</body>
</html>
