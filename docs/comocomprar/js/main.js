/**
 * ComoComprar - Main JavaScript
 * Minimal, deferred, performance-first
 *
 * @package ComoComprar
 */

(function () {
  'use strict';

  // ─── MOBILE MENU ─────────────────────────────────────
  var menuToggle = document.getElementById('cc-menu-toggle');
  var mobileNav = document.getElementById('cc-mobile-nav');

  if (menuToggle && mobileNav) {
    menuToggle.addEventListener('click', function () {
      var isOpen = mobileNav.classList.toggle('is-open');
      menuToggle.setAttribute('aria-expanded', isOpen);
      mobileNav.setAttribute('aria-hidden', !isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });
  }

  // ─── DESKTOP DROPDOWN NAV ────────────────────────────
  var navItems = document.querySelectorAll('.cc-nav__item--has-sub');

  navItems.forEach(function (item) {
    var timeout;

    // Open on hover (desktop)
    item.addEventListener('mouseenter', function () {
      clearTimeout(timeout);
      // Close others
      navItems.forEach(function (other) {
        if (other !== item) other.classList.remove('is-open');
      });
      item.classList.add('is-open');
    });

    item.addEventListener('mouseleave', function () {
      timeout = setTimeout(function () {
        item.classList.remove('is-open');
      }, 150);
    });

    // Also handle click/keyboard for accessibility
    var trigger = item.querySelector('a, .cc-nav__trigger');
    if (trigger) {
      trigger.addEventListener('click', function (e) {
        // If it's a span trigger (no href), toggle dropdown
        if (trigger.tagName === 'SPAN') {
          e.preventDefault();
          item.classList.toggle('is-open');
        }
      });

      trigger.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          if (trigger.tagName === 'SPAN') {
            e.preventDefault();
            item.classList.toggle('is-open');
          }
        }
      });
    }
  });

  // Close dropdowns on click outside
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.cc-nav__item--has-sub')) {
      navItems.forEach(function (item) {
        item.classList.remove('is-open');
      });
    }
  });

  // ─── MOBILE SUBMENU TOGGLES ─────────────────────────
  var mobileToggles = document.querySelectorAll('.cc-mobile-nav__toggle');

  mobileToggles.forEach(function (btn) {
    btn.addEventListener('click', function () {
      var expanded = btn.getAttribute('aria-expanded') === 'true';
      var sub = btn.closest('.cc-mobile-nav__item').querySelector('.cc-mobile-nav__sub');

      // Close all others
      mobileToggles.forEach(function (other) {
        if (other !== btn) {
          other.setAttribute('aria-expanded', 'false');
          var otherSub = other.closest('.cc-mobile-nav__item').querySelector('.cc-mobile-nav__sub');
          if (otherSub) otherSub.classList.remove('is-open');
        }
      });

      // Toggle current
      btn.setAttribute('aria-expanded', !expanded);
      if (sub) sub.classList.toggle('is-open', !expanded);
    });
  });

  // ─── SEARCH OVERLAY ──────────────────────────────────
  var searchOpen = document.getElementById('cc-search-open');
  var searchOverlay = document.getElementById('cc-search-overlay');

  if (searchOpen && searchOverlay) {
    searchOpen.addEventListener('click', function () {
      searchOverlay.classList.add('is-open');
      var input = searchOverlay.querySelector('input');
      if (input) input.focus();
    });

    searchOverlay.addEventListener('click', function (e) {
      if (e.target === searchOverlay) {
        searchOverlay.classList.remove('is-open');
      }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && searchOverlay.classList.contains('is-open')) {
        searchOverlay.classList.remove('is-open');
      }
    });
  }

  // ─── COMBINED SCROLL HANDLER ──────────────────────────
  // Single rAF listener for: header hide/show, progress bar, back-to-top
  var header = document.querySelector('.cc-header');
  var progressBar = document.getElementById('cc-progress');
  var backToTop = document.getElementById('cc-back-to-top');
  var lastScroll = 0;
  var scrollTicking = false;

  function onScroll() {
    var currentScroll = window.scrollY || document.documentElement.scrollTop;

    // ── Header hide/show via CSS class ──
    if (header) {
      if (currentScroll > 300 && currentScroll > lastScroll) {
        header.classList.add('is-hidden');
      } else {
        header.classList.remove('is-hidden');
      }
    }

    // ── Reading progress bar (lives inside header, moves with it) ──
    if (progressBar) {
      var article = document.querySelector('.cc-content');
      if (article) {
        var articleTop = article.offsetTop;
        var articleHeight = article.offsetHeight;
        var viewportHeight = window.innerHeight;

        var progress = Math.min(
          100,
          Math.max(0, ((currentScroll - articleTop + viewportHeight * 0.3) / articleHeight) * 100)
        );

        progressBar.style.width = progress + '%';
      }
    }

    // ── Back to top button ──
    if (backToTop) {
      if (currentScroll > 600) {
        backToTop.classList.add('is-visible');
      } else {
        backToTop.classList.remove('is-visible');
      }
    }

    lastScroll = currentScroll;
    scrollTicking = false;
  }

  window.addEventListener(
    'scroll',
    function () {
      if (!scrollTicking) {
        requestAnimationFrame(onScroll);
        scrollTicking = true;
      }
    },
    { passive: true }
  );

  // ── Back to top click ──
  if (backToTop) {
    backToTop.addEventListener('click', function () {
      window.scrollTo({ top: 0, behavior: 'smooth' });
    });
  }

  // ─── FADE-IN ON SCROLL ───────────────────────────────
  var fadeEls = document.querySelectorAll('.cc-fade-in');

  if ('IntersectionObserver' in window && fadeEls.length) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            observer.unobserve(entry.target);
          }
        });
      },
      { rootMargin: '50px 0px 0px 0px', threshold: 0.01 }
    );

    fadeEls.forEach(function (el) {
      observer.observe(el);
    });

    // Safety fallback: force-show all after 3s in case observer doesn't fire
    setTimeout(function () {
      fadeEls.forEach(function (el) {
        if (!el.classList.contains('is-visible')) {
          el.classList.add('is-visible');
        }
      });
    }, 3000);
  } else {
    // No IntersectionObserver support — show immediately
    fadeEls.forEach(function (el) {
      el.classList.add('is-visible');
    });
  }

  // ─── LOAD MORE POSTS (AJAX) ──────────────────────────
  var loadMoreBtn = document.getElementById('cc-load-more');

  if (loadMoreBtn && typeof ccAjax !== 'undefined') {
    loadMoreBtn.addEventListener('click', function () {
      var page = parseInt(loadMoreBtn.dataset.page, 10);
      var max = parseInt(loadMoreBtn.dataset.max, 10);

      if (page >= max) {
        loadMoreBtn.style.display = 'none';
        return;
      }

      loadMoreBtn.textContent = 'Carregando...';
      loadMoreBtn.disabled = true;

      var data = new FormData();
      data.append('action', 'cc_load_more');
      data.append('nonce', ccAjax.nonce);
      data.append('page', page);

      fetch(ccAjax.url, {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
      })
        .then(function (response) {
          return response.text();
        })
        .then(function (html) {
          if (html.trim()) {
            var grid = document.querySelector('.cc-post-grid');
            if (grid) {
              grid.insertAdjacentHTML('beforeend', html);

              var newEls = grid.querySelectorAll('.cc-fade-in:not(.is-visible)');
              if ('IntersectionObserver' in window && newEls.length) {
                var obs = new IntersectionObserver(
                  function (entries) {
                    entries.forEach(function (entry) {
                      if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        obs.unobserve(entry.target);
                      }
                    });
                  },
                  { rootMargin: '0px 0px -40px 0px', threshold: 0.1 }
                );
                newEls.forEach(function (el) {
                  obs.observe(el);
                });
              }
            }

            loadMoreBtn.dataset.page = page + 1;
            loadMoreBtn.textContent = 'Carregar mais artigos';
            loadMoreBtn.disabled = false;

            if (page + 1 >= max) {
              loadMoreBtn.style.display = 'none';
            }
          } else {
            loadMoreBtn.style.display = 'none';
          }
        })
        .catch(function () {
          loadMoreBtn.textContent = 'Erro. Tente novamente.';
          loadMoreBtn.disabled = false;
        });
    });
  }

  // ─── COPY LINK BUTTON ────────────────────────────────
  var copyBtn = document.getElementById('cc-copy-link');
  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var url = copyBtn.getAttribute('data-url');
      if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () {
          copyBtn.classList.add('is-copied');
          var textEl = copyBtn.querySelector('.cc-share__btn-text');
          if (textEl) textEl.textContent = 'Copiado!';
          setTimeout(function () {
            copyBtn.classList.remove('is-copied');
            if (textEl) textEl.textContent = 'Copiar link';
          }, 2000);
        });
      }
    });
  }

  // ─── RESPONSIVE TABLES ───────────────────────────────
  // 1. Auto-add data-label attributes from thead for mobile card layout
  // 2. Detect scrollable tables and add .is-scrollable class for fade hint
  var tables = document.querySelectorAll('.cc-content table');

  tables.forEach(function (table) {
    // Get header labels
    var headers = [];
    var thEls = table.querySelectorAll('thead th');
    thEls.forEach(function (th) {
      headers.push(th.textContent.trim());
    });

    // Apply data-label to each td
    if (headers.length) {
      var rows = table.querySelectorAll('tbody tr');
      rows.forEach(function (row) {
        var cells = row.querySelectorAll('td');
        cells.forEach(function (td, i) {
          if (headers[i]) {
            td.setAttribute('data-label', headers[i]);
          }
        });
      });
    }

    // Check if table is scrollable (wider than its container)
    var wrapper = table.closest('.wp-block-table') || table.closest('figure.wp-block-table');
    if (wrapper) {
      function checkScroll() {
        if (wrapper.scrollWidth > wrapper.clientWidth + 2) {
          wrapper.classList.add('is-scrollable');
        } else {
          wrapper.classList.remove('is-scrollable');
        }
      }
      checkScroll();
      window.addEventListener('resize', checkScroll, { passive: true });
    }
  });

  // ─── INLINE POST: URL UPDATE + ANALYTICS ─────────────
  // When the inline (next) post scrolls into view,
  // update the browser URL and fire a virtual pageview.
  var inlinePost = document.querySelector('.cc-single--inline');

  if (inlinePost && 'IntersectionObserver' in window) {
    var inlineUrl = inlinePost.getAttribute('data-url');
    var inlineTitle = inlinePost.getAttribute('data-title');
    var originalUrl = window.location.href;
    var originalTitle = document.title;
    var hasSwapped = false;

    var inlineObserver = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting && !hasSwapped) {
            // Update URL without reload
            if (inlineUrl && window.history.pushState) {
              window.history.pushState({ inline: true }, inlineTitle, inlineUrl);
              document.title = inlineTitle;
            }

            // Fire Analytics pageview (GA4 / gtag)
            if (typeof gtag === 'function') {
              gtag('event', 'page_view', {
                page_location: inlineUrl,
                page_title: inlineTitle
              });
            }
            // Google Analytics Universal (legacy)
            if (typeof ga === 'function') {
              ga('set', 'page', inlineUrl);
              ga('send', 'pageview');
            }

            hasSwapped = true;
          }
        });
      },
      { threshold: 0.15 }
    );

    inlineObserver.observe(inlinePost);

    // Restore original URL when user scrolls back up past the separator
    var separator = document.querySelector('.cc-next-post-separator');
    if (separator) {
      var restoreObserver = new IntersectionObserver(
        function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting && hasSwapped) {
              window.history.pushState(null, originalTitle, originalUrl);
              document.title = originalTitle;
              hasSwapped = false;
            }
          });
        },
        { threshold: 0.5 }
      );
      restoreObserver.observe(separator);
    }
  }
})();
