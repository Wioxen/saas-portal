<?php
/**
 * Astra Child Guia dos Cursos — header.php
 */
if (!defined('ABSPATH')) exit;
$site_name = get_bloginfo('name');
$site_url = home_url('/');
$nav_cats = gdc_nav_cats();
$trending_cats = gdc_trending_cats();

$lcp_src = $lcp_srcset = $lcp_sizes = '';
if (is_front_page()) {
    $featured_main = get_posts(['numberposts'=>1, 'post_status'=>'publish']);
    if ($featured_main) {
        $lcp_id = get_post_thumbnail_id($featured_main[0]->ID);
        if ($lcp_id) {
            $lcp_src = wp_get_attachment_image_url($lcp_id,'large');
            $lcp_srcset = wp_get_attachment_image_srcset($lcp_id,'large');
            $lcp_sizes = '(max-width:768px) 100vw, 62vw';
        }
    }
} elseif (is_singular('post')) {
    $lcp_id = get_post_thumbnail_id(get_queried_object_id());
    if ($lcp_id) {
        $lcp_src = wp_get_attachment_image_url($lcp_id,'large');
        $lcp_srcset = wp_get_attachment_image_srcset($lcp_id,'large');
        $lcp_sizes = '(max-width:768px) 100vw, 800px';
    }
} elseif (is_archive() || is_category() || is_search()) {
    global $wp_query;
    if (!empty($wp_query->posts)) {
        $lcp_id = get_post_thumbnail_id($wp_query->posts[0]->ID);
        if ($lcp_id) {
            $lcp_src = wp_get_attachment_image_url($lcp_id,'medium');
            $lcp_srcset = wp_get_attachment_image_srcset($lcp_id,'medium');
            $lcp_sizes = '(max-width:480px) 115px, (max-width:768px) 33vw, 280px';
        }
    }
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#1e40af" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0f172a" media="(prefers-color-scheme: dark)">
<meta name="color-scheme" content="light dark">
<meta name="format-detection" content="telephone=no">
<link rel="manifest" href="<?php echo esc_attr(gdc_pwa_manifest_uri()); ?>">
<link rel="apple-touch-icon" href="<?php echo esc_url(get_site_icon_url(180) ?: home_url('/favicon.ico')); ?>">
<?php wp_head(); ?>
<?php if ($lcp_src): ?>
<link rel="preload" as="image" href="<?php echo esc_url($lcp_src); ?>" <?php if($lcp_srcset):?>imagesrcset="<?php echo esc_attr($lcp_srcset); ?>" imagesizes="<?php echo esc_attr($lcp_sizes); ?>"<?php endif;?> fetchpriority="high">
<?php endif; ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="preload" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Merriweather:wght@700;900&display=swap" as="style" onload="this.onload=null;this.rel='stylesheet'">
<noscript><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Merriweather:wght@700;900&display=swap"></noscript>
<?php if (is_front_page()): $home_jsonld = gdc_home_itemlist(); if ($home_jsonld): ?>
<script type="application/ld+json"><?php echo wp_json_encode($home_jsonld, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?></script>
<?php endif; endif; ?>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box}
:root{
    --azul:#1e40af;--azul-escuro:#1e3a8a;--azul-claro:#3b82f6;--azul-soft:#dbeafe;
    --laranja:#ea580c;--laranja-claro:#f97316;
    --bg:#f8fafc;--bg-card:#ffffff;--borda:#e2e8f0;
    --texto:#1e293b;--texto-sec:#475569;--texto-muted:#64748b;
    --titulo:#1e3a8a;
    --sombra:0 1px 3px rgba(15,23,42,.06),0 1px 2px rgba(15,23,42,.04);
    --sombra-md:0 4px 6px -1px rgba(15,23,42,.08),0 2px 4px -2px rgba(15,23,42,.05);
    --sombra-lg:0 10px 25px -3px rgba(15,23,42,.1),0 4px 6px -4px rgba(15,23,42,.05);
    --radius:10px;--radius-sm:6px;
    --font-body:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
    --font-heading:'Merriweather',Georgia,serif;
    --max-w:1280px;--transition:.2s ease;
    --nav-bg:#f1f5f9;
    --overlay:rgba(15,23,42,.55);
}
@media (prefers-color-scheme: dark){
    :root{
        --azul:#60a5fa;--azul-escuro:#3b82f6;--azul-claro:#93c5fd;--azul-soft:#1e3a8a;
        --bg:#0f172a;--bg-card:#1e293b;--borda:#334155;
        --texto:#f1f5f9;--texto-sec:#cbd5e1;--texto-muted:#94a3b8;
        --titulo:#f1f5f9;
        --sombra:0 1px 3px rgba(0,0,0,.4),0 1px 2px rgba(0,0,0,.2);
        --sombra-md:0 4px 6px -1px rgba(0,0,0,.5),0 2px 4px -2px rgba(0,0,0,.3);
        --sombra-lg:0 10px 25px -3px rgba(0,0,0,.5),0 4px 6px -4px rgba(0,0,0,.3);
        --nav-bg:#1e293b;
        --overlay:rgba(0,0,0,.7);
    }
}
html{scroll-behavior:smooth;scroll-padding-top:140px;-webkit-text-size-adjust:100%}
body{background:var(--bg);font-family:var(--font-body);color:var(--texto);line-height:1.6;display:flex;flex-direction:column;min-height:100vh;-webkit-font-smoothing:antialiased;font-size:16px}
img{max-width:100%;height:auto;display:block}
a{color:var(--azul);text-decoration:none;transition:color var(--transition)}
a:hover{color:var(--azul-claro)}
button{font-family:inherit;-webkit-tap-highlight-color:transparent}
input,textarea{font-family:inherit;font-size:16px}
.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);border:0}
.skip-link{position:absolute;top:-40px;left:0;background:var(--azul);color:#fff;padding:.5rem 1rem;z-index:9999}
.skip-link:focus{top:0}

.read-progress{position:fixed;top:0;left:0;height:3px;width:0;background:linear-gradient(90deg,var(--azul-claro),var(--azul));z-index:1100;transition:width .1s linear}

/* HEADER */
.site-header{background:var(--bg-card);box-shadow:var(--sombra-md);position:sticky;top:0;z-index:1000;border-bottom:1px solid var(--borda)}
.header-inner{max-width:var(--max-w);margin:0 auto;padding:.7rem 1.5rem .35rem;display:grid;grid-template-columns:1fr auto 1fr;grid-template-rows:auto auto;align-items:center;column-gap:1rem;row-gap:.55rem}
.site-search{grid-column:1;grid-row:1;justify-self:start;display:flex;align-items:center;background:var(--bg);border:1.5px solid var(--borda);border-radius:50px;overflow:hidden;width:100%;max-width:300px;transition:border-color var(--transition),box-shadow var(--transition)}
.site-search:focus-within{border-color:var(--azul);box-shadow:0 0 0 3px rgba(59,130,246,.18)}
.site-search input{border:none;background:transparent;padding:.45rem 1rem;font-size:15px;color:var(--texto);outline:none;width:100%;min-width:0}
.site-search input::placeholder{color:var(--texto-muted)}
.site-search button{background:none;border:none;cursor:pointer;padding:.45rem .75rem;color:var(--azul);display:flex;align-items:center;flex-shrink:0;min-height:36px;min-width:36px}
.site-search button svg{width:17px;height:17px}
.search-kbd{display:none;font-size:.65rem;background:var(--borda);color:var(--texto-sec);padding:1px 5px;border-radius:3px;margin-right:.5rem;font-family:monospace;font-weight:600}
@media(min-width:1024px){.search-kbd{display:inline-block}}
.site-logo{grid-column:2;grid-row:1;justify-self:center;display:flex;align-items:center;gap:.7rem;text-decoration:none}
.site-logo svg{flex-shrink:0;border-radius:9px;box-shadow:0 2px 6px rgba(30,58,138,.25)}
.site-logo-text{display:flex;flex-direction:column;line-height:1.15;text-align:left}
.site-logo-name{font-weight:800;font-size:1.15rem;color:var(--titulo);letter-spacing:-.01em}
.site-logo-tagline{font-size:.66rem;color:var(--texto-sec);font-weight:600;letter-spacing:.05em;text-transform:uppercase}
.menu-toggle{grid-column:3;grid-row:1;justify-self:end;display:none;background:none;border:none;cursor:pointer;padding:8px 4px;flex-direction:column;align-items:center;justify-content:center;gap:5px;width:44px;height:44px}
.menu-toggle span{display:block;width:22px;height:2.5px;background:var(--titulo);border-radius:2px;transition:all .3s;transform-origin:center}
.menu-toggle.active span:nth-child(1){transform:translateY(7.5px) rotate(45deg)}
.menu-toggle.active span:nth-child(2){opacity:0;transform:scaleX(0)}
.menu-toggle.active span:nth-child(3){transform:translateY(-7.5px) rotate(-45deg)}
.nav-wrap{grid-column:1 / -1;grid-row:2;width:calc(100% + 3rem);margin:0 -1.5rem;background:var(--nav-bg);border-top:1px solid var(--borda);padding:0;position:relative}
.nav-cats{display:flex;justify-content:center;gap:2px;list-style:none;padding:0 1.5rem;margin:0 auto;max-width:var(--max-w);overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch}
.nav-cats::-webkit-scrollbar{display:none}
.nav-cats li{flex-shrink:0;list-style:none}
.nav-cat-link{display:inline-flex;align-items:center;gap:4px;padding:.6rem .7rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;color:var(--texto);text-decoration:none;border-bottom:3px solid transparent;transition:color .18s,border-color .18s,background .18s;white-space:nowrap;min-height:44px}
.nav-cat-link:hover,.nav-cat-link:focus{color:var(--azul);border-bottom-color:var(--azul);background:rgba(30,64,175,.06)}
.nav-cat-link[data-active="1"]{color:var(--azul);border-bottom-color:var(--azul)}
.nav-cat-link .hot-dot{width:6px;height:6px;background:#ef4444;border-radius:50%;display:inline-block;animation:pulse-dot 1.5s ease-in-out infinite}
@keyframes pulse-dot{0%,100%{transform:scale(1);opacity:1}50%{transform:scale(1.4);opacity:.6}}
.nav-cats-close{display:none}
/* dropdown de subcategorias: regras em style.css (.nav-subcats, .has-children, .nav-chevron) */

.container{max-width:var(--max-w);width:100%;margin:0 auto;padding:0 1.5rem}

/* HERO */
.hero{display:grid;grid-template-columns:1.6fr 1fr;gap:1.25rem;margin:1.5rem 0}
.hero-main{background:var(--bg-card);border-radius:var(--radius);overflow:hidden;display:flex;flex-direction:column;text-decoration:none;color:var(--texto);box-shadow:var(--sombra);border:1px solid var(--borda);transition:box-shadow var(--transition),transform var(--transition);position:relative}
.hero-main:hover{box-shadow:var(--sombra-lg);transform:translateY(-2px)}
.hero-main-body{padding:1.5rem 1.5rem 1rem;display:flex;flex-direction:column;gap:.5rem}
.hero-main-body .badges{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.hero-main h1{font-family:var(--font-heading);font-size:1.6rem;font-weight:900;line-height:1.3;color:var(--texto);margin:0}
.hero-main-excerpt{font-size:.92rem;color:var(--texto-muted);line-height:1.55;display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.hero-main-img{aspect-ratio:16/9;overflow:hidden;background:var(--borda);margin:0 1.5rem;border-radius:var(--radius-sm);position:relative}
.hero-main-img img{width:100%;height:100%;object-fit:cover;transition:transform .5s ease}
.hero-main:hover .hero-main-img img{transform:scale(1.03)}
.hero-main-footer{padding:.6rem 1.5rem 1.2rem;display:flex;align-items:center;gap:.6rem;flex-wrap:wrap}
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
.cat-badge{display:inline-block;padding:.22rem .7rem;border-radius:4px;font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#fff;line-height:1.4}
.new-badge{display:inline-block;padding:.18rem .55rem;border-radius:4px;font-size:.62rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#dc2626,#f97316);text-transform:uppercase;letter-spacing:.05em;line-height:1.4;animation:pulse-new 2s ease-in-out infinite}
.hot-badge{display:inline-flex;align-items:center;gap:3px;padding:.18rem .55rem;border-radius:4px;font-size:.62rem;font-weight:800;color:#fff;background:linear-gradient(135deg,#f97316,#facc15);text-transform:uppercase;letter-spacing:.05em;line-height:1.4}
.hot-badge::before{content:'\01F525';font-size:.7rem}
@keyframes pulse-new{0%,100%{opacity:1;transform:scale(1)}50%{opacity:.85;transform:scale(.97)}}

/* SECTION HEADERS */
.section-hdr{display:flex;align-items:center;justify-content:space-between;margin:2.5rem 0 1.2rem;padding-bottom:.6rem;border-bottom:3px solid var(--azul);gap:.5rem;flex-wrap:wrap}
.section-hdr h2{font-family:var(--font-heading);font-size:1.3rem;font-weight:900;color:var(--titulo);display:flex;align-items:center;gap:.5rem}
.section-hdr-sub{font-size:.78rem;color:var(--texto-sec);font-weight:500}

/* CARDS */
.cards-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1.15rem}
.card{background:var(--bg-card);border-radius:var(--radius);overflow:hidden;box-shadow:var(--sombra);text-decoration:none;color:var(--texto);transition:box-shadow var(--transition),transform var(--transition);display:flex;flex-direction:column;border:1px solid var(--borda);position:relative}
.card:hover{box-shadow:var(--sombra-lg);transform:translateY(-3px)}
.card-body{padding:.85rem .85rem .5rem;display:flex;flex-direction:column;gap:.4rem}
.card-meta-row{display:flex;align-items:center;gap:.4rem;flex-wrap:wrap}
.card-body h3{font-size:.95rem;font-weight:700;color:var(--texto);line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.card-excerpt{font-size:.82rem;color:var(--texto-muted);line-height:1.5;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.card-img{aspect-ratio:16/9;overflow:hidden;background:var(--borda);margin:0 .85rem;border-radius:var(--radius-sm);position:relative}
.card-img img{width:100%;height:100%;object-fit:cover;transition:transform .4s ease}
.card:hover .card-img img{transform:scale(1.06)}
.card-footer{padding:.5rem .85rem .75rem;display:flex;align-items:center;gap:.4rem}
.card-footer time{font-size:.74rem;color:var(--texto-sec);font-weight:500}
.reading-time{font-size:.74rem;color:var(--texto-muted)}
.card-share{position:absolute;top:.5rem;right:.5rem;width:38px;height:38px;border:none;background:#ffffff;color:#1e3a8a;border-radius:50%;cursor:pointer;display:flex !important;align-items:center;justify-content:center;box-shadow:0 2px 6px rgba(15,23,42,.2);transition:background var(--transition),color var(--transition),transform var(--transition);z-index:3;padding:0;line-height:0}
.card-share svg{width:18px !important;height:18px !important;display:block !important;color:inherit}
.card-share svg path{fill:currentColor !important}
.card-share:hover,.card-share:focus{background:var(--azul);color:#fff;transform:scale(1.06)}
@media (prefers-color-scheme: dark){.card-share{background:#1e293b;color:#93c5fd}}

/* QUICK LINKS — CARROSSEL */
.quick-links{background:var(--bg-card);border-radius:var(--radius);padding:1.5rem 0 1.2rem;margin:2rem 0;box-shadow:var(--sombra);border:1px solid var(--borda);position:relative;overflow:hidden}
.quick-links-title{font-family:var(--font-heading);color:var(--titulo);font-size:1.05rem;font-weight:700;margin:0 1.5rem 1rem;display:flex;align-items:center;gap:.5rem}
.quick-grid-wrap{position:relative}
.quick-grid{display:flex;flex-wrap:nowrap;gap:.55rem;overflow-x:auto;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch;scrollbar-width:none;padding:.4rem 1.5rem .6rem;scroll-padding:0 1.5rem;mask-image:linear-gradient(90deg,transparent 0,#000 1.5rem,#000 calc(100% - 2rem),transparent 100%);-webkit-mask-image:linear-gradient(90deg,transparent 0,#000 1.5rem,#000 calc(100% - 2rem),transparent 100%)}
.quick-grid::-webkit-scrollbar{display:none}
.quick-tag{flex-shrink:0;scroll-snap-align:start;padding:.55rem 1.1rem;border-radius:50px;font-size:.85rem;font-weight:600;color:#fff;background:var(--azul);transition:all var(--transition);text-decoration:none;white-space:nowrap;box-shadow:0 1px 3px rgba(15,23,42,.15);position:relative;min-height:38px;display:inline-flex;align-items:center}
.quick-tag:hover{transform:translateY(-1px);box-shadow:var(--sombra-md);filter:brightness(1.08);color:#fff}
.quick-tag-count{font-size:.72rem;opacity:.85;font-weight:500;margin-left:.25rem}
.quick-tag .hot-dot{width:8px;height:8px;background:#facc15;border-radius:50%;display:inline-block;margin-left:.4rem}
.quick-nav{position:absolute;top:50%;transform:translateY(-50%);width:36px;height:36px;border-radius:50%;border:1px solid var(--borda);background:var(--bg-card);color:var(--azul);cursor:pointer;display:none;align-items:center;justify-content:center;box-shadow:var(--sombra-md);z-index:5;transition:background var(--transition),color var(--transition)}
.quick-nav:hover{background:var(--azul);color:#fff}
.quick-nav svg{width:16px;height:16px}
.quick-nav.prev{left:.5rem}
.quick-nav.next{right:.5rem}
.quick-nav[disabled]{opacity:.35;cursor:default;pointer-events:none}
@media(min-width:1024px){.quick-nav{display:flex}}

/* SMART (Visto + Mais Lidos + Salvos) */
.smart-section{background:var(--bg-card);border-radius:var(--radius);padding:1.25rem 1.5rem 1.5rem;margin:2rem 0;box-shadow:var(--sombra);border:1px solid var(--borda)}
.smart-section-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;flex-wrap:wrap;gap:.5rem}
.smart-section-hdr h2{font-family:var(--font-heading);font-size:1.1rem;font-weight:800;color:var(--titulo);display:flex;align-items:center;gap:.5rem}
.smart-section-hdr .clear-history{font-size:.74rem;color:var(--texto-muted);background:none;border:1px solid var(--borda);padding:.4rem .75rem;border-radius:50px;cursor:pointer;min-height:36px}
.smart-section-hdr .clear-history:hover{color:#b91c1c;border-color:#fecaca}
.smart-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:.85rem}
.smart-card{display:block;text-decoration:none;color:var(--texto);background:var(--bg);border:1px solid var(--borda);border-radius:var(--radius-sm);overflow:hidden;transition:transform var(--transition),box-shadow var(--transition);position:relative}
.smart-card:hover{transform:translateY(-2px);box-shadow:var(--sombra-md)}
.smart-card-img{aspect-ratio:16/9;background:var(--borda);overflow:hidden}
.smart-card-img img{width:100%;height:100%;object-fit:cover}
.smart-card-body{padding:.55rem .65rem .7rem}
.smart-card-cat{display:inline-block;font-size:.6rem;font-weight:700;color:#fff;text-transform:uppercase;letter-spacing:.04em;padding:.1rem .45rem;border-radius:3px;margin-bottom:.35rem}
.smart-card h4{font-size:.8rem;font-weight:600;line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0;color:var(--texto)}
.smart-card-rank{position:absolute;top:.4rem;left:.4rem;width:24px;height:24px;background:linear-gradient(135deg,#f97316,#dc2626);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;box-shadow:0 2px 6px rgba(0,0,0,.2);z-index:2}
.smart-card-saved-icon{position:absolute;top:.4rem;right:.4rem;width:24px;height:24px;background:linear-gradient(135deg,var(--azul-claro),var(--azul));color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.85rem;box-shadow:0 2px 6px rgba(0,0,0,.2);z-index:2}
@media(max-width:1024px){.smart-grid{grid-template-columns:repeat(4,1fr)}}
@media(max-width:768px){.smart-grid{grid-template-columns:repeat(3,1fr)}}
@media(max-width:480px){.smart-grid{grid-template-columns:repeat(2,1fr);gap:.6rem}}

/* NEWSLETTER */
.newsletter{background:linear-gradient(135deg,var(--azul-escuro) 0%,var(--azul) 100%);border-radius:var(--radius);padding:2.5rem;text-align:center;color:#fff;margin:2rem 0;position:relative;overflow:hidden;box-shadow:var(--sombra-lg)}
.newsletter::before{content:'';position:absolute;top:-50%;right:-20%;width:300px;height:300px;background:radial-gradient(circle,rgba(255,255,255,.08) 0%,transparent 70%);pointer-events:none}
.newsletter h2{font-family:var(--font-heading);font-size:1.5rem;font-weight:900;margin-bottom:.4rem;color:#fff}
.newsletter p{color:rgba(255,255,255,.9);margin-bottom:1.2rem;font-size:.95rem}
.newsletter-form{max-width:500px;margin:0 auto;position:relative}
.newsletter-row{display:flex;gap:0;margin-bottom:.6rem}
.newsletter-row input[type="email"]{flex:1;padding:.85rem 1.1rem;border:none;border-radius:var(--radius) 0 0 var(--radius);font-size:16px;outline:none;min-width:0;color:#1e293b;background:#fff}
.newsletter-row button{padding:.85rem 1.5rem;background:var(--laranja-claro);color:#fff;border:none;border-radius:0 var(--radius) var(--radius) 0;font-weight:800;font-size:.95rem;cursor:pointer;white-space:nowrap;min-height:48px}
.newsletter-row button:hover{background:var(--laranja)}
.captcha-row{display:flex;align-items:center;justify-content:center;gap:.5rem;flex-wrap:wrap}
.captcha-label{font-size:.85rem;color:rgba(255,255,255,.95);font-weight:600;white-space:nowrap}
.captcha-input{width:80px;padding:.5rem .6rem;border:2px solid rgba(255,255,255,.3);border-radius:var(--radius-sm);background:rgba(255,255,255,.15);color:#fff;font-size:1rem;font-weight:700;text-align:center;outline:none;min-height:44px}
.captcha-input:focus{border-color:#fff;background:rgba(255,255,255,.25)}
.captcha-refresh{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:1.2rem;padding:6px;line-height:1;min-height:44px;min-width:44px}
.captcha-refresh:hover{color:#fff}
.captcha-error{color:#fca5a5;font-size:.8rem;margin-top:.3rem;display:none}
.newsletter-success{color:#86efac;font-size:.9rem;font-weight:600;margin-top:.5rem;display:none}

/* INFINITE SCROLL */
#scroll-sentinel{width:100%;height:4px}
.loader{text-align:center;padding:2rem;display:none}
.spinner{width:32px;height:32px;border:3px solid var(--borda);border-top-color:var(--azul);border-radius:50%;animation:spin .6s linear infinite;margin:0 auto}
@keyframes spin{to{transform:rotate(360deg)}}
.no-more{display:none;text-align:center;padding:2rem;color:var(--texto-sec);font-size:.9rem}

/* ============== SINGLE POST ============== */
.post-article{background:var(--bg-card);border-radius:var(--radius);padding:2rem;margin:1.5rem 0;border:1px solid var(--borda);box-shadow:var(--sombra)}
.post-meta-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1rem}
.post-title{font-family:var(--font-heading);font-size:2.2rem;font-weight:900;line-height:1.2;color:var(--titulo);margin:0 0 1rem}
.post-meta{display:flex;align-items:center;gap:.7rem;color:var(--texto-sec);font-size:.88rem;margin-bottom:1.5rem;flex-wrap:wrap}
.post-meta time{font-weight:500}
.post-meta .author{font-weight:600;color:var(--azul)}
.post-meta .meta-dot{opacity:.4}
.post-meta .modified-date{color:var(--laranja-claro);font-weight:600;display:inline-flex;align-items:center;gap:.25rem}
.post-meta .modified-date svg{width:13px;height:13px}
.post-thumbnail{margin:0 0 1.5rem;padding:0}
.post-thumbnail img{width:100%;aspect-ratio:16/9;object-fit:cover;display:block;border-radius:var(--radius-sm);background:var(--borda)}
.post-thumbnail-caption{font-size:.85rem;color:var(--texto-sec);line-height:1.55;font-style:italic;margin-top:.6rem;padding:.6rem .9rem .6rem 1rem;background:var(--bg);border-left:3px solid var(--azul);border-radius:0 var(--radius-sm) var(--radius-sm) 0;text-align:left}
.post-thumbnail-caption a{color:var(--azul);text-decoration:underline;text-underline-offset:2px}

/* TABLE OF CONTENTS (auto) */
.post-toc{background:linear-gradient(135deg,var(--azul-soft) 0%,var(--bg) 100%);border:1px solid var(--borda);border-left:4px solid var(--azul);padding:1.1rem 1.3rem;margin:1.5rem 0;border-radius:0 var(--radius) var(--radius) 0}
.post-toc-title{font-weight:800;color:var(--titulo);font-size:.95rem;margin-bottom:.7rem;display:flex;align-items:center;gap:.5rem;font-family:var(--font-heading)}
.post-toc-title svg{width:20px;height:20px;color:var(--azul)}
.post-toc-toggle{display:none;margin-left:auto;background:none;border:none;color:var(--azul);cursor:pointer;font-size:.85rem;font-weight:600;padding:.25rem .5rem;min-height:30px}
.toc-list{list-style:none;margin:0;padding:0;counter-reset:toc-counter}
.toc-item{margin:.15rem 0;counter-increment:toc-counter}
.toc-h3{padding-left:1.2rem}
.toc-list a{color:var(--texto-sec);font-size:.88rem;text-decoration:none;display:block;padding:.4rem .6rem;border-left:2px solid transparent;transition:all var(--transition);border-radius:0 var(--radius-sm) var(--radius-sm) 0;line-height:1.4}
.toc-list a::before{content:counter(toc-counter) '. ';font-weight:700;color:var(--azul);opacity:.6;display:none}
.toc-h2 > a::before{display:inline}
.toc-list a:hover{color:var(--azul);background:var(--bg-card);border-color:var(--azul-claro)}
.toc-list a.active{color:var(--azul);font-weight:700;background:var(--bg-card);border-color:var(--azul);box-shadow:var(--sombra)}

/* SHARE BAR + Save button */
.post-share-bar{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;background:var(--bg);border:1px solid var(--borda);border-radius:var(--radius);padding:.85rem 1.1rem;margin:0 0 1.5rem}
.share-label{font-size:.85rem;font-weight:700;color:var(--texto-sec);margin-right:.3rem;text-transform:uppercase;letter-spacing:.05em}
.share-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem .9rem;border-radius:50px;font-size:.85rem;font-weight:700;text-decoration:none;border:1.5px solid;transition:all var(--transition);min-height:40px;cursor:pointer;background:var(--bg-card);font-family:inherit}
.share-btn svg{width:16px;height:16px}
.share-wa{color:#25d366;border-color:#25d366}
.share-wa:hover{background:#25d366;color:#fff}
.share-tg{color:#0088cc;border-color:#0088cc}
.share-tg:hover{background:#0088cc;color:#fff}
.share-tw{color:#0f172a;border-color:#0f172a}
.share-tw:hover{background:#0f172a;color:#fff}
.share-copy{color:var(--azul);border-color:var(--azul)}
.share-copy:hover{background:var(--azul);color:#fff}
.share-save{color:var(--laranja);border-color:var(--laranja);margin-left:auto}
.share-save:hover{background:var(--laranja);color:#fff}
.share-save.saved{background:var(--laranja);color:#fff}
.share-save.saved svg{fill:#fff}

/* Conteúdo */
.post-content{font-size:1.06rem;line-height:1.8;color:var(--texto)}
.post-content h2{font-family:var(--font-heading);font-size:1.55rem;font-weight:900;color:var(--titulo);margin:2.2rem 0 1rem;padding-bottom:.4rem;border-bottom:2px solid var(--azul);line-height:1.3;scroll-margin-top:120px}
.post-content h3{font-size:1.25rem;font-weight:800;color:var(--titulo);margin:1.6rem 0 .8rem;line-height:1.35;scroll-margin-top:120px}
.post-content h4{font-size:1.1rem;font-weight:700;color:var(--titulo);margin:1.4rem 0 .7rem}
.post-content p{margin:0 0 1.2rem}
.post-content a{color:var(--azul);text-decoration:underline;text-underline-offset:3px;text-decoration-thickness:1px}
.post-content a:hover{color:var(--azul-claro);text-decoration-thickness:2px}
.post-content ul,.post-content ol{margin:0 0 1.2rem 1.5rem;padding:0}
.post-content li{margin:0 0 .5rem}
.post-content li::marker{color:var(--azul)}
.post-content blockquote{border-left:4px solid var(--azul);background:var(--bg);padding:1rem 1.5rem;margin:1.5rem 0;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-style:italic;color:var(--texto-sec)}
.post-content img{max-width:100%;height:auto;border-radius:var(--radius-sm);margin:1rem 0}
.post-content figure{margin:1.5rem 0}
.post-content figcaption{text-align:center;font-size:.85rem;color:var(--texto-muted);margin-top:.5rem;font-style:italic}
.post-content table{border-collapse:collapse;width:100%;margin:1.5rem 0;background:var(--bg);font-size:.95rem;display:block;overflow-x:auto}
.post-content th,.post-content td{border:1px solid var(--borda);padding:.7rem 1rem;text-align:left}
.post-content th{background:var(--azul);color:#fff;font-weight:700}
.post-content tr:nth-child(even) td{background:var(--bg-card)}
.post-content code{background:var(--bg);padding:2px 6px;border-radius:3px;font-size:.95em;color:var(--azul-escuro);font-family:'SF Mono',Menlo,monospace}
.post-content pre{background:var(--titulo);color:var(--bg-card);padding:1rem 1.2rem;border-radius:var(--radius-sm);overflow-x:auto;margin:1.2rem 0;font-size:.9rem}
.post-content hr{border:none;border-top:2px solid var(--borda);margin:2rem 0}

/* Details/Summary acordeão */
.post-content details{background:var(--bg-card);border:1px solid var(--borda);border-radius:var(--radius-sm);margin:1.2rem 0;overflow:hidden;box-shadow:var(--sombra)}
.post-content details summary{padding:1rem 3rem 1rem 1.2rem;cursor:pointer;font-weight:700;color:var(--titulo);position:relative;list-style:none;background:linear-gradient(135deg,var(--bg) 0%,transparent 100%);transition:background var(--transition);user-select:none;font-size:1rem}
.post-content details summary::-webkit-details-marker{display:none}
.post-content details summary::after{content:'';position:absolute;right:1.4rem;top:50%;width:9px;height:9px;border-right:2.5px solid var(--azul);border-bottom:2.5px solid var(--azul);transform:translateY(-75%) rotate(45deg);transition:transform .25s ease}
.post-content details[open] summary::after{transform:translateY(-25%) rotate(225deg)}
.post-content details summary:hover{background:var(--bg);color:var(--azul)}
.post-content details[open] summary{border-bottom:1px solid var(--borda);background:var(--bg)}
.post-content details>:not(summary){padding:1rem 1.2rem}

/* Tags + Author Box */
.post-tags{margin-top:2rem;padding-top:1.5rem;border-top:1px solid var(--borda);display:flex;align-items:center;gap:.5rem;flex-wrap:wrap}
.post-tags .label{font-size:.85rem;color:var(--texto-sec);font-weight:600}
.post-tags a{font-size:.78rem;color:var(--texto);background:var(--bg);border:1px solid var(--borda);padding:.35rem .8rem;border-radius:50px;font-weight:500;transition:all var(--transition);min-height:32px;display:inline-flex;align-items:center}
.post-tags a:hover{background:var(--azul);color:#fff;border-color:var(--azul)}

.author-box{display:grid;grid-template-columns:auto 1fr;gap:1.2rem;align-items:flex-start;background:var(--bg-card);border:1px solid var(--borda);border-radius:var(--radius);padding:1.5rem;margin:2rem 0;box-shadow:var(--sombra)}
.author-avatar{flex-shrink:0}
.author-avatar img{width:88px;height:88px;border-radius:50%;border:3px solid var(--azul);display:block}
.author-info{min-width:0}
.author-label{font-size:.7rem;font-weight:700;color:var(--azul);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.3rem;display:block}
.author-name{font-family:var(--font-heading);font-size:1.2rem;font-weight:800;color:var(--titulo);margin:0 0 .5rem;line-height:1.3}
.author-bio{font-size:.92rem;color:var(--texto-sec);line-height:1.6;margin:0 0 .75rem}
.author-archive-link{font-size:.85rem;font-weight:600;color:var(--azul);display:inline-flex;align-items:center;gap:.3rem}
.author-archive-link:hover{color:var(--azul-escuro);gap:.5rem}

.related-section,.continue-section{margin:2.5rem 0}

/* NEXT POST SLIDE-IN */
.next-post-slide{position:fixed;bottom:1rem;left:50%;transform:translate(-50%,calc(100% + 2rem));max-width:520px;width:calc(100vw - 2rem);background:var(--bg-card);border:1px solid var(--borda);border-radius:var(--radius);box-shadow:var(--sombra-lg);padding:.9rem 1rem;display:flex;align-items:center;gap:.8rem;z-index:1100;transition:transform .35s ease;text-decoration:none;color:var(--texto)}
.next-post-slide.show{transform:translate(-50%,0)}
.next-post-slide-img{width:60px;height:60px;border-radius:var(--radius-sm);background:var(--borda);overflow:hidden;flex-shrink:0}
.next-post-slide-img img{width:100%;height:100%;object-fit:cover}
.next-post-slide-body{flex:1;min-width:0}
.next-post-slide-label{font-size:.65rem;font-weight:700;color:var(--azul);text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.15rem}
.next-post-slide-title{font-size:.88rem;font-weight:700;color:var(--texto);line-height:1.35;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;margin:0}
.next-dismiss{width:32px;height:32px;border:none;background:transparent;color:var(--texto-muted);cursor:pointer;font-size:1.4rem;line-height:1;flex-shrink:0;border-radius:50%;display:flex;align-items:center;justify-content:center}
.next-dismiss:hover{background:var(--bg);color:var(--texto)}

/* Comments */
.comments-area{background:var(--bg-card);border-radius:var(--radius);padding:1.5rem;margin:2rem 0;border:1px solid var(--borda);box-shadow:var(--sombra)}
.comments-title{font-family:var(--font-heading);font-size:1.2rem;font-weight:800;color:var(--titulo);margin-bottom:1rem;padding-bottom:.5rem;border-bottom:2px solid var(--azul)}

/* Archive */
.archive-header{background:var(--bg-card);border-radius:var(--radius);padding:1.8rem 2rem;margin:1.5rem 0;border:1px solid var(--borda);border-left:6px solid var(--azul);box-shadow:var(--sombra)}
.archive-header-text h1{font-family:var(--font-heading);font-size:2rem;font-weight:900;color:var(--titulo);line-height:1.2;margin:0 0 .6rem}
.archive-desc{font-size:.95rem;color:var(--texto-sec);line-height:1.6;margin-bottom:.6rem}
.archive-meta{display:flex;align-items:center;gap:.7rem;font-size:.85rem;color:var(--texto-muted);font-weight:600;flex-wrap:wrap}
.archive-meta .dot{opacity:.4}
.no-posts{text-align:center;padding:4rem 1rem;color:var(--texto-muted);font-size:1rem}

.pagination{display:flex;justify-content:center;align-items:center;gap:.4rem;margin:2rem 0;flex-wrap:wrap}
.pagination .page-numbers{display:inline-flex;align-items:center;justify-content:center;min-width:44px;height:44px;padding:0 .9rem;border:1.5px solid var(--borda);background:var(--bg-card);color:var(--texto);border-radius:var(--radius-sm);font-weight:600;text-decoration:none;font-size:.9rem;transition:all var(--transition)}
.pagination .page-numbers:hover{background:var(--azul);color:#fff;border-color:var(--azul)}
.pagination .page-numbers.current{background:var(--azul);color:#fff;border-color:var(--azul)}
.pagination .page-numbers.dots{border:none;background:transparent}

/* Toast */
.toast{position:fixed;bottom:1.5rem;right:1.5rem;background:var(--titulo);color:#fff;padding:.8rem 1.3rem;border-radius:var(--radius);font-size:.88rem;font-weight:600;box-shadow:var(--sombra-lg);opacity:0;transform:translateY(8px);transition:opacity .3s,transform .3s;pointer-events:none;z-index:1200;max-width:calc(100vw - 3rem)}
.toast.show{opacity:1;transform:translateY(0)}

/* Footer */
.site-disclaimer{background:var(--bg-card);border-top:3px solid var(--azul);margin-top:2.5rem;padding:1.2rem 0}
.site-disclaimer p{max-width:var(--max-w);margin:0 auto;padding:0 1.5rem;font-size:.84rem;color:var(--texto-sec);line-height:1.6}
.site-disclaimer strong{color:var(--texto);font-weight:700}
.site-footer{background:var(--azul-escuro);color:rgba(255,255,255,.78);padding:3rem 0 0;margin-top:auto}
.footer-grid{max-width:var(--max-w);margin:0 auto;padding:0 1.5rem;display:grid;grid-template-columns:1.3fr repeat(3,1fr);gap:2.5rem}
.footer-col .footer-heading{color:#fff;font-family:var(--font-heading);font-size:.95rem;font-weight:700;margin-bottom:.8rem;padding-bottom:.4rem;border-bottom:2px solid var(--azul-claro)}
.footer-col p{font-size:.85rem;line-height:1.6}
.footer-col ul{list-style:none}
.footer-col li{margin-bottom:.4rem}
.footer-col a{color:rgba(255,255,255,.78);font-size:.88rem;display:inline-flex;align-items:center;gap:.4rem}
.footer-col a:hover{color:var(--azul-claro)}
.footer-col a svg{width:16px;height:16px}
.footer-bottom{max-width:var(--max-w);margin:2rem auto 0;padding:1.2rem 1.5rem;border-top:1px solid rgba(255,255,255,.1);text-align:center;font-size:.78rem}
.footer-bottom a{color:var(--azul-claro)}

/* Responsive */
@media(max-width:1024px){
    .cards-grid{grid-template-columns:repeat(2,1fr)}
    .footer-grid{grid-template-columns:repeat(2,1fr)}
    .hero-main h1{font-size:1.4rem}
    .header-inner{column-gap:.5rem}
    .nav-cat-link{padding:.6rem .7rem;font-size:.72rem}
    .post-title{font-size:1.85rem}
}
@media(max-width:1023px){
    .header-inner{display:flex !important;flex-wrap:wrap;align-items:center;padding:.6rem 1rem;row-gap:.5rem;column-gap:.5rem}
    .site-logo{flex:1;justify-self:start}
    .menu-toggle{display:flex}
    .site-search{order:3;width:100%;max-width:none;border-radius:var(--radius-sm)}
    .site-search input{padding:.65rem 1rem}
    .nav-wrap{display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:var(--overlay);z-index:9998;justify-content:flex-end;align-items:stretch;margin:0;padding:0;width:100%;border:none;order:4}
    .nav-wrap.open{display:flex}
    .nav-cats{background:var(--bg-card);width:300px;max-width:85vw;padding:1.5rem;overflow-y:auto;flex-direction:column;justify-content:flex-start;gap:.3rem;box-shadow:-5px 0 25px rgba(0,0,0,.15);animation:slideIn .25s ease}
    @keyframes slideIn{from{transform:translateX(100%)}to{transform:translateX(0)}}
    .nav-cats-close{display:block;align-self:flex-end;background:none;border:none;cursor:pointer;padding:.5rem 1rem;color:var(--texto);font-size:1.6rem;font-weight:700;margin-bottom:.5rem;line-height:1;min-height:44px;min-width:44px}
    .nav-cats li{display:block;width:100%}
    .nav-cat-link{display:block;padding:.85rem;border-radius:var(--radius-sm);font-size:.95rem;width:100%;text-align:left;border:1px solid var(--borda);text-transform:none;letter-spacing:0;border-bottom:1px solid var(--borda);min-height:48px}
    .nav-cat-link:hover,.nav-cat-link:focus{background:var(--azul);color:#fff;border-color:var(--azul)}
    /* dropdown mobile (accordion): regras em style.css */
    /* TOC mobile colapsado */
    .post-toc-toggle{display:inline-flex;align-items:center;gap:.3rem}
    .post-toc[data-collapsed="1"] .toc-list{display:none}
    .post-toc-title{justify-content:space-between}
}
@media(max-width:768px){
    .hero{grid-template-columns:1fr}
    .hero-main h1{font-size:1.35rem}
    .hero-main-body{padding:1.2rem}
    .hero-main-img{margin:0 1.2rem}
    .hero-main-footer{padding:.5rem 1.2rem 1rem}
    .hero-sidebar{grid-template-columns:repeat(2,1fr)}
    .footer-grid{grid-template-columns:1fr 1fr;gap:1.5rem}
    .newsletter{padding:2rem 1.5rem}
    .newsletter h2{font-size:1.3rem}
    .post-article{padding:1.5rem 1.2rem}
    .post-title{font-size:1.6rem}
    .post-content{font-size:1rem;line-height:1.7}
    .post-content h2{font-size:1.35rem;margin-top:1.8rem}
    .post-content h3{font-size:1.15rem}
    .archive-header{padding:1.4rem 1.2rem}
    .archive-header-text h1{font-size:1.6rem}
    .author-box{grid-template-columns:1fr;text-align:center}
    .author-avatar{justify-self:center}
}
@media(max-width:480px){
    .container{padding:0 1rem}
    .hero-sidebar{grid-template-columns:1fr 1fr;gap:.7rem}
    .hero-card-body h3{font-size:.8rem}
    .hero-card-excerpt{display:none}
    .cards-grid{grid-template-columns:1fr;gap:.85rem}
    .card{display:grid;grid-template-columns:115px 1fr;grid-template-areas:"img body" "img footer";gap:0;overflow:hidden;align-items:stretch}
    .card-img{grid-area:img;width:115px;height:100%;min-height:130px;aspect-ratio:auto;margin:0;border-radius:0}
    .card-body{grid-area:body;padding:.65rem .85rem .25rem;min-width:0;display:flex;flex-direction:column;gap:.3rem;overflow:hidden}
    .card-meta-row{flex-wrap:nowrap;overflow:hidden;gap:.25rem;margin-bottom:.1rem}
    .card-meta-row .cat-badge{font-size:.56rem;padding:.14rem .42rem;flex-shrink:0;white-space:nowrap;text-overflow:ellipsis;overflow:hidden;max-width:55%}
    .card-meta-row .new-badge,.card-meta-row .hot-badge{font-size:.56rem;padding:.14rem .4rem;flex-shrink:0;white-space:nowrap}
    .card-body h3{font-size:.94rem;line-height:1.3;display:block;overflow:visible;-webkit-line-clamp:unset;margin:0;word-break:normal;overflow-wrap:break-word}
    .card-excerpt{-webkit-line-clamp:1;font-size:.76rem;color:var(--texto-muted);margin:0}
    .card-footer{grid-area:footer;display:flex;align-items:center;gap:.35rem;padding:.2rem .85rem .65rem;border-top:none;font-size:.7rem;flex-wrap:nowrap}
    .card-share{top:.3rem;right:.3rem;width:32px;height:32px}
    .card-share svg{width:14px;height:14px}
    .footer-grid{grid-template-columns:1fr;gap:1.5rem}
    .newsletter-row{flex-direction:column;gap:.5rem}
    .newsletter-row input[type="email"],.newsletter-row button{border-radius:var(--radius-sm)}
    .toast{bottom:1rem;right:1rem;left:1rem;text-align:center;font-size:.85rem}
    .post-article{padding:1.2rem 1rem;margin:1rem 0}
    .post-title{font-size:1.4rem}
    .post-content{font-size:.98rem}
    .post-content h2{font-size:1.25rem;margin-top:1.6rem}
    .archive-header{padding:1.2rem 1rem}
    .archive-header-text h1{font-size:1.45rem}
    .post-share-bar{padding:.7rem .8rem;gap:.4rem}
    .share-btn span{display:none}
    .share-btn{padding:.55rem;width:42px;height:42px;justify-content:center;gap:0}
    .share-label{margin-right:0;width:100%;text-align:center;margin-bottom:.4rem}
    .share-save{margin-left:0}
    .author-box{padding:1.2rem 1rem;gap:.8rem}
    .author-avatar img{width:72px;height:72px}
    .next-post-slide{padding:.7rem .8rem;gap:.6rem}
    .next-post-slide-img{width:50px;height:50px}
}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms !important;transition-duration:.01ms !important}}
/* ═════ EMERGENCY DROPDOWN — última regra do <head>, vence qualquer cascata stale ═════ */
.nav-cats .nav-subcats{display:none !important;position:absolute !important;top:100% !important;left:0 !important;min-width:240px;background:#fff !important;border:1px solid #e2e8f0;border-radius:8px;box-shadow:0 8px 28px rgba(0,0,0,.15);padding:.4rem 0;z-index:1000;list-style:none;margin:0;flex-direction:column !important;flex-wrap:nowrap !important}
.nav-cats li.has-children:hover>.nav-subcats,
.nav-cats li.has-children:focus-within>.nav-subcats,
.nav-cats li.has-children.open>.nav-subcats{display:block !important}
.nav-cats .nav-subcats>li{display:block !important;width:100% !important;flex:none !important;flex-shrink:initial !important;margin:0 !important;float:none !important}
.nav-cats .nav-subcats>li>a{display:block !important;padding:.5rem 1rem !important;width:100% !important;font-size:.85rem !important;color:#1f2937;text-decoration:none;text-transform:none !important;letter-spacing:0;font-weight:600;white-space:normal;line-height:1.35;min-height:auto !important;border:none !important;border-bottom:none !important;border-radius:0 !important;background:transparent !important}
.nav-cats .nav-subcats>li>a:hover{background:rgba(30,64,175,.08) !important;color:#1e40af !important}
.nav-cats .nav-subcats>li>a.nav-subcat-all{border-bottom:1px solid #e2e8f0 !important;margin-bottom:.25rem}
.nav-cats .nav-subcats>li>a.nav-subcat-all strong{color:#1e40af;font-size:.82rem;font-weight:700}
.nav-cats:hover{overflow:visible !important}
@media(max-width:1023px){
    .nav-cats .nav-subcats{position:static !important;box-shadow:none !important;border:none !important;background:transparent !important;padding:.25rem 0 .5rem .8rem !important;width:100% !important;min-width:0 !important}
    .nav-cats li.has-children:hover>.nav-subcats,
    .nav-cats li.has-children:focus-within>.nav-subcats{display:none !important}
    .nav-cats li.has-children.open>.nav-subcats{display:block !important}
}
</style>
</head>
<body <?php body_class(); ?>>
<a class="skip-link sr-only" href="#content">Pular para o conteúdo</a>
<div class="read-progress" id="readProgress" aria-hidden="true"></div>

<header class="site-header"><div class="header-inner">
<form class="site-search" role="search" method="get" action="<?php echo esc_url($site_url); ?>"><label for="sq" class="sr-only">Buscar</label><input type="search" id="sq" name="s" placeholder="Buscar cursos, ENEM, Sisu, ProUni..." autocomplete="off" value="<?php echo esc_attr(get_search_query()); ?>"><span class="search-kbd" aria-hidden="true">/</span><button type="submit" aria-label="Buscar"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></button></form>
<a href="<?php echo esc_url($site_url); ?>" class="site-logo">
    <?php echo gdc_logo_svg(46); ?>
    <span class="site-logo-text"><span class="site-logo-name"><?php echo esc_html($site_name); ?></span><span class="site-logo-tagline">Cursos · Carreira · Educação</span></span>
</a>
<button class="menu-toggle" id="menuToggle" aria-label="Abrir menu" aria-expanded="false"><span></span><span></span><span></span></button>
<nav class="nav-wrap" id="navWrap" aria-label="Categorias">
<ul class="nav-cats" id="navCats">
<li style="display:none" class="close-li"><button class="nav-cats-close" id="navClose" aria-label="Fechar menu">&times;</button></li>
<?php foreach ($nav_cats as $c): $is_hot = in_array((int)$c->term_id, $trending_cats, true); $has_children = !empty($c->children); ?>
<li class="<?php echo $has_children ? 'has-children' : ''; ?>">
  <a href="<?php echo esc_url(get_category_link($c->term_id)); ?>" class="nav-cat-link" data-cat-slug="<?php echo esc_attr($c->slug); ?>"<?php if($has_children): ?> aria-haspopup="true" aria-expanded="false"<?php endif; ?>><?php echo esc_html(gdc_silo_short_name($c->name)); ?><?php if ($is_hot): ?> <span class="hot-dot" aria-label="em alta"></span><?php endif; ?><?php if ($has_children): ?> <span class="nav-chevron" aria-hidden="true">▾</span><?php endif; ?></a>
  <?php if ($has_children): ?>
    <ul class="nav-subcats" aria-label="Subcategorias de <?php echo esc_attr($c->name); ?>">
      <li><a href="<?php echo esc_url(get_category_link($c->term_id)); ?>" class="nav-subcat-all"><strong>Ver todos os posts de <?php echo esc_html(gdc_silo_short_name($c->name)); ?> →</strong></a></li>
      <?php foreach ($c->children as $sub): ?>
        <li><a href="<?php echo esc_url(get_category_link($sub->term_id)); ?>"><?php echo esc_html($sub->name); ?></a></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</nav>
</div></header>
<script>
(function(){
  // BUG bfcache: quando o usuário clica num post e volta com Back, o navegador restaura o
  // DOM inteiro (incluindo classes .open, .has-active-child) → menu fica "preso" no estado
  // anterior. pageshow com event.persisted === true detecta o restore e zera tudo.
  function resetMenuState(){
    document.querySelectorAll('.nav-cats li.has-children.open').forEach(function(li){ li.classList.remove('open'); });
    document.querySelectorAll('.nav-cats li.has-children > .nav-cat-link[aria-expanded="true"]').forEach(function(a){ a.setAttribute('aria-expanded','false'); });
    var nc = document.querySelector('.nav-cats'); if (nc) nc.classList.remove('has-active-child');
    var nw = document.getElementById('navWrap'); if (nw) nw.classList.remove('open');
    var mt = document.getElementById('menuToggle'); if (mt){ mt.classList.remove('active'); mt.setAttribute('aria-expanded','false'); }
    document.body.style.overflow = '';
  }
  window.addEventListener('pageshow', function(e){ if (e.persisted) resetMenuState(); });

  // Fallback pra navegadores sem :has() — adiciona/remove .has-active-child em .nav-cats
  // no hover/focus dos itens com filhos, pra liberar overflow.
  var navCats = document.querySelector('.nav-cats');
  if (navCats) {
    document.querySelectorAll('.nav-cats li.has-children').forEach(function(li){
      li.addEventListener('mouseenter', function(){ navCats.classList.add('has-active-child'); });
      li.addEventListener('mouseleave', function(){ navCats.classList.remove('has-active-child'); });
      li.addEventListener('focusin',    function(){ navCats.classList.add('has-active-child'); });
      li.addEventListener('focusout',   function(){ if(!li.matches(':focus-within')) navCats.classList.remove('has-active-child'); });
    });
  }
  // Mobile: o link do silo-pai SEMPRE toggle (abre/fecha) o dropdown — nunca navega.
  // Pra ir pro arquivo do silo no mobile, o usuário toca em "Ver todos os posts de X →"
  // dentro do dropdown. Sub-categorias (.nav-subcats a) navegam normal.
  // stopImmediatePropagation cancela o cN() do footer.php que fechava o painel inteiro.
  document.querySelectorAll('.nav-cats li.has-children > .nav-cat-link').forEach(function(a){
    a.addEventListener('click', function(e){
      if (window.matchMedia('(max-width: 1023px)').matches) {
        e.preventDefault();
        e.stopImmediatePropagation();
        var li = a.parentElement;
        var isOpen = li.classList.contains('open');
        if (isOpen) {
          // 2º toque: FECHA
          li.classList.remove('open');
          a.setAttribute('aria-expanded','false');
        } else {
          // 1º toque: ABRE (e fecha outros)
          document.querySelectorAll('.nav-cats li.has-children.open').forEach(function(o){ if(o!==li){ o.classList.remove('open'); var aa=o.querySelector('.nav-cat-link'); if(aa)aa.setAttribute('aria-expanded','false'); } });
          li.classList.add('open');
          a.setAttribute('aria-expanded','true');
        }
      }
    }, true); // capture phase
  });
})();
</script>
