<?php
/**
 * Astra Child — Cursos SENAC Gratuito
 * functions.php — helpers + queries cacheadas + AJAX + JSON-LD + Core Web Vitals + Performance
 *
 * Prefixo: csg_* (Cursos Senac Gratuitos)
 * Cor primária: #ea580c (roxo)
 */
if (!defined('ABSPATH')) exit;

/* Enqueue parent + child styles */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('astra-parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('astra-child-csg-style', get_stylesheet_uri(), ['astra-parent-style'], '1.0.0');
}, 15);

add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption']);
    add_image_size('csg_share', 1200, 630, true);
});

/* ============================================================ CORE WEB VITALS ============================================================ */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
});
add_action('init', function () {
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    wp_deregister_script('wp-embed');
}, 9999);
add_action('wp_default_scripts', function ($scripts) {
    if (!is_admin() && isset($scripts->registered['jquery'])) {
        $script = $scripts->registered['jquery'];
        if ($script->deps) $script->deps = array_diff($script->deps, ['jquery-migrate']);
    }
});
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wp_shortlink_wp_head');
add_filter('xmlrpc_enabled', '__return_false');
add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });
add_filter('wp_resource_hints', function ($urls, $type) {
    if ($type === 'dns-prefetch') {
        $urls[] = 'https://fonts.googleapis.com';
        $urls[] = 'https://fonts.gstatic.com';
    }
    return $urls;
}, 10, 2);
add_filter('the_content', function ($content) {
    if (is_singular()) {
        $content = preg_replace_callback('/<img(?![^>]*\b(?:width|height)=)([^>]+)>/i', function ($m) {
            return '<img loading="lazy" decoding="async"' . $m[1] . '>';
        }, $content);
    }
    return $content;
}, 99);

/* ============================================================ PERFORMANCE — DEQUEUE/DEFER ============================================================ */
add_action('wp_enqueue_scripts', function () {
    wp_dequeue_style('wp-block-library');
    wp_dequeue_style('wp-block-library-theme');
    wp_dequeue_style('global-styles');
    wp_dequeue_style('classic-theme-styles');
    if (!is_user_logged_in()) {
        wp_dequeue_style('dashicons');
        wp_deregister_style('dashicons');
    }
}, 100);

add_filter('script_loader_tag', function ($tag, $handle) {
    static $defer_exact  = ['cookie-law-info', 'astra-theme-js', 'jquery-core', 'jquery'];
    static $defer_substr = ['complianz', 'cmplz', 'onesignal'];
    $match = in_array($handle, $defer_exact, true);
    if (!$match) {
        foreach ($defer_substr as $s) { if (stripos($handle, $s) !== false) { $match = true; break; } }
    }
    if ($match && strpos($tag, ' defer') === false && strpos($tag, ' async') === false) {
        return str_replace(' src=', ' defer src=', $tag);
    }
    return $tag;
}, 10, 2);

add_action('wp_default_scripts', function ($scripts) {
    if (is_admin()) return;
    foreach (['jquery-core', 'jquery-migrate', 'jquery'] as $h) {
        if (isset($scripts->registered[$h])) {
            $scripts->registered[$h]->extra['group'] = 1;
        }
    }
});

add_filter('style_loader_tag', function ($tag, $handle) {
    static $async = ['astra-theme-css', 'astra-parent-style', 'astra-child-csg-style', 'post-views-counter-frontend'];
    if (in_array($handle, $async, true)) {
        $tag = str_replace("rel='stylesheet'", "rel='preload' as='style' onload=\"this.onload=null;this.rel='stylesheet'\"", $tag);
    }
    return $tag;
}, 10, 2);

add_action('wp_print_styles', function () {
    global $wp_styles;
    if (isset($wp_styles->registered['astra-theme-css'])) {
        $wp_styles->registered['astra-theme-css']->extra['after'] = [];
    }
}, 100);

/**
 * GA4 lazy-load. IMPORTANTE: substituir 'G-XXXXXXXXXX' pelo Measurement ID real do GA4
 * do cursosenacgratuito antes de subir.
 */
add_action('wp_head', function () {
    if (is_admin()) return;
    $ga_id = 'G-XXXXXXXXXX'; // TODO: trocar pelo ID real do GA4 do cursosenacgratuito
    if ($ga_id === 'G-XXXXXXXXXX') return; // se não setado, não injeta
    ?>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','<?php echo esc_js($ga_id); ?>');
(function(){var L=false;function ld(){if(L)return;L=true;var s=document.createElement('script');s.async=true;s.src='https://www.googletagmanager.com/gtag/js?id=<?php echo esc_js($ga_id); ?>';document.head.appendChild(s);}var ev=['mousedown','keydown','touchstart'];ev.forEach(function(e){window.addEventListener(e,ld,{once:true,passive:true});});setTimeout(ld,10000);})();</script>
    <?php
}, 5);

/* ============================================================ HELPERS ============================================================ */
function csg_get_cat($pid){$c=wp_get_post_categories($pid,['fields'=>'names']);return!empty($c)?$c[0]:'Geral';}
function csg_get_cat_slug($pid){$c=wp_get_post_categories($pid,['fields'=>'all']);return!empty($c)?$c[0]->slug:'geral';}
function csg_get_cat_id($pid){$c=wp_get_post_categories($pid,['fields'=>'ids']);return!empty($c)?(int)$c[0]:0;}
function csg_time_ago($d){$diff=time()-strtotime($d);if($diff<3600)return max(1,intval($diff/60)).' min atrás';if($diff<86400)return intval($diff/3600).'h atrás';return date_i18n('d \d\e M, Y',strtotime($d));}
function csg_is_new($d){return (time()-strtotime($d))<86400;}
function csg_get_excerpt($post){$exc=is_object($post)?$post->post_excerpt:'';if(!$exc)$exc=wp_strip_all_tags(get_post_field('post_content',is_object($post)?$post->ID:$post));return wp_trim_words($exc,18,'…');}
function csg_reading_time($pid){$c=wp_strip_all_tags(get_post_field('post_content',$pid));$w=str_word_count($c);return max(1,(int)ceil($w/220));}

/**
 * Mapa de cores por silo educacional. Mantém esquema do gdc (cores por intent), mas a
 * cor primária do site é roxo. Cards/badges usam essa paleta por slug.
 */
function csg_cat_color($slug){
    static $exact=null;
    if($exact===null) $exact=[
        // SENAC / SENAI / SEBRAE — verde-esmeralda (cursos gratuitos)
        'cursos-senac'=>'#047857','senac'=>'#065f46','cursos-senai'=>'#047857','senai'=>'#047857','sebrae'=>'#0d9488','cursos-sebrae'=>'#0d9488','fundacao-bradesco'=>'#0e7490','sesc'=>'#065f46','funcef'=>'#047857','cursos-gratuitos'=>'#047857',
        // Certificados / Certificação
        'curso-com-certificado'=>'#c2410c','certificacao'=>'#1e3a8a','certificados'=>'#c2410c',
        // Profissionalizantes — roxo (cor primária do tema)
        'profissionalizantes'=>'#c2410c','informatica'=>'#1e3a8a','administracao'=>'#c2410c','rh'=>'#c2410c','programacao'=>'#9333ea','marketing-digital'=>'#7e22ce','design'=>'#1e3a8a','contabilidade'=>'#c2410c',
        // EAD/Online — turquesa
        'ead'=>'#155e75','online'=>'#155e75','curso-online'=>'#155e75','cursos-online'=>'#155e75','graduacao-ead'=>'#0e7490',
        // Idiomas — laranja
        'idiomas'=>'#c2410c','ingles'=>'#c2410c','espanhol'=>'#9a3412','libras'=>'#c2410c','frances'=>'#9a3412','japones'=>'#7c2d12',
        // Saúde — vermelho
        'saude'=>'#dc2626','enfermagem'=>'#b91c1c','cuidador'=>'#991b1b','tecnico-em-enfermagem'=>'#1e3a8a',
        // Carreira/Mercado — cinza-grafite
        'carreira'=>'#475569','mercado-trabalho'=>'#334155','dicas-carreira'=>'#475569','primeiro-emprego'=>'#64748b','curriculo'=>'#475569',
    ];
    $slug=strtolower((string)$slug);
    if(isset($exact[$slug]))return $exact[$slug];
    $part=['senac'=>'#065f46','senai'=>'#047857','sebrae'=>'#0d9488','gratuit'=>'#047857','certifica'=>'#c2410c','profissionaliz'=>'#c2410c','informatica'=>'#1e3a8a','administracao'=>'#c2410c','programacao'=>'#9333ea','marketing'=>'#7e22ce','design'=>'#1e3a8a','ead'=>'#155e75','online'=>'#155e75','idiom'=>'#c2410c','ingles'=>'#c2410c','espanhol'=>'#9a3412','libras'=>'#c2410c','saude'=>'#dc2626','enferm'=>'#b91c1c','carreira'=>'#475569','curriculo'=>'#475569','emprego'=>'#64748b','curso'=>'#c2410c'];
    foreach($part as $k=>$v){if(strpos($slug,$k)!==false)return $v;}
    $palette=['#c2410c','#047857','#155e75','#c2410c','#dc2626','#475569','#c2410c','#155e75'];
    return $palette[hexdec(substr(md5($slug),0,2))%count($palette)];
}

function csg_fallback_img(){return'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 225" fill="none"><rect width="400" height="225" fill="#e2e8f0"/><path d="M170 90l30 45h-60z M200 80a12 12 0 110 24 12 12 0 010-24z" fill="#94a3b8"/><text x="200" y="165" text-anchor="middle" fill="#64748b" font-size="13" font-family="sans-serif">Sem imagem</text></svg>');}

/* ============================================================ QUERIES CACHEADAS ============================================================ */
function csg_trending_cats(){
    $key='csg_trending_cats_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $r=$wpdb->get_col("SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON tr.object_id=p.ID WHERE tt.taxonomy='category' AND p.post_status='publish' AND p.post_type='post' AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY t.term_id HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC LIMIT 3");
    $r=array_map('intval',(array)$r);
    set_transient($key,$r,30*MINUTE_IN_SECONDS);
    return $r;
}
function csg_most_read_week(){
    $key='csg_most_read_week_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $r=$wpdb->get_results("SELECT p.ID, p.post_title, p.post_date, COALESCE(NULLIF(CAST(pm.meta_value AS UNSIGNED),0), p.comment_count) AS popularity FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='post_views_count' WHERE p.post_type='post' AND p.post_status='publish' AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY popularity DESC, p.post_date DESC LIMIT 6");
    if(empty($r)) $r=$wpdb->get_results("SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY comment_count DESC, post_date DESC LIMIT 6");
    set_transient($key,$r,HOUR_IN_SECONDS);
    return $r;
}
/** Alias curto de exibição pro menu. */
function csg_silo_short_name($name){
    static $map=[
        'Cursos Gratuitos com Certificado' => 'Cursos Gratuitos',
        'Cursos SENAC Gratuitos'           => 'SENAC',
        'Cursos SENAI Gratuitos'           => 'SENAI',
        'Cursos SEBRAE'                    => 'SEBRAE',
        'Profissionalizantes e Áreas'      => 'Profissionalizantes',
        'EAD e Especialização Online'      => 'EAD',
        'Cursos Online com Certificado'    => 'Online',
        'Carreira e Mercado'               => 'Carreira',
    ];
    return $map[$name] ?? $name;
}
function csg_nav_cats(){
    $key='csg_nav_cats_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $silos=$wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' AND tt.count>0 AND tt.parent=0 AND t.slug!='uncategorized' ORDER BY tt.count DESC LIMIT 8");
    foreach($silos as $silo){
        $silo->children=$wpdb->get_results($wpdb->prepare("SELECT t.term_id,t.name,t.slug,tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' AND tt.count>0 AND tt.parent=%d ORDER BY tt.count DESC LIMIT 10",(int)$silo->term_id));
    }
    set_transient($key,$silos,HOUR_IN_SECONDS);
    return $silos;
}
function csg_all_cats(){
    return get_categories(['orderby'=>'count','order'=>'DESC','hide_empty'=>true,'exclude'=>get_cat_ID('uncategorized'),'number'=>20]);
}

function csg_home_itemlist(){
    $key='csg_home_itemlist_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $posts=$wpdb->get_results("SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 8");
    $items=[];
    foreach($posts as $i=>$p){
        $url=get_permalink($p->ID);
        $img=get_the_post_thumbnail_url($p->ID,'large')?:get_the_post_thumbnail_url($p->ID,'full');
        $items[]=['@type'=>'ListItem','position'=>$i+1,'url'=>$url,'item'=>['@type'=>'NewsArticle','headline'=>wp_strip_all_tags($p->post_title),'image'=>$img?:'','datePublished'=>date('c',strtotime($p->post_date)),'mainEntityOfPage'=>$url]];
    }
    $jsonld=['@context'=>'https://schema.org','@type'=>'ItemList','itemListElement'=>$items];
    set_transient($key,$jsonld,15*MINUTE_IN_SECONDS);
    return $jsonld;
}

add_action('save_post_post', function(){
    delete_transient('csg_trending_cats_v1');
    delete_transient('csg_most_read_week_v1');
    delete_transient('csg_nav_cats_v1');
    delete_transient('csg_home_itemlist_v1');
});
add_action('edited_category', function(){ delete_transient('csg_nav_cats_v1'); });
add_action('created_category', function(){ delete_transient('csg_nav_cats_v1'); });
add_action('delete_category', function(){ delete_transient('csg_nav_cats_v1'); });

/* ============================================================ RENDER CARD ============================================================ */
function csg_render_card($p, $loading='lazy'){
    $tc=csg_trending_cats();
    $pt=get_the_post_thumbnail_url($p->ID,'medium');$pl=get_permalink($p->ID);$pc=csg_get_cat($p->ID);$pcsl=csg_get_cat_slug($p->ID);$pcid=csg_get_cat_id($p->ID);$exc=csg_get_excerpt($p);$rt=csg_reading_time($p->ID);$isNew=csg_is_new($p->post_date);$isHot=in_array($pcid,$tc,true);
    ?>
    <a href="<?php echo esc_url($pl);?>" class="card" data-pid="<?php echo (int)$p->ID;?>" data-cat="<?php echo esc_attr($pc);?>" data-cat-slug="<?php echo esc_attr($pcsl);?>" data-thumb="<?php echo esc_url($pt?:csg_fallback_img());?>" data-title="<?php echo esc_attr($p->post_title);?>"><div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:<?php echo esc_attr(csg_cat_color($pcsl));?>"><?php echo esc_html($pc);?></span><?php if($isNew):?><span class="new-badge">NOVO</span><?php endif;?><?php if($isHot):?><span class="hot-badge" title="Categoria em alta">EM ALTA</span><?php endif;?></div><h3><?php echo esc_html(wp_trim_words($p->post_title,14));?></h3><p class="card-excerpt"><?php echo esc_html($exc);?></p></div><div class="card-img"><img src="<?php echo esc_url($pt?:csg_fallback_img());?>" alt="<?php echo esc_attr(wp_trim_words($p->post_title,6));?>" width="400" height="225" loading="<?php echo esc_attr($loading);?>" decoding="async"><button type="button" class="card-share" aria-label="Compartilhar" data-share-url="<?php echo esc_url($pl);?>" data-share-title="<?php echo esc_attr($p->post_title);?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg></button></div><div class="card-footer"><time datetime="<?php echo esc_attr(date('c',strtotime($p->post_date)));?>"><?php echo esc_html(csg_time_ago($p->post_date));?></time><span class="reading-time">· <?php echo (int)$rt;?> min</span></div></a>
    <?php
}

/* ============================================================ AJAX ============================================================ */
add_action('wp_ajax_csg_load_posts','csg_ajax_load_posts');
add_action('wp_ajax_nopriv_csg_load_posts','csg_ajax_load_posts');
function csg_ajax_load_posts(){
    $paged=max(1,(int)($_GET['paged']??1));
    $per_page=max(1,min(20,(int)($_GET['per_page']??8)));
    $cat_id=(int)($_GET['cat']??0);
    $search=sanitize_text_field((string)($_GET['s']??''));
    $exclude=(int)($_GET['exclude']??0);
    $args=['post_type'=>'post','post_status'=>'publish','posts_per_page'=>$per_page,'paged'=>$paged];
    if($cat_id>0) $args['cat']=$cat_id;
    if($search!=='') $args['s']=$search;
    if($exclude>0) $args['post__not_in']=[$exclude];
    $q=new WP_Query($args);
    $tc=csg_trending_cats();
    $out=[];
    while($q->have_posts()){
        $q->the_post();
        $p=get_post();
        $thumb=get_the_post_thumbnail_url($p->ID,'medium');
        $cat=csg_get_cat($p->ID);
        $cat_slug=csg_get_cat_slug($p->ID);
        $cat_id_p=csg_get_cat_id($p->ID);
        $out[]=[
            'id'=>$p->ID,
            'title'=>get_the_title($p),
            'link'=>get_permalink($p),
            'excerpt'=>csg_get_excerpt($p),
            'date'=>date('c',strtotime($p->post_date)),
            'date_fmt'=>date_i18n('d \d\e M, Y',strtotime($p->post_date)),
            'thumb'=>$thumb?:csg_fallback_img(),
            'cat'=>$cat,
            'cat_slug'=>$cat_slug,
            'is_new'=>csg_is_new($p->post_date),
            'is_hot'=>in_array($cat_id_p,$tc,true),
            'reading_time'=>csg_reading_time($p->ID),
        ];
    }
    wp_reset_postdata();
    wp_send_json([
        'posts'=>$out,
        'max_pages'=>(int)$q->max_num_pages,
        'current_page'=>$paged,
    ]);
}

/* Manifest PWA */
function csg_pwa_manifest_uri(){
    $manifest=[
        'name'=>get_bloginfo('name'),
        'short_name'=>get_bloginfo('name'),
        'description'=>get_bloginfo('description')?:'Cursos gratuitos com certificado: SENAC, SENAI, SEBRAE, online.',
        'start_url'=>'/',
        'display'=>'standalone',
        'background_color'=>'#fff7ed',
        'theme_color'=>'#c2410c',
        'lang'=>'pt-BR',
        'icons'=>[
            ['src'=>get_site_icon_url(192) ?: home_url('/favicon.ico'),'sizes'=>'192x192','type'=>'image/png'],
            ['src'=>get_site_icon_url(512) ?: home_url('/favicon.ico'),'sizes'=>'512x512','type'=>'image/png'],
        ],
    ];
    return 'data:application/manifest+json;base64,'.base64_encode(wp_json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}

/**
 * Logo do site. Usa o PNG oficial uploaded no wp-content (cropped 270x270 do site icon — "S" SENAC).
 * Mantém o nome `csg_logo_svg` pra compat com o template; retorna <img>.
 */
function csg_logo_svg($width=46){
    $size=(int)$width;
    $logo_url = content_url('uploads/2025/12/cropped-cursosenac-270x270.png');
    return '<img src="'.esc_url($logo_url).'" width="'.$size.'" height="'.$size.'" alt="Cursos SENAC Gratuito" decoding="sync" loading="eager" style="border-radius:9px;display:block;box-shadow:0 2px 6px rgba(30,58,138,.25);flex-shrink:0">';
}
