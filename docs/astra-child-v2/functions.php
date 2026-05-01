<?php
/**
 * Astra Child — Vagas e Benefícios
 * functions.php — helpers + queries cacheadas + AJAX loader + JSON-LD
 */
if (!defined('ABSPATH')) exit;

/* Enqueue parent + child styles */
add_action('wp_enqueue_scripts', function () {
    wp_enqueue_style('astra-parent-style', get_template_directory_uri() . '/style.css');
    wp_enqueue_style('astra-child-style', get_stylesheet_uri(), ['astra-parent-style'], '1.2.0');
}, 15);

/* Image size 1200x630 para og:image / Discover */
add_action('after_setup_theme', function () {
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('automatic-feed-links');
    add_theme_support('html5', ['search-form','comment-form','comment-list','gallery','caption']);
    add_image_size('veb_share', 1200, 630, true);
});

/* ============================================================ CORE WEB VITALS ============================================================ */
/* Disable emojis */
add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_styles', 'print_emoji_styles');
    remove_filter('the_content_feed', 'wp_staticize_emoji');
    remove_filter('comment_text_rss', 'wp_staticize_emoji');
    remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
});

/* Disable embeds (saves wp-embed.min.js) */
add_action('init', function () {
    remove_action('wp_head', 'wp_oembed_add_discovery_links');
    remove_action('wp_head', 'wp_oembed_add_host_js');
    wp_deregister_script('wp-embed');
}, 9999);

/* Remove jQuery Migrate (saves ~10kb) */
add_action('wp_default_scripts', function ($scripts) {
    if (!is_admin() && isset($scripts->registered['jquery'])) {
        $script = $scripts->registered['jquery'];
        if ($script->deps) $script->deps = array_diff($script->deps, ['jquery-migrate']);
    }
});

/* Remove unnecessary head tags */
remove_action('wp_head', 'rsd_link');
remove_action('wp_head', 'wlwmanifest_link');
remove_action('wp_head', 'wp_generator');
remove_action('wp_head', 'wp_shortlink_wp_head');

/* Disable XML-RPC */
add_filter('xmlrpc_enabled', '__return_false');
add_filter('wp_headers', function ($h) { unset($h['X-Pingback']); return $h; });

/* DNS prefetch para fontes Google */
add_filter('wp_resource_hints', function ($urls, $type) {
    if ($type === 'dns-prefetch') {
        $urls[] = 'https://fonts.googleapis.com';
        $urls[] = 'https://fonts.gstatic.com';
    }
    return $urls;
}, 10, 2);

/* Adiciona width/height em imagens do conteúdo (CLS prevention) */
add_filter('the_content', function ($content) {
    if (is_singular()) {
        $content = preg_replace_callback('/<img(?![^>]*\b(?:width|height)=)([^>]+)>/i', function ($m) {
            return '<img loading="lazy" decoding="async"' . $m[1] . '>';
        }, $content);
    }
    return $content;
}, 99);

/* ============================================================ HELPERS ============================================================ */
function veb_get_cat($pid){$c=wp_get_post_categories($pid,['fields'=>'names']);return!empty($c)?$c[0]:'Geral';}
function veb_get_cat_slug($pid){$c=wp_get_post_categories($pid,['fields'=>'all']);return!empty($c)?$c[0]->slug:'geral';}
function veb_get_cat_id($pid){$c=wp_get_post_categories($pid,['fields'=>'ids']);return!empty($c)?(int)$c[0]:0;}
function veb_time_ago($d){$diff=time()-strtotime($d);if($diff<3600)return max(1,intval($diff/60)).' min atrás';if($diff<86400)return intval($diff/3600).'h atrás';return date_i18n('d \d\e M, Y',strtotime($d));}
function veb_is_new($d){return (time()-strtotime($d))<86400;}
function veb_get_excerpt($post){$exc=is_object($post)?$post->post_excerpt:'';if(!$exc)$exc=wp_strip_all_tags(get_post_field('post_content',is_object($post)?$post->ID:$post));return wp_trim_words($exc,18,'…');}
function veb_reading_time($pid){$c=wp_strip_all_tags(get_post_field('post_content',$pid));$w=str_word_count($c);return max(1,(int)ceil($w/220));}

function veb_cat_color($slug){
    static $exact=null;
    if($exact===null) $exact=[
        'inss-e-aposentadoria'=>'#1e40af','inss'=>'#1e40af','aposentadoria'=>'#1e3a8a','auxilio-doenca'=>'#1d4ed8','bpc-loas'=>'#2563eb','13-inss'=>'#1e40af','pensao'=>'#1e3a8a','salario-maternidade'=>'#3b82f6','meu-inss'=>'#1e40af',
        'beneficios-sociais'=>'#7c3aed','beneficios'=>'#7c3aed','bolsa-familia'=>'#6d28d9','cadunico'=>'#8b5cf6','pe-de-meia'=>'#a855f7','auxilio-gas'=>'#9333ea','minha-casa-minha-vida'=>'#7e22ce','seguro-desemprego'=>'#b91c1c',
        'fgts-pis-direitos-trabalhador'=>'#047857','fgts'=>'#047857','saque-aniversario'=>'#059669','pis-pasep'=>'#0e6655','pis'=>'#0e6655','13-salario'=>'#065f46','ferias'=>'#10b981','rescisao-aviso-previo'=>'#0f766e','direitos-trabalhador'=>'#047857',
        'vagas-e-empregos'=>'#0d6844','vagas-clt'=>'#0d6844','vagas-de-emprego'=>'#0d6844','vagas'=>'#0d6844','empregos'=>'#0d6844','home-office'=>'#7c2d12','trabalho-remoto'=>'#7c2d12','estagio-jovem-aprendiz'=>'#0369a1','estagio'=>'#6d28d9','jovem-aprendiz'=>'#0369a1','sine'=>'#0d6844',
        'concursos-publicos'=>'#b91c1c','concursos'=>'#b91c1c','concursos-federais'=>'#991b1b','concursos-estaduais'=>'#dc2626','concursos-municipais'=>'#ef4444','editais'=>'#7f1d1d','resultado-convocacao'=>'#b91c1c',
        'mei-trabalho-autonomo'=>'#ea580c','mei'=>'#ea580c','abrir-mei'=>'#f97316','das-mei'=>'#fb923c','consultar-mei'=>'#ea580c','dasn-simei'=>'#c2410c','limite-mei'=>'#9a3412','baixa-mei'=>'#7c2d12',
        'imposto-de-renda'=>'#92400e','declaracao-ir'=>'#a16207','restituicao'=>'#ca8a04','tabela-ir'=>'#854d0e','malha-fina'=>'#78350f',
        'direitos-sociais-documentos'=>'#475569','cpf'=>'#475569','cnh-carteira-motorista'=>'#334155','cnh'=>'#334155','carteira-trabalho-digital'=>'#0f172a','cin-rg-identidade'=>'#1e293b','rg'=>'#1e293b','carteira-do-idoso'=>'#64748b','id-jovem'=>'#94a3b8','gov-br'=>'#475569',
        'clt-e-direitos'=>'#b45309','clt'=>'#b45309','direitos'=>'#b45309','salario'=>'#b45309',
    ];
    $slug=strtolower((string)$slug);
    if(isset($exact[$slug]))return $exact[$slug];
    $part=['inss'=>'#1e40af','aposenta'=>'#1e3a8a','bolsa-familia'=>'#6d28d9','beneficio'=>'#7c3aed','fgts'=>'#047857','pis'=>'#0e6655','vaga'=>'#0d6844','emprego'=>'#0d6844','home-office'=>'#7c2d12','remoto'=>'#7c2d12','aprendiz'=>'#0369a1','estagio'=>'#6d28d9','concurso'=>'#b91c1c','edital'=>'#7f1d1d','mei'=>'#ea580c','autonomo'=>'#f97316','imposto'=>'#92400e','restitui'=>'#ca8a04','irpf'=>'#92400e','cpf'=>'#475569','cnh'=>'#334155','rg'=>'#1e293b','documento'=>'#475569','identidade'=>'#1e293b','gov'=>'#475569','clt'=>'#b45309','direito'=>'#b45309','salario'=>'#b45309','seguro'=>'#b91c1c'];
    foreach($part as $k=>$v){if(strpos($slug,$k)!==false)return $v;}
    $palette=['#0d6844','#1e40af','#7c3aed','#b91c1c','#ea580c','#92400e','#475569','#0e6655','#0369a1','#6d28d9'];
    return $palette[hexdec(substr(md5($slug),0,2))%count($palette)];
}

function veb_fallback_img(){return'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 225" fill="none"><rect width="400" height="225" fill="#e2e8f0"/><path d="M170 90l30 45h-60z M200 80a12 12 0 110 24 12 12 0 010-24z" fill="#94a3b8"/><text x="200" y="165" text-anchor="middle" fill="#64748b" font-size="13" font-family="sans-serif">Sem imagem</text></svg>');}

/* ============================================================ QUERIES CACHEADAS ============================================================ */
function veb_trending_cats(){
    $key='veb_trending_cats_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $r=$wpdb->get_col("SELECT t.term_id FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id INNER JOIN {$wpdb->term_relationships} tr ON tt.term_taxonomy_id=tr.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON tr.object_id=p.ID WHERE tt.taxonomy='category' AND p.post_status='publish' AND p.post_type='post' AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) GROUP BY t.term_id HAVING COUNT(*) >= 2 ORDER BY COUNT(*) DESC LIMIT 3");
    $r=array_map('intval',(array)$r);
    set_transient($key,$r,30*MINUTE_IN_SECONDS);
    return $r;
}

function veb_most_read_week(){
    $key='veb_most_read_week_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $r=$wpdb->get_results("SELECT p.ID, p.post_title, p.post_date, COALESCE(NULLIF(CAST(pm.meta_value AS UNSIGNED),0), p.comment_count) AS popularity FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key='post_views_count' WHERE p.post_type='post' AND p.post_status='publish' AND p.post_date >= DATE_SUB(NOW(), INTERVAL 7 DAY) ORDER BY popularity DESC, p.post_date DESC LIMIT 6");
    if(empty($r)) $r=$wpdb->get_results("SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY comment_count DESC, post_date DESC LIMIT 6");
    set_transient($key,$r,HOUR_IN_SECONDS);
    return $r;
}

function veb_nav_cats(){
    $key='veb_nav_cats_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $r=$wpdb->get_results("SELECT t.term_id,t.name,t.slug,tt.count FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id=tt.term_id WHERE tt.taxonomy='category' AND tt.count>0 AND t.slug!='uncategorized' ORDER BY tt.count DESC LIMIT 12");
    set_transient($key,$r,HOUR_IN_SECONDS);
    return $r;
}

function veb_all_cats(){
    return get_categories(['orderby'=>'count','order'=>'DESC','hide_empty'=>true,'exclude'=>get_cat_ID('uncategorized'),'number'=>20]);
}

/* JSON-LD ItemList — só home, complementa Rank Math (não duplica) */
function veb_home_itemlist(){
    $key='veb_home_itemlist_v1';
    $c=get_transient($key);
    if($c!==false) return $c;
    global $wpdb;
    $posts=$wpdb->get_results("SELECT ID, post_title, post_date FROM {$wpdb->posts} WHERE post_type='post' AND post_status='publish' ORDER BY post_date DESC LIMIT 8");
    $items=[];
    foreach($posts as $i=>$p){
        $url=get_permalink($p->ID);
        $img=get_the_post_thumbnail_url($p->ID,'large')?:get_the_post_thumbnail_url($p->ID,'full');
        $items[]=[
            '@type'=>'ListItem','position'=>$i+1,'url'=>$url,
            'item'=>[
                '@type'=>'NewsArticle',
                'headline'=>wp_strip_all_tags($p->post_title),
                'image'=>$img?:'',
                'datePublished'=>date('c',strtotime($p->post_date)),
                'mainEntityOfPage'=>$url,
            ],
        ];
    }
    $jsonld=['@context'=>'https://schema.org','@type'=>'ItemList','itemListElement'=>$items];
    set_transient($key,$jsonld,15*MINUTE_IN_SECONDS);
    return $jsonld;
}

/* Invalida caches ao salvar post/categoria */
add_action('save_post_post', function(){
    delete_transient('veb_trending_cats_v1');
    delete_transient('veb_most_read_week_v1');
    delete_transient('veb_nav_cats_v1');
    delete_transient('veb_home_itemlist_v1');
});
add_action('edited_category', function(){ delete_transient('veb_nav_cats_v1'); });

/* ============================================================ RENDER CARD ============================================================ */
function veb_render_card($p, $loading='lazy'){
    $tc=veb_trending_cats();
    $pt=get_the_post_thumbnail_url($p->ID,'medium');$pl=get_permalink($p->ID);$pc=veb_get_cat($p->ID);$pcsl=veb_get_cat_slug($p->ID);$pcid=veb_get_cat_id($p->ID);$exc=veb_get_excerpt($p);$rt=veb_reading_time($p->ID);$isNew=veb_is_new($p->post_date);$isHot=in_array($pcid,$tc,true);
    ?>
    <a href="<?php echo esc_url($pl);?>" class="card" data-pid="<?php echo (int)$p->ID;?>" data-cat="<?php echo esc_attr($pc);?>" data-cat-slug="<?php echo esc_attr($pcsl);?>" data-thumb="<?php echo esc_url($pt?:veb_fallback_img());?>" data-title="<?php echo esc_attr($p->post_title);?>"><div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:<?php echo esc_attr(veb_cat_color($pcsl));?>"><?php echo esc_html($pc);?></span><?php if($isNew):?><span class="new-badge">NOVO</span><?php endif;?><?php if($isHot):?><span class="hot-badge" title="Categoria em alta">EM ALTA</span><?php endif;?></div><h3><?php echo esc_html(wp_trim_words($p->post_title,14));?></h3><p class="card-excerpt"><?php echo esc_html($exc);?></p></div><div class="card-img"><img src="<?php echo esc_url($pt?:veb_fallback_img());?>" alt="<?php echo esc_attr(wp_trim_words($p->post_title,6));?>" width="400" height="225" loading="<?php echo esc_attr($loading);?>" decoding="async"><button type="button" class="card-share" aria-label="Compartilhar" data-share-url="<?php echo esc_url($pl);?>" data-share-title="<?php echo esc_attr($p->post_title);?>"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg></button></div><div class="card-footer"><time datetime="<?php echo esc_attr(date('c',strtotime($p->post_date)));?>"><?php echo esc_html(veb_time_ago($p->post_date));?></time><span class="reading-time">· <?php echo (int)$rt;?> min</span></div></a>
    <?php
}

/* ============================================================ AJAX (infinite scroll) ============================================================ */
add_action('wp_ajax_veb_load_posts','veb_ajax_load_posts');
add_action('wp_ajax_nopriv_veb_load_posts','veb_ajax_load_posts');
function veb_ajax_load_posts(){
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
    $tc=veb_trending_cats();
    $out=[];
    while($q->have_posts()){
        $q->the_post();
        $p=get_post();
        $thumb=get_the_post_thumbnail_url($p->ID,'medium');
        $cat=veb_get_cat($p->ID);
        $cat_slug=veb_get_cat_slug($p->ID);
        $cat_id_p=veb_get_cat_id($p->ID);
        $out[]=[
            'id'=>$p->ID,
            'title'=>get_the_title($p),
            'link'=>get_permalink($p),
            'excerpt'=>veb_get_excerpt($p),
            'date'=>date('c',strtotime($p->post_date)),
            'date_fmt'=>date_i18n('d \d\e M, Y',strtotime($p->post_date)),
            'thumb'=>$thumb?:veb_fallback_img(),
            'cat'=>$cat,
            'cat_slug'=>$cat_slug,
            'is_new'=>veb_is_new($p->post_date),
            'is_hot'=>in_array($cat_id_p,$tc,true),
            'reading_time'=>veb_reading_time($p->ID),
        ];
    }
    wp_reset_postdata();
    wp_send_json([
        'posts'=>$out,
        'max_pages'=>(int)$q->max_num_pages,
        'current_page'=>$paged,
    ]);
}

/* Manifest PWA dinâmico (data URI no header.php) */
function veb_pwa_manifest_uri(){
    $manifest=[
        'name'=>get_bloginfo('name'),
        'short_name'=>get_bloginfo('name'),
        'description'=>get_bloginfo('description')?:'Vagas, empregos e benefícios.',
        'start_url'=>'/',
        'display'=>'standalone',
        'background_color'=>'#f0fdf4',
        'theme_color'=>'#0d6844',
        'lang'=>'pt-BR',
        'icons'=>[
            ['src'=>get_site_icon_url(192) ?: home_url('/favicon.ico'),'sizes'=>'192x192','type'=>'image/png'],
            ['src'=>get_site_icon_url(512) ?: home_url('/favicon.ico'),'sizes'=>'512x512','type'=>'image/png'],
        ],
    ];
    return 'data:application/manifest+json;base64,'.base64_encode(wp_json_encode($manifest, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
}
