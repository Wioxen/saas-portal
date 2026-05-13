</main><!-- /.ldb-main -->

<!-- FOOTER -->
<footer class="ldb-footer" role="contentinfo">
    <div class="ldb-container">
        <div class="ldb-footer-grid">
            <!-- Coluna 1: Sobre -->
            <div class="ldb-footer-col">
                <div class="ldb-footer-brand">
                    <span class="ldb-logo-icon">LB</span>
                    <span class="ldb-logo-text">Leão<span>da</span>Barra</span>
                </div>
                <p class="ldb-footer-about">
                    <?php echo esc_html(get_bloginfo('description') ?: 'Notícias do Esporte Clube Vitória e futebol brasileiro e internacional. Tabelas, resultados ao vivo, bastidores e muito mais.'); ?>
                </p>
                <div class="ldb-footer-social">
                    <?php
                    $socials = ['facebook', 'twitter', 'instagram', 'youtube', 'tiktok', 'telegram'];
                    foreach ($socials as $social) {
                        $url = get_theme_mod("ldb_{$social}_url", '');
                        if ($url) {
                            echo '<a href="' . esc_url($url) . '" target="_blank" rel="noopener noreferrer" class="ldb-social-link" aria-label="' . esc_attr(ucfirst($social)) . '">';
                            echo '<span class="ldb-social-icon ldb-icon-' . $social . '"></span>';
                            echo '</a>';
                        }
                    }
                    ?>
                </div>
            </div>

            <!-- Coluna 2: Widget -->
            <div class="ldb-footer-col">
                <?php if (is_active_sidebar('footer-1')) : ?>
                    <?php dynamic_sidebar('footer-1'); ?>
                <?php else : ?>
                    <h4 class="ldb-footer-title">Navegação</h4>
                    <?php
                    wp_nav_menu([
                        'theme_location' => 'footer',
                        'container'      => false,
                        'menu_class'     => 'ldb-footer-menu',
                        'depth'          => 1,
                        'fallback_cb'    => function() {
                            echo '<ul class="ldb-footer-menu">';
                            echo '<li><a href="' . esc_url(home_url('/')) . '">Início</a></li>';
                            echo '<li><a href="' . esc_url(home_url('/sobre/')) . '">Sobre</a></li>';
                            echo '<li><a href="' . esc_url(home_url('/contato/')) . '">Contato</a></li>';
                            echo '<li><a href="' . esc_url(home_url('/politica-de-privacidade/')) . '">Privacidade</a></li>';
                            echo '</ul>';
                        },
                    ]);
                    ?>
                <?php endif; ?>
            </div>

            <!-- Coluna 3: Categorias dinâmicas -->
            <div class="ldb-footer-col">
                <?php if (is_active_sidebar('footer-2')) : ?>
                    <?php dynamic_sidebar('footer-2'); ?>
                <?php else : ?>
                    <h4 class="ldb-footer-title">Categorias</h4>
                    <ul class="ldb-footer-menu">
                        <?php
                        $footer_cats = get_categories([
                            'hide_empty' => true,
                            'orderby'    => 'count',
                            'order'      => 'DESC',
                            'number'     => 8,
                        ]);
                        foreach ($footer_cats as $fcat) :
                            ?>
                            <li><a href="<?php echo esc_url(get_category_link($fcat->term_id)); ?>"><?php echo esc_html($fcat->name); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <!-- Copyright -->
        <div class="ldb-footer-bottom">
            <p>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. Todos os direitos reservados.</p>
            <p class="ldb-footer-disclaimer">Conteúdo não oficial. Site feito por torcedores para torcedores.</p>
        </div>
    </div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
