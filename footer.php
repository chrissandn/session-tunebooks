<footer>
    <div class="container ">
        <div class="row">
            <div class="col-12 d-flex flex-wrap justify-content-between gap-2">
                <div><a href="<?php echo esc_url(function_exists('help_page_url') ? help_page_url() : home_url('/')); ?>">Help</a></div>
                <div><?php echo wp_kses_post(get_theme_mod('isg_footer_note', '')); ?></div>
                <div><?php if ($impressum = isg_impressum_page()) : ?><a href="<?php echo esc_url(get_permalink($impressum)); ?>"><?php echo esc_html(get_the_title($impressum)); ?></a><?php endif; ?></div>
            </div>
        </div>


    </div>
</footer>
<?php wp_footer(); ?>
</body>
</html>