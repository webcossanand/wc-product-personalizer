<?php
/*
Plugin Name: WooCommerce Product Personalizer
Description: Personalize WooCommerce products with custom elements.
Version: 1.0
Author: Your Name
Text Domain: wc-product-personalizer
*/

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class WC_Product_Personalizer
{

    public function __construct()
    {
        // Register product meta
        add_action('init', array($this, 'register_personalize_product_meta'));
        add_action('init', array($this, 'register_personalization_elements_meta'));

        // Add personalize checkbox meta box
        add_action('add_meta_boxes', array($this, 'add_personalize_checkbox_meta_box'));
        add_action('save_post_product', array($this, 'save_personalize_checkbox'));

        // Admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array($this, 'frontend_enqueue_scripts'));

        // AJAX handlers
        $this->ajax_handlers();



        add_filter('woocommerce_add_cart_item_data', array($this, 'add_personalization_data_to_cart_item'), 10, 2);
        add_filter('woocommerce_get_item_data', array($this, 'display_personalization_cart_item_data'), 10, 2);

        // Send email on order complete
        add_action('woocommerce_order_status_completed', array($this, 'send_personalization_email'));

        /* commnets added by narendra start */
        //customize now button added
        add_action('woocommerce_after_add_to_cart_button', array($this, 'render_customize_button'));
        //popup customize dispaly added
        // add_action('woocommerce_before_add_to_cart_button', array($this, 'render_customize_modal'));
        add_action('wp_footer', array($this, 'render_customize_modal'));

        add_filter('woocommerce_add_to_cart_validation', function ($passed) {

            if (empty($_POST['wc_personalize_payload'])) {
                wc_add_notice('Please customize your product.', 'error');
                return false;
            }

            return $passed;
        });
        /* commnets added by narendra end */
    }

    /* commnets added by narendra start */
    // popup cumize display
    public function render_customize_modal()
    {

        if (!is_product()) return;

        global $post;
        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);

        if ($enabled !== 'yes') return;

?>

        <div id="wc-customize-modal">

            <div class="wc-modal-overlay"></div>

            <div class="wc-modal-box">

                <div class="wc-modal-header">
                    <!-- <div class="drag-bar"></div> -->
                    <!-- <h4 class="wc-title">Customize</h4> -->
                    <span class="popup-product-title"></span>
                    <span class="wc-close">&times;</span>
                </div>

                <div class="wc-custom-header">

                    <div class="wc-left">
                        <!-- <h2 class="wc-title">Customise</h2> -->

                        <div class="popup-price"></div>

                        <div class="wc-delivery">
                            FREE delivery <strong>On limited products</strong>
                        </div>

                        <a href="#" class="wc-multi-design">Buying multiple designs?</a>
                    </div>


                    <div class="wc-right">

                        <button class="wc-add-cart">
                            Add to Cart
                        </button>

                        <button class="wc-add-list">
                            Add to List
                        </button>

                    </div>

                </div>

                <div class="row wc-modal-content">

                    <!-- LEFT PREVIEW -->
                    <div class="wc-preview-area">
                        <?php $this->display_personalization_overlay(); ?>
                    </div>

                    <!-- RIGHT OPTIONS -->
                    <div class="wc-options-area">
                        <?php $this->display_personalization_inputs(); ?>
                    </div>

                </div>

                <div class="wc-modal-footer">
                    <p>
                        Display is an approximate preview. By clicking “Customise now”, you agree to these <a href="/terms-conditions" target="_blank" rel="noopener noreferrer">Terms and Conditions</a>.
                    </p>
                </div>

            </div>

        </div>

    <?php
    }

    //customize now button
    public function render_customize_button()
    {

        global $post;

        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);

        echo '<button type="button" id="wc-customize-btn" 
                class="button alt"
                style="margin-top:10px;width:100%;">
                Customize Now
            </button>';
    }
    /* commnets added by narendra end */

    // Register meta for personalize checkbox
    public function register_personalize_product_meta()
    {
        register_post_meta('product', '_wc_personalize_enabled', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'string',
            'default' => 'no',
        ));
    }

    // Register meta for personalization elements
    public function register_personalization_elements_meta()
    {
        register_post_meta('product', '_wc_personalize_elements', array(
            'show_in_rest' => true,
            'single' => true,
            'type' => 'array',
            'default' => array(),
        ));
    }

    // Add checkbox meta box on product edit screen
    public function add_personalize_checkbox_meta_box()
    {
        add_meta_box(
            'wc_personalize_checkbox',
            __('Personalize Product', 'wc-product-personalizer'),
            array($this, 'render_personalize_checkbox_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    public function render_personalize_checkbox_meta_box($post)
    {
        wp_nonce_field('wc_personalize_save_checkbox', 'wc_personalize_nonce');
        $value = get_post_meta($post->ID, '_wc_personalize_enabled', true);
    ?>
        <label>
            <input type="checkbox" name="wc_personalize_enabled" value="yes" <?php checked($value, 'yes'); ?> />
            <?php esc_html_e('Enable Personalization', 'wc-product-personalizer'); ?>
        </label>
    <?php
    }

    public function save_personalize_checkbox($post_id)
    {
        if (! isset($_POST['wc_personalize_nonce']) || ! wp_verify_nonce($_POST['wc_personalize_nonce'], 'wc_personalize_save_checkbox')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (isset($_POST['wc_personalize_enabled']) && $_POST['wc_personalize_enabled'] === 'yes') {
            update_post_meta($post_id, '_wc_personalize_enabled', 'yes');
        } else {
            update_post_meta($post_id, '_wc_personalize_enabled', 'no');
        }
    }

    // Add admin menu page
    public function add_admin_menu()
    {
        add_menu_page(
            __('Product Personalizer', 'wc-product-personalizer'),
            __('Product Personalizer', 'wc-product-personalizer'),
            'manage_woocommerce',
            'wc-product-personalizer',
            array($this, 'render_personalize_product_list_page'),
            'dashicons-art',
            56
        );
    }

    // Render personalized product list page
    public function render_personalize_product_list_page()
    {
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_key' => '_wc_personalize_enabled',
            'meta_value' => 'yes',
        );
        $products = get_posts($args);
    ?>
        <div class="wrap wc-personalizer-admin">

            <div class="wc-admin-header">
                <h1><?php esc_html_e('Product Personalizer', 'wc-product-personalizer'); ?></h1>
                <p>Manage products with personalization enabled.</p>
            </div>

            <div class="wc-admin-card">
                <table class="wp-list-table widefat fixed striped wc-personalizer-table">
                    <thead>
                        <tr>
                            <th width="70"><?php esc_html_e('Image', 'wc-product-personalizer'); ?></th>
                            <th><?php esc_html_e('Product', 'wc-product-personalizer'); ?></th>
                            <th width="120"><?php esc_html_e('Status', 'wc-product-personalizer'); ?></th>
                            <th width="160"><?php esc_html_e('Actions', 'wc-product-personalizer'); ?></th>
                        </tr>
                    </thead>
                    <tbody>

                        <?php if ($products) : ?>
                            <?php foreach ($products as $product) : ?>
                                <tr>
                                    <td>
                                        <?php echo get_the_post_thumbnail($product->ID, 'thumbnail'); ?>
                                    </td>

                                    <td>
                                        <strong><?php echo esc_html(get_the_title($product->ID)); ?></strong>
                                    </td>

                                    <td>
                                        <span class="wc-status-badge enabled">
                                            Enabled
                                        </span>
                                    </td>

                                    <td>
                                        <a href="<?php echo get_edit_post_link($product->ID); ?>"
                                            class="button">
                                            Edit
                                        </a>

                                        <button class="button button-primary wc-personalize-edit-btn"
                                            data-product-id="<?php echo esc_attr($product->ID); ?>">
                                            Builder
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else : ?>
                            <tr>
                                <td colspan="4">
                                    <?php esc_html_e('No personalized products found.', 'wc-product-personalizer'); ?>
                                </td>
                            </tr>
                        <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>

        <!-- Modal -->
        <div id="wc-personalize-modal">
            <div id="wc-personalize-modal-content"></div>
        </div>
    <?php
    }

    // Enqueue admin scripts and styles
    public function admin_enqueue_scripts($hook)
    {
        if ($hook === 'toplevel_page_wc-product-personalizer') {
            wp_enqueue_style('wp-jquery-ui-dialog');
            wp_enqueue_script('jquery-ui-dialog');
            wp_enqueue_script('wc-personalizer-admin', plugin_dir_url(__FILE__) . 'assets/js/admin.js', array('jquery', 'jquery-ui-dialog'), '1.5.2', true);
            wp_localize_script('wc-personalizer-admin', 'wcPersonalizer', array(
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('wc_personalizer_nonce'),
            ));
            wp_enqueue_style('wc-personalizer-admin-css', plugin_dir_url(__FILE__) . 'assets/css/admin.css');
        }
    }

    // Enqueue frontend scripts and styles
    public function frontend_enqueue_scripts()
    {
        if (is_product()) {
            global $post;
            $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
            if ($enabled === 'yes') {
                wp_enqueue_script('wc-personalizer-frontend', plugin_dir_url(__FILE__) . 'assets/js/frontend.js', array('jquery'), '1.5.7', true);
                wp_enqueue_style('wc-personalizer-frontend-css', plugin_dir_url(__FILE__) . 'assets/css/frontend.css');
                wp_localize_script('wc-personalizer-frontend', 'wcPersonalizer', array(
                    'ajax_url' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('wc_personalizer_nonce'),
                ));
            }
        }
    }

    // Register AJAX handlers
    public function ajax_handlers()
    {
        // frontend + admin
        add_action('wp_ajax_wc_personalizer_get_product_data', [$this, 'get_product_data']);
        add_action('wp_ajax_nopriv_wc_personalizer_get_product_data', [$this, 'get_product_data']);

        add_action('wp_ajax_wc_personalizer_save_elements', [$this, 'save_elements']);
        // probably admin-only, but add nopriv if you call it publically:
        // add_action( 'wp_ajax_nopriv_wc_personalizer_save_elements', [ $this, 'save_elements' ] );

        add_action('wp_ajax_wc_personalizer_upload_image', [$this, 'handle_image_upload']);
        add_action('wp_ajax_nopriv_wc_personalizer_upload_image', [$this, 'handle_image_upload']);
    }


    // AJAX: Get product data for personalization editor
    public function get_product_data()
    {
        check_ajax_referer('wc_personalizer_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);

        if (! $product) {
            wp_send_json_error('Product not found');
        }

        $elements = get_post_meta($product_id, '_wc_personalize_elements', true);
        $image_url = get_the_post_thumbnail_url($product_id, 'full');

        wp_send_json_success(array(
            'id' => $product_id,
            'title' => get_the_title($product_id),
            'image_url' => $image_url,
            'elements' => $elements ?: array(),
        ));
    }

    // AJAX: Save personalization elements
    public function save_elements()
    {
        check_ajax_referer('wc_personalizer_nonce', 'nonce');

        $product_id = intval($_POST['product_id']);
        $elements = isset($_POST['elements']) ? $_POST['elements'] : array();

        if (! is_array($elements)) {
            wp_send_json_error('Invalid elements data');
        }

        update_post_meta($product_id, '_wc_personalize_elements', $elements);

        wp_send_json_success();
    }

    // AJAX: Handle image upload (optional, extend as needed)
    public function handle_image_upload()
    {
        check_ajax_referer('wc_personalizer_nonce', 'nonce');

        if (! empty($_FILES['image'])) {
            $file = $_FILES['image'];
            $upload = wp_handle_upload($file, array('test_form' => false));

            if (isset($upload['error'])) {
                wp_send_json_error($upload['error']);
            }

            wp_send_json_success(array('url' => $upload['url']));
        }

        wp_send_json_error('No file uploaded');
    }

    // Display personalization inputs after price (left side) - Enhanced with better visibility
    public function display_personalization_inputs()
    {
        global $post;
        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
        if ($enabled !== 'yes') {
            return;
        }

        $elements = get_post_meta($post->ID, '_wc_personalize_elements', true);
        if (! $elements || ! is_array($elements)) {
            echo '<p>' . esc_html__('No personalization elements defined.', 'wc-product-personalizer') . '</p>';
            return;
        }

        // Temporary debug: Check page source (Ctrl+U) for this comment - remove after testing
        echo '<!-- WC Personalizer: INPUTS LOADED - Elements Count: ' . count($elements) . ' -->';
        // echo "test";
    ?>
        <div class="wc-personalizer-inputs">
            <h6 style="margin: 10px 0;color:var(--e-global-color-text)"><?php esc_html_e('Personalize Your Product', 'wc-product-personalizer'); ?></h6>

            <!-- Inputs are part of the main WooCommerce form - no separate <form> needed -->
            <?php foreach ($elements as $element) :
                $input_name = 'wc_personalize[' . esc_attr($element['id']) . ']';
                $input_id = 'personalize_input_' . esc_attr($element['id']);
                $default_value = '';
                if (isset($_POST['wc_personalize'][$element['id']])) {
                    $default_value = sanitize_text_field($_POST['wc_personalize'][$element['id']]);
                } else {
                    if ($element['type'] === 'text') {
                        $default_value = $element['properties']['defaultText'] ?? '';
                    } elseif ($element['type'] === 'color') {
                        $default_value = $element['properties']['defaultColor'] ?? '#000000';
                    }
                }
            ?>
                <div class="personalization-input-group">
                    <label for="<?php echo esc_attr($input_id); ?>">
                        <?php echo esc_html($element['label']); ?>
                    </label>
                    <?php if ($element['type'] === 'text') : ?>
                        <input type="text" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($default_value); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="text" placeholder="Enter your text here..." />
                    <?php elseif ($element['type'] === 'color') : ?>
                        <input type="color" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($default_value); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="color" style="width: 60px; height: 40px; padding: 0; border: 1px solid #ccc; border-radius: 4px; cursor: pointer; vertical-align: middle;" />
                        <span style="margin-left: 10px; color: #666;">Choose color</span>
                    <?php elseif ($element['type'] === 'image') : ?>
                        <!-- <input type="file" name="wc_personalize_<?php echo esc_attr($element['id']); ?>" id="<?php echo esc_attr($input_id); ?>" accept="image/*" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="image" class="file-upload" />
                        <small style="color: #666; display: block; margin-top: 5px;">Upload an image (JPG, PNG, etc.)</small> -->
                        <div class="wc-upload-box">

                            <label class="wc-upload-label">
                                Upload Image
                                <input type="file"
                                    name="wc_personalize_<?php echo esc_attr($element['id']); ?>"
                                    id="<?php echo esc_attr($input_id); ?>"
                                    accept="image/*"
                                    data-element-id="<?php echo esc_attr($element['id']); ?>"
                                    data-type="image"
                                    class="file-upload"
                                    hidden />
                            </label>

                            <div class="wc-image-preview"></div>

                        </div>

                    <?php elseif ($element['type'] === 'font_size') : ?>
                        <select name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="font_size" style="width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 14px; background: #fff;">
                            <option value="small" <?php selected($default_value, 'small'); ?>><?php esc_html_e('Small', 'wc-product-personalizer'); ?></option>
                            <option value="medium" <?php selected($default_value, 'medium'); ?>><?php esc_html_e('Medium', 'wc-product-personalizer'); ?></option>
                            <option value="large" <?php selected($default_value, 'large'); ?>><?php esc_html_e('Large', 'wc-product-personalizer'); ?></option>
                        </select>
                    <?php else : ?>
                        <input type="text" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($default_value); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="text" />
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php
    }
    // Ultimate fallback: Inject inputs via footer JS if hooks fail
    public function inject_personalization_inputs()
    {
        if (! is_product()) return;

        global $post;
        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
        if ($enabled !== 'yes') return;

        $elements = get_post_meta($post->ID, '_wc_personalize_elements', true);
        if (! $elements || ! is_array($elements)) return;

        // Generate inputs HTML (same as display_personalization_inputs but as string)
        ob_start();

    ?>
        <div class="wc-personalizer-inputs" style="margin: 20px 0; padding: 20px; border: 2px solid #0073aa; border-radius: 8px; background: #f9f9f9; box-shadow: 0 2px 5px rgba(0,0,0,0.1);">
            <h3 style="margin: 0 0 20px 0; color: #0073aa; font-size: 18px; border-bottom: 1px solid #ddd; padding-bottom: 10px;"><?php esc_html_e('Personalize Your Product', 'wc-product-personalizer'); ?></h3>

            <?php

            foreach ($elements as $element) :
                $input_name = 'wc_personalize[' . esc_attr($element['id']) . ']';
                $input_id = 'personalize_input_' . esc_attr($element['id']);
                $default_value = $element['properties']['defaultText'] ?? $element['properties']['defaultColor'] ?? '';
            ?>
                <div class="personalization-input-group" style="margin-bottom: 20px; padding: 10px; background: #fff; border: 1px solid #eee; border-radius: 5px;">
                    <label for="<?php echo esc_attr($input_id); ?>" style="font-weight: bold; display: block; margin-bottom: 8px; color: #333; font-size: 14px;">
                        <?php echo esc_html($element['label']); ?>
                    </label>
                    <?php if ($element['type'] === 'text') : ?>
                        <input type="text" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($default_value); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="text" style="width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 14px;" />
                    <?php elseif ($element['type'] === 'color') : ?>
                        <input type="color" name="<?php echo esc_attr($input_name); ?>" id="<?php echo esc_attr($input_id); ?>" value="<?php echo esc_attr($default_value); ?>" data-element-id="<?php echo esc_attr($element['id']); ?>" data-type="color" style="width: 60px; height: 40px; padding: 0; border: 1px solid #ccc; border-radius: 4px; cursor: pointer;" />
                    <?php endif;/* Add other types similarly */ ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        $inputs_html = ob_get_clean();

        ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                if ($('.wc-personalizer-inputs').length > 0) return; // Already exists from hooks

                var $summary = $('.woocommerce-product-details__short-description, .product-summary, .summary.entry-summary').first();
                if ($summary.length === 0) $summary = $('.woocommerce-product-details__short-description').closest('.summary');
                if ($summary.length === 0) $summary = $('#product-' + <?php echo $post->ID; ?> + ' .summary');

                if ($summary.length) {
                    $summary.append('<?php echo wp_json_encode($inputs_html); ?>');
                    console.log('11 -Fallback Inputs Injected Successfully'); // Debug
                } else {
                    console.log('No summary container found for inputs injection'); // Debug
                }
            });
        </script>
    <?php
    }

    // Display personalization overlay on product image (right side) - Enhanced
    public function display_personalization_overlay()
    {
        global $post;
        global $product;

        if (! $product) return;

        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
        if ($enabled !== 'yes') {
            return;
        }

        $elements = get_post_meta($post->ID, '_wc_personalize_elements', true);
        if (! $elements || ! is_array($elements)) {
            return;
        }

        // Generate overlay HTML
        $overlay_html = $this->generate_overlay_html($elements);
        // echo "OVERLAY LOADED";
        // Output the container wrapper if not already present
        if (! has_action('woocommerce_single_product_image')) { // Avoid duplicates
            echo '<div class="wc-personalizer-image-container" 
            style="position:relative; margin:auto;">';
        }

        // ✅ PRODUCT IMAGE (YOU WERE MISSING THIS)
        echo $product->get_image('large', [
            'class' => 'woocommerce-product-gallery__image',
            'style' => 'width:100%; display:block;'
        ]);

        // Output overlay div
        echo '<div class="personalization-overlay" style="position:absolute;
                       top:0;
                       left:0;
                       width:100%;
                       height:100%;
                       pointer-events:none;
                       z-index:5;">' . $overlay_html . '</div>';

        // Inline JS to ensure positioning (runs immediately)

    ?>

        <script type="text/javascript">
            jQuery(document).ready(function($) {
                console.log('PHP Overlay Output Detected - Elements Count: <?php echo count($elements); ?>'); // Debug
                var $gallery = $('.woocommerce-product-gallery, .product-gallery, .product-images, .single-product-image').first();
                if ($gallery.length) {
                    $gallery.css('position', 'relative');
                    var $overlay = $gallery.find('.personalization-overlay');
                    if ($overlay.length) {
                        console.log('PHP Overlay positioned successfully'); // Debug
                    } else {
                        console.log('PHP Overlay not found in gallery - injecting...'); // Debug
                        $gallery.append('<?php echo wp_json_encode($overlay_html); ?>');
                        $gallery.find('.personalization-overlay').wrap('<div class="personalization-overlay-wrapper"></div>');
                    }
                }
            });
        </script>
<?php


        if (! has_action('woocommerce_single_product_image')) {
            echo '</div>'; // Close wrapper
        }
    }

    // Helper method to generate overlay HTML
    private function generate_overlay_html($elements)
    {

        error_log("Elements found : " . print_r($elements, true));
        $html = '';
        foreach ($elements as $element) {
            $style = sprintf(
                'position: absolute; left: %dpx; top: %dpx; width: %dpx; height: %dpx; font-size: 14px; color: #000; background: transparent; box-sizing: border-box;',
                intval($element['x'] ?? 0),
                intval($element['y'] ?? 0),
                intval($element['width'] ?? 100),
                intval($element['height'] ?? 30)
            );

            $content = '';
            switch ($element['type']) {
                case 'text':
                    $content = esc_html($element['properties']['defaultText'] ?? '');
                    break;
                case 'color':
                    $default_color = esc_attr($element['properties']['defaultColor'] ?? '#000');
                    $content = '<div style="width: 100%; height: 100%; background-color: ' . $default_color . ';"></div>';
                    break;
                case 'image':
                    $content = '<div style="width: 100%; height: 100%; background: #ccc; display: flex; align-items: center; justify-content: center; font-size: 12px; color: #666;">Image</div>';
                    break;
                case 'font_size':
                    $content = '<div style="font-size: 14px;">Font Size</div>';
                    break;
                default:
                    $content = esc_html($element['properties']['defaultText'] ?? '');
            }

            $html .= sprintf(
                '<div class="personalization-preview-element" data-id="%s" data-type="%s" data-original-left="%d" data-original-top="%d" data-original-width="%d" data-original-height="%d" style="%s">%s</div>',
                esc_attr($element['id']),
                esc_attr($element['type']),
                intval($element['x'] ?? 0),
                intval($element['y'] ?? 0),
                intval($element['width'] ?? 100),
                intval($element['height'] ?? 30),
                esc_attr($style),
                $content
            );
        }
        return $html;
    }

    // Ultimate fallback: Inject overlay via footer JS
    public function inject_personalization_overlay()
    {
        if (! is_product()) return;

        global $post;
        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
        if ($enabled !== 'yes') return;

        $elements = get_post_meta($post->ID, '_wc_personalize_elements', true);
        if (! $elements || ! is_array($elements)) return;

        $overlay_html = $this->generate_overlay_html($elements);
        /*
    ?>
        <script type="text/javascript">
            jQuery(document).ready(function($) {
                if ($('.personalization-overlay').length > 0) return; // Already exists

                var $gallery = $('.woocommerce-product-gallery, .product-gallery, .product-images, .single-product-image').first();
                if ($gallery.length) {
                    $gallery.css('position', 'relative');
                    $gallery.append('<div class="personalization-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none; z-index:9999;"><?php echo wp_json_encode($overlay_html); ?></div>');
                    console.log('Fallback Overlay Injected - Elements: <?php echo count($elements); ?>'); // Debug
                } else {
                    console.log('No gallery found for fallback injection'); // Debug
                }
            });
        </script>
<?php
*/
    }

    // Display personalization form on product page
    public function display_personalization_form()
    {
        global $post;
        $enabled = get_post_meta($post->ID, '_wc_personalize_enabled', true);
        if ($enabled !== 'yes') {
            return;
        }

        $elements = get_post_meta($post->ID, '_wc_personalize_elements', true);
        if (! $elements || ! is_array($elements)) {
            echo '<p>' . esc_html__('No personalization elements defined.', 'wc-product-personalizer') . '</p>';
            return;
        }

        $product_image = get_the_post_thumbnail_url($post->ID, 'full');

        echo '<div class="wc-personalizer-frontend">';
        echo '<div class="personalization-preview" style="position:relative; display:inline-block;">';
        echo '<img src="' . esc_url($product_image) . '" id="personalization-base-image" style="max-width: 100%; display: block;" />';
        echo '<div class="personalization-overlay" style="position:absolute; top:0; left:0; width:100%; height:100%;">';

        foreach ($elements as $element) {
            $style = sprintf(
                'position: absolute; left: %dpx; top: %dpx; width: %dpx; height: %dpx;',
                intval($element['x']),
                intval($element['y']),
                intval($element['width']),
                intval($element['height'])
            );

            switch ($element['type']) {
                case 'text':
                    printf(
                        '<input type="text" name="wc_personalize[%s]" class="personalization-element" style="%s" placeholder="%s" data-type="text" />',
                        esc_attr($element['id']),
                        esc_attr($style),
                        esc_attr($element['properties']['defaultText'] ?? '')
                    );
                    break;

                case 'color':
                    printf(
                        '<input type="color" name="wc_personalize[%s]" class="personalization-element" style="%s" value="%s" data-type="color" />',
                        esc_attr($element['id']),
                        esc_attr($style),
                        esc_attr($element['properties']['defaultColor'] ?? '#000000')
                    );
                    break;

                case 'image':
                    printf(
                        '<input type="file" name="wc_personalize_%s" class="personalization-element" style="%s" accept="image/*" data-type="image" />',
                        esc_attr($element['id']),
                        esc_attr($style)
                    );
                    break;

                case 'font_size':
                    // Example: render a select dropdown for font size
                    printf(
                        '<select name="wc_personalize[%s]" class="personalization-element" style="%s" data-type="font_size">
                            <option value="small">Small</option>
                            <option value="medium" selected>Medium</option>
                            <option value="large">Large</option>
                        </select>',
                        esc_attr($element['id']),
                        esc_attr($style)
                    );
                    break;

                default:
                    // Default to text input
                    printf(
                        '<input type="text" name="wc_personalize[%s]" class="personalization-element" style="%s" data-type="text" />',
                        esc_attr($element['id']),
                        esc_attr($style)
                    );
                    break;
            }
        }

        echo '</div></div></div>';
    }


    // Add personalization data to cart item
    public function add_personalization_data_to_cart_item($cart_item_data, $product_id)
    {
        if (isset($_POST['wc_personalize']) && is_array($_POST['wc_personalize'])) {
            $clean = array_map('sanitize_text_field', $_POST['wc_personalize']);
            $cart_item_data['wc_personalize'] = $clean;
            // Add unique key to force cart item uniqueness
            $cart_item_data['unique_key'] = md5(microtime() . rand());
        }
        return $cart_item_data;
    }

    // Display personalization data in cart and checkout
    public function display_personalization_cart_item_data($item_data, $cart_item)
    {

        if (isset($cart_item['wc_personalize'])) {
            foreach ($cart_item['wc_personalize'] as $key => $value) {

                // ✅ REMOVE preview from cart
                if ($key === 'preview') {
                    continue;
                }

                // Optional: remove payload + time also
                if ($key === 'payload' || $key === 'time') {
                    continue;
                }

                $item_data[] = array(
                    'key' => ucfirst($key),
                    'value' => is_array($value) ? implode(', ', $value) : $value,
                );
            }
        }
        return $item_data;
    }

    // Send email with personalization details on order complete
    public function send_personalization_email($order_id)
    {
        $order = wc_get_order($order_id);
        if (! $order) {
            return;
        }

        $items = $order->get_items();
        $message = "Personalized Products Details:\n\n";

        foreach ($items as $item) {
            $product = $item->get_product();
            $personalize_data = $item->get_meta('wc_personalize');
            if ($personalize_data) {
                $message .= "Product: " . $product->get_name() . "\n";
                foreach ($personalize_data as $key => $value) {
                    $message .= ucfirst($key) . ": " . $value . "\n";
                }
                $message .= "\n";
            }
        }

        if ($message !== "Personalized Products Details:\n\n") {
            $admin_email = get_option('admin_email');
            wp_mail($admin_email, 'Personalized Product Order #' . $order_id, $message);
        }
    }
}
// =====  PERSONALIZER: helpers  =====
function wcpp_write_base64_png($data_url, $prefix = 'wcpp')
{
    if (empty($data_url) || strpos($data_url, 'data:image/png;base64,') !== 0) return false;

    $data = base64_decode(substr($data_url, strlen('data:image/png;base64,')));
    if (! $data) return false;

    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'wc-personalizer';
    if (! file_exists($dir)) wp_mkdir_p($dir);

    $filename = $prefix . '-' . time() . '-' . wp_generate_password(8, false) . '.png';
    $path = trailingslashit($dir) . $filename;
    file_put_contents($path, $data);

    $url = trailingslashit($upload['baseurl']) . 'wc-personalizer/' . $filename;
    return array('path' => $path, 'url' => $url, 'filename' => $filename);
}

function wcpp_handle_uploaded_images_for_elements($elements)
{
    // Attach uploaded files (from <input type="file" name="wc_personalize[<id>]">)
    if (empty($_FILES['wc_personalize']) || empty($elements)) return $elements;

    $files = $_FILES['wc_personalize'];
    foreach ($elements as &$el) {
        if ($el['type'] !== 'image') continue;
        $id = $el['id'];
        if (isset($files['name'][$id]) && ! empty($files['name'][$id])) {
            $file_array = array(
                'name'     => $files['name'][$id],
                'type'     => $files['type'][$id],
                'tmp_name' => $files['tmp_name'][$id],
                'error'    => $files['error'][$id],
                'size'     => $files['size'][$id],
            );
            // Let WP move it to uploads
            $overrides = array('test_form' => false);
            $handled = wp_handle_upload($file_array, $overrides);
            if (empty($handled['error'])) {
                $el['value']['image_url'] = $handled['url'];
                $el['value']['image_file'] = $handled['file'];
            }
        }
    }
    return $elements;
}

// =====  hook: add data to cart item  =====
add_filter('woocommerce_add_cart_item_data', function ($cart_item_data, $product_id, $variation_id) {
    // JSON of current values from the page
    $payload = isset($_POST['wc_personalize_payload']) ? wp_unslash($_POST['wc_personalize_payload']) : '';
    $payload_arr = array();
    if ($payload) {
        $decoded = json_decode($payload, true);
        if (is_array($decoded)) $payload_arr = $decoded;
    }

    // Canvas preview
    $render = isset($_POST['wc_personalize_render']) ? $_POST['wc_personalize_render'] : '';
    $saved = $render ? wcpp_write_base64_png($render, 'preview') : false;

    // Attach uploaded files (image element)
    if (! empty($payload_arr['elements'])) {
        $payload_arr['elements'] = wcpp_handle_uploaded_images_for_elements($payload_arr['elements']);
    }

    if (! empty($payload_arr) || $saved) {
        $cart_item_data['wc_personalize'] = array(
            'preview'  => $saved ?: array(),
            'payload'  => $payload_arr,
            'time'     => time(),
        );
        // make unique so two different designs don't merge in cart
        $cart_item_data['wcpp_unique'] = md5(wp_json_encode($cart_item_data['wc_personalize']) . microtime(true));
    }

    return $cart_item_data;
}, 10, 3);

// =====  hook: show on cart/checkout  =====
add_filter('woocommerce_get_item_data', function ($item_data, $cart_item) {
    if (empty($cart_item['wc_personalize'])) return $item_data;
    $data = $cart_item['wc_personalize'];

    // 1) small preview
    if (! empty($data['preview']['url'])) {
        $item_data[] = array(
            'key'   => __('Personalized Preview', 'wcpp'),
            'value' => '<img src="' . esc_url($data['preview']['url']) . '" style="max-width:120px;height:auto;border:1px solid #eee;border-radius:4px;" />',
            'display' => '',
        );
    }

    // 2) details per element
    if (! empty($data['payload']['elements'])) {
        foreach ($data['payload']['elements'] as $el) {
            $label = ! empty($el['label']) ? $el['label'] : ucfirst($el['type']);
            if ($el['type'] === 'text') {
                $val = isset($el['value']['text']) ? $el['value']['text'] : '';
                $style = array();
                if (! empty($el['value']['fontFamily'])) $style[] = 'Font: ' . $el['value']['fontFamily'];
                if (! empty($el['value']['fontSize']))  $style[] = 'Size: ' . intval($el['value']['fontSize']);
                if (! empty($el['value']['color']))      $style[] = 'Color: ' . sanitize_text_field($el['value']['color']);
                $item_data[] = array(
                    'key'   => esc_html($label),
                    'value' => esc_html($val) . ($style ? '<br/><small>' . esc_html(implode(' • ', $style)) . '</small>' : ''),
                );
            } elseif ($el['type'] === 'color') {
                $clr = isset($el['value']['color']) ? $el['value']['color'] : ($el['properties']['defaultColor'] ?? '#000');
                $item_data[] = array(
                    'key'   => esc_html($label),
                    'value' => '<span style="display:inline-block;width:18px;height:18px;border:1px solid #ccc;background:' . esc_attr($clr) . ';"></span> ' . esc_html($clr),
                    'display' => '',
                );
            } elseif ($el['type'] === 'image') {
                if (! empty($el['value']['image_url'])) {
                    $item_data[] = array(
                        'key'   => esc_html($label),
                        'value' => '<img src="' . esc_url($el['value']['image_url']) . '" style="max-width:120px;height:auto;border:1px solid #eee;border-radius:4px;" />',
                        'display' => '',
                    );
                } else {
                    $item_data[] = array(
                        'key'   => esc_html($label),
                        'value' => __('User image uploaded', 'wcpp'),
                    );
                }
            }
        }
    }

    return $item_data;
}, 10, 2);

// =====  hook: copy to order item  =====
add_action('woocommerce_checkout_create_order_line_item', function ($item, $cart_item_key, $values, $order) {
    if (empty($values['wc_personalize'])) return;
    $data = $values['wc_personalize'];

    // Store JSON (underscore key is hidden in emails/admin; add readable fields below)
    //  $item->add_meta_data( '_wc_personalize_raw', wp_json_encode( $data ) );

    // Human readable bits
    if (! empty($data['preview']['url'])) {
        $item->add_meta_data('Personalized Preview', esc_url_raw($data['preview']['url']));
    }
    if (! empty($data['payload']['elements'])) {
        foreach ($data['payload']['elements'] as $el) {
            $label = ! empty($el['label']) ? $el['label'] : ucfirst($el['type']);
            if ($el['type'] === 'text') {
                $val = isset($el['value']['text']) ? $el['value']['text'] : '';
                $item->add_meta_data($label, $val);
                if (! empty($el['value']['fontFamily'])) $item->add_meta_data($label . ' Font', $el['value']['fontFamily']);
                if (! empty($el['value']['fontSize']))  $item->add_meta_data($label . ' Size', intval($el['value']['fontSize']));
                if (! empty($el['value']['color']))      $item->add_meta_data($label . ' Color', $el['value']['color']);
            } elseif ($el['type'] === 'color') {
                $clr = isset($el['value']['color']) ? $el['value']['color'] : ($el['properties']['defaultColor'] ?? '#000');
                $item->add_meta_data($label . ' Color', $clr);
            } elseif ($el['type'] === 'image' && ! empty($el['value']['image_url'])) {
                $item->add_meta_data($label . ' Image', esc_url_raw($el['value']['image_url']));
            }
        }
    }
}, 10, 4);

// =====  show preview image in emails & order admin (under line item) =====
add_action('woocommerce_order_item_meta_end', function ($item_id, $item, $order, $plain_text) {
    $preview = $item->get_meta('Personalized Preview', true);
    if ($preview) {
        echo '<div style="margin-top:6px"><img src="' . esc_url($preview) . '" style="max-width:160px;height:auto;border:1px solid #eee;border-radius:4px;" /></div>';
    }
}, 10, 4);

// =====  optional: show the preview instead of product thumb in cart =====
add_filter('woocommerce_cart_item_thumbnail', function ($thumb, $cart_item, $cart_item_key) {
    if (! empty($cart_item['wc_personalize']['preview']['url'])) {
        $url = $cart_item['wc_personalize']['preview']['url'];
        return '<img src="' . esc_url($url) . '" alt="" style="max-width:60px;height:auto" />';
    }
    return $thumb;
}, 10, 3);





// require_once plugin_dir_path( __FILE__ ) . 'wc-custom-category.php';

new WC_Product_Personalizer();




// add_action( 'plugins_loaded', function(){
// 	new WC_Custom_Category_list_shortcode();
// });

/*
 * Fixed category shortcodes (admin chooses category in settings)

[wc_cat_one]

[wc_cat_two]

Flexible products by category slug

[wc_cat category="hoodies" limit="8" columns="4"]

New category grid shortcode

[wc_cat_grid limit="8" columns="4" orderby="name" order="ASC" hide_empty="yes"]*/