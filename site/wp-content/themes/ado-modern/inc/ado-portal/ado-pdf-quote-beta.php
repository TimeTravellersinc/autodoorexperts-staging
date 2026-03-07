<?php
if (!defined('ABSPATH')) {
    exit;
}

function ado_pdf_quote_beta_shortcode(): string
{
    if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
        return '<p>Reviewer access required.</p>';
    }

    $admin_url = admin_url('admin.php?page=ado-pdf-quote-beta');
    return '<div class="ado-beta-reviewer-link"><p>Open the beta PDF quote reviewer in the WordPress admin.</p><p><a class="button" href="' . esc_url($admin_url) . '">Open Reviewer</a></p></div>';
}
add_shortcode('ado_pdf_quote_beta_reviewer', 'ado_pdf_quote_beta_shortcode');
