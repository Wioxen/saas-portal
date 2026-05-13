</main><!-- #main-content -->

<footer class="cc-footer" role="contentinfo">
    <div class="cc-container">
        <div class="cc-footer__grid">
            <!-- Brand -->
            <div class="cc-footer__brand">
                <a href="<?php echo esc_url(home_url('/')); ?>" class="cc-logo" style="color:#fff;margin-bottom:.75rem;">
                    como<span>comprar</span>
                </a>
                <p><?php echo esc_html(get_bloginfo('description')); ?></p>
                <p style="margin-top:.75rem;">
                    <?php esc_html_e('Seu guia completo para compras inteligentes. Comparamos, analisamos e recomendamos os melhores produtos para você.', 'comocomprar'); ?>
                </p>
            </div>

            <!-- Navigation columns -->
            <div>
                <p class="cc-footer__col-title" role="heading" aria-level="2"><?php esc_html_e('Categorias', 'comocomprar'); ?></p>
                <?php
                wp_nav_menu([
                    'theme_location' => 'footer',
                    'container'      => false,
                    'depth'          => 1,
                    'fallback_cb'    => function() {
                        $cats = get_categories(['number' => 6, 'orderby' => 'count', 'order' => 'DESC']);
                        if ($cats) {
                            echo '<ul>';
                            foreach ($cats as $cat) {
                                printf('<li><a href="%s">%s</a></li>', esc_url(get_category_link($cat)), esc_html($cat->name));
                            }
                            echo '</ul>';
                        }
                    },
                ]);
                ?>
            </div>

            <div>
                <p class="cc-footer__col-title" role="heading" aria-level="2"><?php esc_html_e('Institucional', 'comocomprar'); ?></p>
                <ul>
                    <li><a href="<?php echo esc_url(home_url('/sobre')); ?>"><?php esc_html_e('Sobre Nós', 'comocomprar'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/contato')); ?>"><?php esc_html_e('Contato', 'comocomprar'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/politica-de-privacidade')); ?>"><?php esc_html_e('Privacidade', 'comocomprar'); ?></a></li>
                    <li><a href="<?php echo esc_url(home_url('/termos-de-uso')); ?>"><?php esc_html_e('Termos de Uso', 'comocomprar'); ?></a></li>
                </ul>
            </div>

            <div>
                <p class="cc-footer__col-title" role="heading" aria-level="2"><?php esc_html_e('Siga-nos', 'comocomprar'); ?></p>
                <ul>
                    <?php
                    $socials = ['instagram', 'twitter', 'facebook', 'youtube'];
                    foreach ($socials as $social) {
                        $url = get_theme_mod("cc_social_{$social}");
                        if ($url) {
                            printf('<li><a href="%s" target="_blank" rel="noopener">%s</a></li>', esc_url($url), ucfirst($social));
                        }
                    }
                    ?>
                </ul>

                <?php if (is_active_sidebar('footer-1')) : ?>
                    <?php dynamic_sidebar('footer-1'); ?>
                <?php endif; ?>
            </div>
        </div>

        <div class="cc-footer__bottom">
            <span>&copy; <?php echo date('Y'); ?> <?php bloginfo('name'); ?>. <?php esc_html_e('Todos os direitos reservados.', 'comocomprar'); ?></span>
            <span><?php esc_html_e('Alguns links podem gerar comissões para o site.', 'comocomprar'); ?></span>
        </div>
    </div>
</footer>

<!-- Back to top floating button -->
<button class="cc-back-to-top" id="cc-back-to-top" aria-label="<?php esc_attr_e('Voltar ao topo', 'comocomprar'); ?>">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M10 17a.75.75 0 01-.75-.75V5.612L5.29 9.77a.75.75 0 01-1.08-1.04l5.25-5.5a.75.75 0 011.08 0l5.25 5.5a.75.75 0 11-1.08 1.04l-3.96-4.158V16.25A.75.75 0 0110 17z" clip-rule="evenodd"/></svg>
</button>

<?php wp_footer(); ?>
</body>
</html>
