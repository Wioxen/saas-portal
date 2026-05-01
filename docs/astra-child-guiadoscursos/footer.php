<?php
/**
 * Astra Child Guia dos Cursos — footer.php
 */
if (!defined('ABSPATH')) exit;
$site_name = get_bloginfo('name');
$all_categories = gdc_all_cats();

$current_post_data = null;
if (is_single()) {
    global $post;
    $thumb = get_the_post_thumbnail_url($post->ID, 'medium');
    $current_post_data = [
        'pid' => (int)$post->ID,
        'title' => get_the_title($post),
        'link' => get_permalink($post),
        'cat' => gdc_get_cat($post->ID),
        'cat_slug' => gdc_get_cat_slug($post->ID),
        'thumb' => $thumb ?: gdc_fallback_img(),
    ];
}

$has_grid = (is_front_page() || is_home() || is_archive() || is_search() || is_single());
$archive_cat_id = is_category() ? (int)get_queried_object_id() : 0;
$archive_search = is_search() ? get_search_query() : '';
$single_exclude = is_single() ? (int)get_the_ID() : 0;
?>

<div class="toast" id="toast" role="status" aria-live="polite"></div>

<aside class="site-disclaimer"><p><strong>Aviso:</strong> Este portal é independente. Não possuímos vínculo oficial com instituições de ensino, órgãos públicos ou empresas citadas.</p></aside>

<footer class="site-footer">
<div class="footer-grid">
    <div class="footer-col"><h3 class="footer-heading"><?php echo esc_html($site_name); ?></h3><p>Portal dedicado a cursos gratuitos, vestibulares, ENEM, Sisu, ProUni, profissionalizantes e dicas de carreira para quem quer crescer profissionalmente em todo o Brasil.</p></div>
    <div class="footer-col"><h3 class="footer-heading">Institucional</h3><ul>
        <li><a href="<?php echo esc_url(home_url('/sobre/')); ?>">Sobre nós</a></li>
        <li><a href="<?php echo esc_url(home_url('/politica-de-privacidade/')); ?>">Política de Privacidade</a></li>
        <li><a href="<?php echo esc_url(home_url('/termos-de-uso/')); ?>">Termos de Uso</a></li>
        <li><a href="<?php echo esc_url(home_url('/contato/')); ?>">Contato</a></li>
    </ul></div>
    <div class="footer-col"><h3 class="footer-heading">Categorias</h3><ul>
        <?php foreach (array_slice($all_categories, 0, 6) as $fc): ?>
        <li><a href="<?php echo esc_url(get_category_link($fc->term_id)); ?>"><?php echo esc_html($fc->name); ?></a></li>
        <?php endforeach; ?>
    </ul></div>
    <div class="footer-col"><h3 class="footer-heading">Siga nas Redes</h3><ul>
        <li><a href="https://www.instagram.com/_guiadoscursos" target="_blank" rel="noopener noreferrer">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.81.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.81-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.81-.25-2.23-.41-.56-.22-.96-.48-1.38-.9-.42-.42-.68-.82-.9-1.38-.16-.42-.36-1.06-.41-2.23C2.17 15.58 2.16 15.2 2.16 12s.01-3.58.07-4.85c.05-1.17.25-1.81.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16M12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.31-1.46.72-2.13 1.39C1.34 2.69.93 3.36.62 4.15.32 4.91.12 5.79.06 7.06.01 8.34 0 8.75 0 12s.01 3.66.07 4.94c.06 1.27.26 2.15.56 2.91.31.79.72 1.46 1.39 2.13.67.67 1.34 1.08 2.13 1.39.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.66-.01 4.94-.07c1.27-.06 2.15-.26 2.91-.56.79-.31 1.46-.72 2.13-1.39.67-.67 1.08-1.34 1.39-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.94s-.01-3.66-.07-4.94c-.06-1.27-.26-2.15-.56-2.91-.31-.79-.72-1.46-1.39-2.13C21.31 1.34 20.64.93 19.85.62 19.09.32 18.21.12 16.94.06 15.66.01 15.25 0 12 0zm0 5.84A6.16 6.16 0 1 0 12 18.16 6.16 6.16 0 0 0 12 5.84zm0 10.16A4 4 0 1 1 12 8a4 4 0 0 1 0 8zm6.41-11.85a1.44 1.44 0 1 0 0 2.88 1.44 1.44 0 0 0 0-2.88z"/></svg>
            Instagram
        </a></li>
    </ul></div>
</div>
<div class="footer-bottom">
    <p>&copy; <?php echo date('Y'); ?> <?php echo esc_html($site_name); ?> — Portal informativo sobre educação e carreira.</p>
    <p style="margin-top:.3rem"><a href="<?php echo esc_url(home_url('/sitemap.xml')); ?>">Mapa do Site</a></p>
</div>
</footer>

<script>
(function(){
'use strict';
var LS_RECENT='gdc_recent_v1', LS_CATSCORE='gdc_catscore_v1', LS_SAVED='gdc_saved_v1', MAX_RECENT=6, MAX_SAVED=50;
var AJAX_URL='<?php echo admin_url("admin-ajax.php"); ?>';
var FB_IMG='<?php echo gdc_fallback_img(); ?>';
<?php if ($current_post_data): ?>
var CURRENT_POST=<?php echo wp_json_encode($current_post_data); ?>;
<?php else: ?>
var CURRENT_POST=null;
<?php endif; ?>
var HAS_GRID=<?php echo $has_grid ? 'true' : 'false'; ?>;
var ARCHIVE_CAT=<?php echo (int)$archive_cat_id; ?>;
var ARCHIVE_SEARCH=<?php echo wp_json_encode($archive_search); ?>;
var EXCLUDE_PID=<?php echo (int)$single_exclude; ?>;

function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}
function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}
function escH(s){var d=document.createElement('div');d.appendChild(document.createTextNode(s||''));return d.innerHTML;}
function toast(msg){var t=document.getElementById('toast');if(!t)return;t.textContent=msg;t.classList.add('show');clearTimeout(toast._t);toast._t=setTimeout(function(){t.classList.remove('show');},2400);}

/* TRACKING */
function trackItem(item){
    if(!item||!item.pid) return;
    var rec=lsGet(LS_RECENT,[]);
    rec=rec.filter(function(r){return String(r.pid)!==String(item.pid);});
    rec.unshift({pid:item.pid,title:item.title||'',cat:item.cat||'',cat_slug:item.cat_slug||'',thumb:item.thumb||'',link:item.link||'',ts:Date.now()});
    if(rec.length>MAX_RECENT) rec=rec.slice(0,MAX_RECENT);
    lsSet(LS_RECENT,rec);
    if(item.cat_slug){var sc=lsGet(LS_CATSCORE,{});sc[item.cat_slug]=(sc[item.cat_slug]||0)+1;lsSet(LS_CATSCORE,sc);}
}
function trackClickEl(el){
    var pid=el.getAttribute('data-pid'); if(!pid) return;
    trackItem({pid:pid,title:el.getAttribute('data-title')||'',cat:el.getAttribute('data-cat')||'',cat_slug:el.getAttribute('data-cat-slug')||'',thumb:el.getAttribute('data-thumb')||'',link:el.getAttribute('href')||''});
}
document.addEventListener('click',function(e){
    if(e.target.closest('.card-share, .post-share-btn, .share-btn, .share-save')) return;
    var el=e.target.closest('a.card, a.hero-main, a.hero-card, a.smart-card');
    if(el) trackClickEl(el);
},true);
if(CURRENT_POST) trackItem(CURRENT_POST);

/* SAVE FOR LATER */
function getSaved(){return lsGet(LS_SAVED,[]);}
function isSaved(pid){return getSaved().some(function(r){return String(r.pid)===String(pid);});}
function toggleSave(item){
    var saved=getSaved();
    var idx=-1;
    for(var i=0;i<saved.length;i++){if(String(saved[i].pid)===String(item.pid)){idx=i;break;}}
    if(idx>=0){saved.splice(idx,1);lsSet(LS_SAVED,saved);toast('Removido dos salvos');return false;}
    saved.unshift(item);
    if(saved.length>MAX_SAVED) saved=saved.slice(0,MAX_SAVED);
    lsSet(LS_SAVED,saved);
    toast('Salvo para depois ✓');
    return true;
}
var saveBtn=document.getElementById('saveForLaterBtn');
if(saveBtn && CURRENT_POST){
    function updSave(){if(isSaved(CURRENT_POST.pid)) saveBtn.classList.add('saved'); else saveBtn.classList.remove('saved');}
    updSave();
    saveBtn.addEventListener('click',function(e){e.preventDefault();toggleSave(CURRENT_POST);updSave();renderSaved();});
}

function renderSaved(){
    var sec=document.getElementById('savedSection'), grid=document.getElementById('savedGrid');
    if(!sec||!grid) return;
    var items=getSaved();
    if(!items.length){sec.style.display='none'; return;}
    var current_pid=CURRENT_POST?String(CURRENT_POST.pid):null;
    var visible=items.filter(function(r){return String(r.pid)!==current_pid;}).slice(0,6);
    if(!visible.length){sec.style.display='none'; return;}
    var html=visible.map(function(r){
        var color=catColor(r.cat_slug||'');
        return '<a class="smart-card" href="'+escH(r.link)+'" data-pid="'+escH(r.pid)+'" data-cat="'+escH(r.cat)+'" data-cat-slug="'+escH(r.cat_slug)+'" data-thumb="'+escH(r.thumb)+'" data-title="'+escH(r.title)+'"><span class="smart-card-saved-icon" aria-hidden="true">&#128278;</span><div class="smart-card-img"><img src="'+escH(r.thumb)+'" alt="" loading="lazy" decoding="async" width="400" height="225"></div><div class="smart-card-body"><span class="smart-card-cat" style="background:'+color+'">'+escH(r.cat||'Geral')+'</span><h4>'+escH(r.title)+'</h4></div></a>';
    }).join('');
    grid.innerHTML=html; sec.style.display='block';
}

/* RENDER VISTO POR ÚLTIMO */
function renderRecent(){
    var rec=lsGet(LS_RECENT,[]);
    var sec=document.getElementById('recentSection'), grid=document.getElementById('recentGrid');
    if(!sec||!grid) return;
    var current_pid=CURRENT_POST?String(CURRENT_POST.pid):null;
    var items=rec.filter(function(r){return String(r.pid)!==current_pid;}).slice(0,MAX_RECENT);
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
    toast('Histórico limpo');
});
var clearSavedBtn=document.getElementById('clearSaved');
if(clearSavedBtn) clearSavedBtn.addEventListener('click',function(){
    if(!confirm('Remover todos os posts salvos?')) return;
    try{localStorage.removeItem(LS_SAVED);}catch(e){}
    var sec=document.getElementById('savedSection'); if(sec) sec.style.display='none';
    if(saveBtn) saveBtn.classList.remove('saved');
    toast('Salvos limpos');
});

/* REORDENA NAV */
function reorderNavByPreference(){
    var sc=lsGet(LS_CATSCORE,{});
    var nav=document.getElementById('navCats');
    if(!nav) return;
    var items=Array.prototype.slice.call(nav.querySelectorAll('li')).filter(function(li){return !li.classList.contains('close-li');});
    if(!items.length) return;
    items.forEach(function(li){
        var a=li.querySelector('.nav-cat-link'); if(!a) return;
        var slug=a.getAttribute('data-cat-slug')||'';
        if((sc[slug]||0)>0) a.setAttribute('data-active','1'); else a.removeAttribute('data-active');
    });
    if(!Object.keys(sc).length) return;
    var sorted=items.slice().sort(function(a,b){
        var aa=a.querySelector('.nav-cat-link'),bb=b.querySelector('.nav-cat-link');
        var sa=sc[(aa&&aa.getAttribute('data-cat-slug'))||'']||0;
        var sb=sc[(bb&&bb.getAttribute('data-cat-slug'))||'']||0;
        return sb-sa;
    });
    var closeLi=nav.querySelector('.close-li');
    sorted.forEach(function(li){nav.appendChild(li);});
    if(closeLi) nav.insertBefore(closeLi,nav.firstChild);
}

/* MAPA DE CORES (espelha PHP) */
function catColor(s){
    var exact={'cursos-gratuitos':'#10b981','senac':'#059669','sebrae':'#0d9488','senai':'#047857','enem':'#dc2626','sisu':'#b91c1c','prouni':'#991b1b','fies':'#7f1d1d','vestibular':'#b91c1c','cursos-tecnicos':'#1e3a8a','etec':'#1e40af','if-federais':'#1d4ed8','profissionalizantes':'#7c3aed','informatica':'#6d28d9','programacao':'#9333ea','ead':'#0891b2','online':'#06b6d4','idiomas':'#ea580c','ingles':'#f97316','espanhol':'#fb923c','libras':'#c2410c','concursos':'#9d174d','concursos-publicos':'#9d174d','editais':'#831843','carreira':'#475569','curriculo':'#475569','primeiro-emprego':'#64748b'};
    s=(s||'').toLowerCase(); if(exact[s]) return exact[s];
    var part={'gratuit':'#10b981','senac':'#059669','senai':'#047857','sebrae':'#0d9488','enem':'#dc2626','sisu':'#b91c1c','prouni':'#991b1b','vestibular':'#b91c1c','tecnico':'#1e3a8a','etec':'#1e40af','federa':'#1d4ed8','profissionaliz':'#7c3aed','informatica':'#6d28d9','programacao':'#9333ea','marketing':'#7e22ce','ead':'#0891b2','online':'#06b6d4','idiom':'#ea580c','ingles':'#f97316','espanhol':'#fb923c','libras':'#c2410c','concurso':'#9d174d','edital':'#831843','carreira':'#475569','curriculo':'#475569','emprego':'#64748b','curso':'#1e40af'};
    for(var k in part){if(s.indexOf(k)!==-1) return part[k];}
    var pal=['#1e40af','#10b981','#dc2626','#7c3aed','#0891b2','#ea580c','#9d174d','#475569','#3b82f6','#059669'];
    var h=0; for(var i=0;i<s.length;i++){h=((h<<5)-h)+s.charCodeAt(i); h|=0;}
    return pal[Math.abs(h)%pal.length];
}

/* MENU MOBILE */
var toggle=document.getElementById('menuToggle'),navW=document.getElementById('navWrap'),closeLi=document.querySelector('.close-li'),closeBtn=document.getElementById('navClose');
function oN(){if(!navW)return;navW.classList.add('open');if(closeLi)closeLi.style.display='block';toggle.classList.add('active');toggle.setAttribute('aria-expanded','true');document.body.style.overflow='hidden';if(closeBtn)closeBtn.focus();}
function cN(){if(!navW)return;navW.classList.remove('open');if(closeLi)closeLi.style.display='none';toggle.classList.remove('active');toggle.setAttribute('aria-expanded','false');document.body.style.overflow='';toggle.focus();}
if(toggle&&navW){toggle.addEventListener('click',oN);if(closeBtn)closeBtn.addEventListener('click',cN);navW.addEventListener('click',function(e){if(e.target===navW)cN();});navW.querySelectorAll('.nav-cat-link').forEach(function(a){a.addEventListener('click',cN);});document.addEventListener('keydown',function(e){if(e.key==='Escape'&&navW.classList.contains('open'))cN();});}

/* CARROSSEL DE CATEGORIAS */
var quickGrid=document.querySelector('.quick-grid');
var quickPrev=document.querySelector('.quick-nav.prev');
var quickNext=document.querySelector('.quick-nav.next');
function updateQuickNavState(){
    if(!quickGrid||!quickPrev||!quickNext)return;
    quickPrev.disabled=quickGrid.scrollLeft<=4;
    quickNext.disabled=quickGrid.scrollLeft+quickGrid.clientWidth>=quickGrid.scrollWidth-4;
}
if(quickGrid){
    quickGrid.addEventListener('scroll',updateQuickNavState,{passive:true});
    window.addEventListener('resize',updateQuickNavState);
    if(quickPrev) quickPrev.addEventListener('click',function(){quickGrid.scrollBy({left:-300,behavior:'smooth'});});
    if(quickNext) quickNext.addEventListener('click',function(){quickGrid.scrollBy({left:300,behavior:'smooth'});});
    updateQuickNavState();
}

/* TABLE OF CONTENTS auto */
function buildTOC(){
    var content=document.querySelector('.post-content');
    var tocAside=document.getElementById('postToc');
    var tocList=document.getElementById('tocList');
    if(!content||!tocAside||!tocList) return;
    var headings=content.querySelectorAll('h2, h3');
    if(headings.length<3) return;
    headings.forEach(function(h,i){
        if(!h.id) h.id='sec-'+i;
        var li=document.createElement('li');
        li.className='toc-item toc-'+h.tagName.toLowerCase();
        var a=document.createElement('a');
        a.href='#'+h.id;
        a.textContent=h.textContent;
        a.dataset.target=h.id;
        li.appendChild(a);
        tocList.appendChild(li);
    });
    tocAside.hidden=false;
    var observer=new IntersectionObserver(function(entries){
        entries.forEach(function(entry){
            var link=tocList.querySelector('a[data-target="'+entry.target.id+'"]');
            if(!link) return;
            if(entry.isIntersecting){
                tocList.querySelectorAll('a.active').forEach(function(a){a.classList.remove('active');});
                link.classList.add('active');
            }
        });
    },{rootMargin:'-15% 0px -75% 0px'});
    headings.forEach(function(h){observer.observe(h);});
    var toggle=tocAside.querySelector('.post-toc-toggle');
    if(toggle){
        toggle.addEventListener('click',function(){
            var collapsed=tocAside.getAttribute('data-collapsed')==='1';
            tocAside.setAttribute('data-collapsed',collapsed?'0':'1');
            toggle.textContent=collapsed?'Esconder ▲':'Mostrar ▼';
        });
        if(window.innerWidth<=1023){tocAside.setAttribute('data-collapsed','1');toggle.textContent='Mostrar ▼';}
    }
}
buildTOC();
renderSaved();

/* CAPTCHA + NEWSLETTER */
var cRs,cQe=document.getElementById('cQ'),cIe=document.getElementById('cA'),cEe=document.getElementById('cErr'),cRe=document.getElementById('cR'),nFe=document.getElementById('nlForm'),nEe=document.getElementById('nlEmail'),nBe=document.getElementById('nlBtn'),nOe=document.getElementById('nlOk');
function gCa(){var a=Math.floor(Math.random()*15)+1,b=Math.floor(Math.random()*10)+1;if(Math.random()>.5&&a>=b){cRs=a-b;if(cQe)cQe.textContent='Quanto \xe9 '+a+' − '+b+'?';}else{cRs=a+b;if(cQe)cQe.textContent='Quanto \xe9 '+a+' + '+b+'?';}if(cIe)cIe.value='';if(cEe)cEe.style.display='none';if(nOe)nOe.style.display='none';}
if(cQe) gCa();
if(cRe) cRe.addEventListener('click',gCa);
if(nFe) nFe.addEventListener('submit',function(e){e.preventDefault();if(cEe)cEe.style.display='none';if(nOe)nOe.style.display='none';var em=nEe?nEe.value.trim():'';if(!em||em.indexOf('@')===-1)return;var ans=cIe?parseInt(cIe.value,10):NaN;if(isNaN(ans)||ans!==cRs){if(cEe)cEe.style.display='block';if(cIe){cIe.value='';cIe.focus();}gCa();return;}if(nBe){nBe.textContent='✓ Inscrito!';nBe.disabled=true;}if(nEe)nEe.value='';if(cIe)cIe.value='';if(nOe)nOe.style.display='block';setTimeout(function(){if(nBe){nBe.textContent='Inscrever-se';nBe.disabled=false;}if(nOe)nOe.style.display='none';gCa();},4000);});

/* READING PROGRESS */
var rp=document.getElementById('readProgress');
function onScroll(){
    if(!rp) return;
    var st=window.pageYOffset||document.documentElement.scrollTop;
    var dh=document.documentElement.scrollHeight-window.innerHeight;
    rp.style.width=(dh>0?(st/dh)*100:0)+'%';
}
window.addEventListener('scroll',onScroll,{passive:true});

/* ATALHO "/" */
document.addEventListener('keydown',function(e){
    if(e.key==='/' && document.activeElement && document.activeElement.tagName!=='INPUT' && document.activeElement.tagName!=='TEXTAREA'){
        var sq=document.getElementById('sq'); if(sq){e.preventDefault(); sq.focus();}
    }
});

/* WEB SHARE / COPY LINK */
document.addEventListener('click',function(e){
    var btn=e.target.closest('.card-share, .share-copy'); if(!btn) return;
    e.preventDefault(); e.stopPropagation();
    var url=btn.getAttribute('data-share-url')||window.location.href;
    var title=btn.getAttribute('data-share-title')||document.title;
    if(navigator.share){
        navigator.share({title:title, url:url}).catch(function(){});
    }else if(navigator.clipboard){
        navigator.clipboard.writeText(url).then(function(){toast('Link copiado!');}).catch(function(){toast('Não foi possível copiar.');});
    }else{
        var ta=document.createElement('textarea'); ta.value=url; document.body.appendChild(ta); ta.select();
        try{document.execCommand('copy'); toast('Link copiado!');}catch(_){toast('Erro ao copiar.');}
        document.body.removeChild(ta);
    }
});

/* HOVER PREFETCH */
var prefetched={};
function prefetch(url){if(!url||prefetched[url])return;prefetched[url]=1;try{var l=document.createElement('link');l.rel='prefetch';l.href=url;l.as='document';document.head.appendChild(l);}catch(e){}}
var prefetchTimer;
document.addEventListener('mouseover',function(e){
    var a=e.target.closest('a.card, a.hero-main, a.hero-card, a.smart-card, a.quick-tag');
    if(!a||!a.href||a.host!==location.host)return;
    clearTimeout(prefetchTimer);
    prefetchTimer=setTimeout(function(){prefetch(a.href);},80);
});

/* INFINITE SCROLL */
var page=2,loading=false,done=false;
var grid=document.getElementById('allGrid'),loader=document.getElementById('loader'),noMore=document.getElementById('noMore'),sentinel=document.getElementById('scroll-sentinel');
if(grid){
    var initialDone=grid.getAttribute('data-done')==='1';
    if(initialDone) done=true;
}
function smartDate(iso,fmt){var d=(Date.now()-new Date(iso).getTime())/1000;if(d<3600)return Math.max(1,Math.floor(d/60))+' min atrás';if(d<86400)return Math.floor(d/3600)+'h atrás';return fmt;}
function isNew(iso){return (Date.now()-new Date(iso).getTime())<86400000;}
function finish(){done=true;if(noMore)noMore.style.display='block';}
function buildCard(p){
    var a=document.createElement('a');a.href=p.link;a.className='card';
    a.setAttribute('data-pid',p.id||'');a.setAttribute('data-cat',p.cat||'');a.setAttribute('data-cat-slug',p.cat_slug||'');a.setAttribute('data-thumb',p.thumb||FB_IMG);a.setAttribute('data-title',p.title||'');
    var newB=isNew(p.date)?'<span class="new-badge">NOVO</span>':'';
    var hotB=p.is_hot?'<span class="hot-badge">EM ALTA</span>':'';
    var rt=p.reading_time?('· '+p.reading_time+' min'):'';
    a.innerHTML='<div class="card-body"><div class="card-meta-row"><span class="cat-badge" style="background:'+catColor(p.cat_slug)+'">'+escH(p.cat)+'</span>'+newB+hotB+'</div><h3>'+escH(p.title)+'</h3><p class="card-excerpt">'+escH(p.excerpt||'')+'</p></div><div class="card-img"><img src="'+escH(p.thumb||FB_IMG)+'" alt="" width="400" height="225" loading="lazy" decoding="async"><button type="button" class="card-share" aria-label="Compartilhar" data-share-url="'+escH(p.link)+'" data-share-title="'+escH(p.title||'')+'"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92 1.61 0 2.92-1.31 2.92-2.92s-1.31-2.92-2.92-2.92z"/></svg></button></div><div class="card-footer"><time datetime="'+escH(p.date)+'">'+escH(smartDate(p.date,p.date_fmt))+'</time><span class="reading-time">'+rt+'</span></div>';
    return a;
}
function loadMore(){
    if(loading||done||!grid) return;
    loading=true;
    if(loader) loader.style.display='block';
    var url=AJAX_URL+'?action=gdc_load_posts&paged='+page;
    if(ARCHIVE_CAT>0) url+='&cat='+ARCHIVE_CAT;
    if(ARCHIVE_SEARCH) url+='&s='+encodeURIComponent(ARCHIVE_SEARCH);
    if(EXCLUDE_PID>0) url+='&exclude='+EXCLUDE_PID;
    fetch(url).then(function(r){if(!r.ok) throw new Error(r.status); return r.json();}).then(function(d){
        if(!d.posts||!d.posts.length){finish(); return;}
        var f=document.createDocumentFragment();
        d.posts.forEach(function(p){f.appendChild(buildCard(p));});
        grid.appendChild(f);
        page++;
        if(d.max_pages && page>d.max_pages) finish();
    }).catch(function(){finish();}).finally(function(){loading=false; if(loader) loader.style.display='none';});
}
if(HAS_GRID && grid && !done){
    if('IntersectionObserver' in window && sentinel){
        new IntersectionObserver(function(e){if(e[0].isIntersecting) loadMore();},{rootMargin:'0px 0px 800px 0px'}).observe(sentinel);
    }
    var st=false;
    window.addEventListener('scroll',function(){if(st||done) return; st=true; requestAnimationFrame(function(){st=false; if(window.scrollY+window.innerHeight>=document.documentElement.scrollHeight-1000) loadMore();});},{passive:true});
    setTimeout(function(){if(!done&&!loading&&sentinel){var r=sentinel.getBoundingClientRect(); if(r.top<window.innerHeight+800) loadMore();}},500);
}

/* NEXT POST SLIDE-IN (single only) */
var nextSlide=document.getElementById('nextPostSlide');
if(nextSlide && CURRENT_POST){
    var dKey='gdc_next_dismissed_'+CURRENT_POST.pid;
    var dismissed=false;
    try{dismissed=sessionStorage.getItem(dKey)==='1';}catch(e){}
    if(!dismissed){
        var shown=false;
        var checkScroll=function(){
            if(shown) return;
            var pct=(window.pageYOffset+window.innerHeight)/document.documentElement.scrollHeight;
            if(pct>0.85){nextSlide.classList.add('show');shown=true;}
        };
        window.addEventListener('scroll',checkScroll,{passive:true});
        var dismissBtn=nextSlide.querySelector('.next-dismiss');
        if(dismissBtn) dismissBtn.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();nextSlide.classList.remove('show');try{sessionStorage.setItem(dKey,'1');}catch(_){}});
    }
}

/* INIT */
renderRecent();
reorderNavByPreference();
onScroll();
})();
</script>
<?php wp_footer(); ?>
</body>
</html>
