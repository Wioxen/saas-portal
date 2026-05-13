/**
 * Leão da Barra - Main JS
 * 
 * Carousel hero, infinite scroll, category filter, mobile nav
 * No dependencies, mobile-first
 * 
 * @package LeaoDaBarra
 */

(function() {
    'use strict';

    // ============================================================
    // 1. CAROUSEL
    // ============================================================
    const carousel = {
        track: document.getElementById('g1-carousel-track'),
        progressBar: document.getElementById('g1-progress-bar'),
        slides: null,
        current: 0,
        total: 0,
        timer: null,
        duration: 5000,
        touchStartX: 0,
        touchEndX: 0,

        init() {
            if (!this.track) return;
            this.slides = this.track.querySelectorAll('.g1-carousel-slide');
            this.total = this.slides.length;
            if (this.total <= 1) return;

            const prev = document.getElementById('g1-prev');
            const next = document.getElementById('g1-next');
            if (prev) prev.addEventListener('click', () => { this.prev(); this.restartAuto(); });
            if (next) next.addEventListener('click', () => { this.next(); this.restartAuto(); });

            this.track.addEventListener('touchstart', (e) => {
                this.touchStartX = e.changedTouches[0].screenX;
                this.stopAuto();
            }, { passive: true });

            this.track.addEventListener('touchend', (e) => {
                this.touchEndX = e.changedTouches[0].screenX;
                this.handleSwipe();
                this.restartAuto();
            }, { passive: true });

            this.track.addEventListener('mouseenter', () => this.stopAuto());
            this.track.addEventListener('mouseleave', () => this.restartAuto());

            this.startProgress();
            this.startAuto();
        },

        goTo(index) {
            if (index < 0) index = this.total - 1;
            if (index >= this.total) index = 0;
            this.current = index;
            this.track.style.transform = 'translateX(-' + (index * 100) + '%)';
            this.slides.forEach(s => s.classList.remove('active'));
            if (this.slides[index]) this.slides[index].classList.add('active');
        },

        next() { this.goTo(this.current + 1); },
        prev() { this.goTo(this.current - 1); },

        handleSwipe() {
            const diff = this.touchStartX - this.touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0) this.next();
                else this.prev();
            }
        },

        startProgress() {
            if (!this.progressBar) return;
            this.progressBar.classList.remove('animating');
            this.progressBar.style.width = '0%';
            requestAnimationFrame(() => {
                requestAnimationFrame(() => {
                    this.progressBar.classList.add('animating');
                    this.progressBar.style.transitionDuration = this.duration + 'ms';
                    this.progressBar.style.width = '100%';
                });
            });
        },

        startAuto() {
            this.stopAuto();
            this.timer = setInterval(() => {
                this.next();
                this.startProgress();
            }, this.duration);
        },

        stopAuto() {
            if (this.timer) { clearInterval(this.timer); this.timer = null; }
            if (this.progressBar) {
                var w = this.progressBar.getBoundingClientRect().width;
                var pw = this.progressBar.parentElement.getBoundingClientRect().width;
                this.progressBar.classList.remove('animating');
                this.progressBar.style.width = (pw > 0 ? (w / pw * 100) : 0) + '%';
            }
        },

        restartAuto() {
            this.startProgress();
            this.startAuto();
        }
    };

    // ============================================================
    // 2. INFINITE SCROLL
    // ============================================================
    const feed = {
        list: document.getElementById('g1-feed-list'),
        loader: document.getElementById('g1-load-more'),
        sentinel: document.getElementById('g1-scroll-sentinel'),
        loading: false,
        hasMore: true,
        category: 'todas',
        exclude: [],
        observer: null,
        totalRendered: 0,
        tableInjected: false,

        init() {
            if (!this.list || !this.sentinel) return;

            var config = window.ldbFeedConfig || {};
            this.exclude = (config.exclude || []).slice();

            this.totalRendered = this.list.querySelectorAll('.g1-fullcard').length;

            if (this.totalRendered >= 5 && !this.tableInjected) {
                this.injectMobileTable();
            }

            this.observer = new IntersectionObserver(function(entries) {
                if (entries[0].isIntersecting && !feed.loading && feed.hasMore) {
                    feed.loadMore();
                }
            }, { rootMargin: '400px' });

            this.observer.observe(this.sentinel);
        },

        injectMobileTable() {
            if (this.tableInjected) return;
            this.tableInjected = true;

            var cards = this.list.querySelectorAll('.g1-fullcard');
            if (cards.length < 5) return;

            var fifthCard = cards[4];
            var tableDiv = document.createElement('div');
            tableDiv.className = 'g1-inline-table-mobile';
            tableDiv.innerHTML = '<div class="g1-sidebar-widget"><div class="g1-widget-header"><h3 class="g1-widget-title" style="font-family:Oswald,sans-serif;font-size:14px;font-weight:600;text-transform:uppercase">Classificação</h3><a href="/tabela/" class="g1-widget-link" style="font-family:Oswald,sans-serif;font-size:11px;color:#C41E2A;text-transform:uppercase;font-weight:500">Completa →</a></div><div id="g1-inline-tabela-mobile"><div class="ldb-loading"><div class="ldb-spinner"></div></div></div></div>';

            fifthCard.after(tableDiv);

            var savedHtml = window._ldbTabelaHtml;
            var desktopTable = document.getElementById('g1-inline-tabela');
            var source = savedHtml || (desktopTable ? desktopTable.innerHTML : '');
            if (source) {
                var mc = document.getElementById('g1-inline-tabela-mobile');
                if (mc) mc.innerHTML = source;
            }
        },

        async loadMore() {
            if (this.loading || !this.hasMore) return;
            this.loading = true;
            if (this.loader) this.loader.classList.remove('hidden');

            var config = window.ldbFeedConfig || {};
            var formData = new FormData();
            formData.append('action', 'ldb_load_more');
            formData.append('nonce', config.nonce || '');
            formData.append('per_page', '8');
            formData.append('category', this.category);

            for (var i = 0; i < this.exclude.length; i++) {
                formData.append('exclude[]', this.exclude[i]);
            }

            try {
                var response = await fetch(config.ajaxUrl || '/wp-admin/admin-ajax.php', {
                    method: 'POST',
                    body: formData,
                });

                var data = await response.json();

                if (data.success && data.data.posts && data.data.posts.length > 0) {
                    var posts = data.data.posts;

                    for (var j = 0; j < posts.length; j++) {
                        if (posts[j].id && this.exclude.indexOf(posts[j].id) === -1) {
                            this.exclude.push(posts[j].id);
                        }
                    }

                    this.renderPosts(posts);
                    this.hasMore = data.data.has_more;
                } else {
                    this.hasMore = false;
                }
            } catch (error) {
                console.warn('Feed load error:', error);
                this.hasMore = false;
            }

            this.loading = false;

            if (!this.hasMore) {
                this.showEndMessage();
            } else {
                if (this.loader) this.loader.classList.remove('hidden');
            }
        },

        renderPosts(posts) {
            const fragment = document.createDocumentFragment();

            posts.forEach(post => {
                this.totalRendered++;

                const article = document.createElement('article');
                article.className = 'g1-fullcard';
                article.dataset.category = post.cat_slug || '';

                const catClass = this.getCatClass(post.category);

                let imgHtml = '';
                if (post.thumbnail) {
                    imgHtml = `
                        <div class="g1-fullcard-img">
                            <img src="${post.thumbnail}" alt="${this.escapeHtml(post.title)}" 
                                 width="600" height="340" loading="lazy" decoding="async">
                        </div>
                    `;
                }

                let catHtml = '';
                if (post.category) {
                    catHtml = `<span class="g1-fullcard-cat ${catClass}">${this.escapeHtml(post.category)}</span>`;
                }

                let catMeta = '';
                if (post.category) {
                    catMeta = `<span class="g1-fullcard-sep">&middot;</span><span>Em ${this.escapeHtml(post.category)}</span>`;
                }

                article.innerHTML = `
                    <a href="${post.url}" class="g1-fullcard-link">
                        ${imgHtml}
                        <div class="g1-fullcard-body">
                            ${catHtml}
                            <h3 class="g1-fullcard-title">${this.escapeHtml(post.title)}</h3>
                            <div class="g1-fullcard-meta">
                                <span>${post.time_ago}</span>
                                ${catMeta}
                            </div>
                        </div>
                    </a>
                `;

                fragment.appendChild(article);
            });

            this.list.appendChild(fragment);

            if (this.totalRendered >= 5 && !this.tableInjected) {
                this.injectMobileTable();
            }
        },

        getCatClass(catName) {
            if (!catName) return 'cat-default';
            const lower = catName.toLowerCase();
            if (lower.includes('vitória') || lower.includes('vitoria')) return 'cat-vitoria';
            if (lower.includes('nacional') || lower.includes('brasileir')) return 'cat-nacional';
            if (lower.includes('internacional') || lower.includes('champions') || lower.includes('europa')) return 'cat-internacional';
            return 'cat-default';
        },

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text || '';
            return div.innerHTML;
        },

        showLoader(show) {
            if (this.loader) {
                this.loader.classList.toggle('hidden', !show);
            }
        },

        showEndMessage() {
            if (this.loader) this.loader.classList.add('hidden');
            if (this.observer) this.observer.disconnect();

            // Remove sentinel
            if (this.sentinel) this.sentinel.style.display = 'none';

            var endDiv = document.createElement('div');
            endDiv.className = 'g1-feed-end';
            endDiv.innerHTML = '<span class="g1-feed-end-line"></span><span class="g1-feed-end-text">Todas as notícias foram carregadas</span><span class="g1-feed-end-line"></span>';

            var feedEl = document.getElementById('g1-feed');
            if (feedEl) feedEl.appendChild(endDiv);
        },

        reset(category) {
            this.category = category;
            this.hasMore = true;
            this.list.innerHTML = '';
            this.showLoader(true);
            this.loadMore();
        }
    };

    // ============================================================
    // 3. CATEGORY FILTER
    // ============================================================
    const filterBtns = document.querySelectorAll('.g1-filter-btn');
    filterBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            filterBtns.forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            const category = this.dataset.cat;

            // Se "todas", mostrar todos e reiniciar feed
            if (category === 'todas') {
                document.querySelectorAll('.g1-feed-card').forEach(c => c.style.display = '');
                feed.category = 'todas';
                feed.page = 2;
                feed.hasMore = true;
            } else {
                // Filtrar os existentes no DOM
                document.querySelectorAll('.g1-feed-card').forEach(card => {
                    card.style.display = card.dataset.category === category ? '' : 'none';
                });

                // Reconfigurar feed para carregar apenas dessa categoria
                feed.category = category;
                feed.page = 2;
                feed.hasMore = true;
            }
        });
    });

    // ============================================================
    // 4. MOBILE NAV
    // ============================================================
    const mobileToggle = document.querySelector('.ldb-mobile-toggle');
    const mobileNav = document.getElementById('ldb-mobile-nav');
    const mobileClose = document.querySelector('.ldb-mobile-close');
    let overlay = null;

    function openMobileNav() {
        if (!mobileNav) return;
        mobileNav.classList.add('active');
        mobileNav.removeAttribute('inert');
        document.body.style.overflow = 'hidden';

        if (!overlay) {
            overlay = document.createElement('div');
            overlay.className = 'ldb-mobile-overlay';
            document.body.appendChild(overlay);
        }
        requestAnimationFrame(() => overlay.classList.add('active'));
        overlay.addEventListener('click', closeMobileNav);
    }

    function closeMobileNav() {
        if (!mobileNav) return;
        mobileNav.classList.remove('active');
        mobileNav.setAttribute('inert', '');
        document.body.style.overflow = '';
        if (overlay) overlay.classList.remove('active');
    }

    if (mobileToggle) mobileToggle.addEventListener('click', openMobileNav);
    if (mobileClose) mobileClose.addEventListener('click', closeMobileNav);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeMobileNav(); });

    // ============================================================
    // 5. HEADER SCROLL
    // ============================================================
    const header = document.querySelector('.ldb-header');
    let lastScroll = 0;

    window.addEventListener('scroll', () => {
        const scrollY = window.scrollY;

        if (header) {
            if (scrollY > 60) header.classList.add('ldb-header-scrolled');
            else header.classList.remove('ldb-header-scrolled');

            if (scrollY > lastScroll && scrollY > 200) header.classList.add('ldb-header-hidden');
            else header.classList.remove('ldb-header-hidden');
        }

        lastScroll = scrollY;
    }, { passive: true });

    // ============================================================
    // 6. SHARE POPUPS
    // ============================================================
    document.querySelectorAll('.ldb-share-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            if (this.href && (this.href.includes('whatsapp') || this.href.includes('t.me'))) return;
            e.preventDefault();
            const w = 600, h = 400;
            window.open(this.href, 'share', `width=${w},height=${h},left=${(screen.width-w)/2},top=${(screen.height-h)/2}`);
        });
    });

    // ============================================================
    // 7. CTA FIXTURES (lazy load on click)
    // ============================================================
    const fixturesBtn = document.getElementById('g1-fixtures-btn');
    const fixturesPanel = document.getElementById('g1-fixtures-panel');
    let fixturesLoaded = false;

    if (fixturesBtn && fixturesPanel) {
        fixturesBtn.addEventListener('click', function() {
            const expanded = this.getAttribute('aria-expanded') === 'true';

            if (expanded) {
                fixturesPanel.style.display = 'none';
                this.setAttribute('aria-expanded', 'false');
            } else {
                fixturesPanel.style.display = 'block';
                this.setAttribute('aria-expanded', 'true');

                if (!fixturesLoaded) {
                    fixturesLoaded = true;
                    if (typeof loadFixturesOnDemand === 'function') {
                        loadFixturesOnDemand();
                    } else {
                        document.dispatchEvent(new CustomEvent('ldb:loadFixtures'));
                    }
                }
            }
        });
    }

    // ============================================================
    // INIT
    // ============================================================
    function init() {
        carousel.init();
        feed.init();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
