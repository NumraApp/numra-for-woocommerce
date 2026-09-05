# Translations

Place compiled translation files here, named by text domain and locale:

    numra-for-woocommerce-fr_FR.mo
    numra-for-woocommerce-ar.mo

The POT template (`numra-for-woocommerce.pot`) is generated with WP-CLI:

    wp i18n make-pot . languages/numra-for-woocommerce.pot --domain=numra-for-woocommerce

WordPress loads the matching .mo automatically based on the site language.
Loco Translate, WPML, Polylang and TranslatePress all read from this directory
via the standard `load_plugin_textdomain()` mechanism — no extra integration
code is required in the plugin.
