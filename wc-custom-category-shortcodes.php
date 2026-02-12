<?php
/**
 * Plugin Name: WC Custom Category Shortcodes
 * Description: Adds two admin-configurable shortcodes to display WooCommerce products from specific categories, plus a general category shortcode.
 * Version: 1.0.0
 * Author: Your Name
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * License: GPL-2.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WC_Custom_Category_Shortcodes {
	const OPTION_KEY = 'wcccs_settings';

	public function __construct() {
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_action( 'admin_menu', [ $this, 'add_settings_page' ] );
		add_action( 'init', [ $this, 'register_shortcodes' ] );
	}

	/**
	 * Ensure WooCommerce exists.
	 */
	private function wc_active() : bool {
		return class_exists( 'WooCommerce' );
	}

	/**
	 * Register settings using Settings API.
	 */
	public function register_settings() {
		register_setting( 'wcccs_group', self::OPTION_KEY, [ $this, 'sanitize_settings' ] );

		add_settings_section( 'wcccs_main', __( 'Shortcode Configuration', 'wcccs' ), function(){
			echo '<p>' . esc_html__( 'Choose which product categories each shortcode should display. You can override limits via shortcode attributes.', 'wcccs' ) . '</p>';
		}, 'wcccs' );

		add_settings_field( 'cat_one', __( 'Shortcode 1 Category', 'wcccs' ), [ $this, 'field_cat_one' ], 'wcccs', 'wcccs_main' );
		add_settings_field( 'cat_two', __( 'Shortcode 2 Category', 'wcccs' ), [ $this, 'field_cat_two' ], 'wcccs', 'wcccs_main' );
		add_settings_field( 'default_limit', __( 'Default Product Limit', 'wcccs' ), [ $this, 'field_default_limit' ], 'wcccs', 'wcccs_main' );
	}

	public function sanitize_settings( $input ) {
		$clean = [];
		$clean['cat_one'] = isset( $input['cat_one'] ) ? absint( $input['cat_one'] ) : 0;
		$clean['cat_two'] = isset( $input['cat_two'] ) ? absint( $input['cat_two'] ) : 0;
		$clean['default_limit'] = isset( $input['default_limit'] ) ? max( 1, absint( $input['default_limit'] ) ) : 8;
		return $clean;
	}

	private function get_option( $key, $default = '' ) {
		$opts = get_option( self::OPTION_KEY, [] );
		return isset( $opts[ $key ] ) ? $opts[ $key ] : $default;
	}

	public function add_settings_page() {
		add_submenu_page(
			'woocommerce',
			__( 'Category Shortcodes', 'wcccs' ),
			__( 'Category Shortcodes', 'wcccs' ),
			'manage_woocommerce',
			'wcccs',
			[ $this, 'render_settings_page' ]
		);
	}

	public function render_settings_page() {
		if ( ! $this->wc_active() ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'WooCommerce is not active. Please activate WooCommerce to use this plugin.', 'wcccs' ) . '</p></div>';
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WC Category Shortcodes', 'wcccs' ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'wcccs_group' );
				do_settings_sections( 'wcccs' );
				submit_button();
				?>
			</form>

			<hr/>
			<h2><?php esc_html_e( 'How to Use', 'wcccs' ); ?></h2>
			<p><code>[wc_cat_one limit="8" columns="4" orderby="date" order="DESC"]</code> — <?php esc_html_e( 'Shows products from Category set for Shortcode 1.', 'wcccs' ); ?></p>
			<p><code>[wc_cat_two limit="8" columns="4" orderby="date" order="DESC"]</code> — <?php esc_html_e( 'Shows products from Category set for Shortcode 2.', 'wcccs' ); ?></p>
			<p><code>[wc_cat category="hoodies" limit="8" columns="4" orderby="date" order="DESC"]</code> — <?php esc_html_e( 'Shows products from any category slug (ad-hoc).', 'wcccs' ); ?></p>
		</div>
		<?php
	}

	public function field_cat_one() {
		$this->render_category_dropdown( 'cat_one', $this->get_option( 'cat_one', 0 ) );
	}

	public function field_cat_two() {
		$this->render_category_dropdown( 'cat_two', $this->get_option( 'cat_two', 0 ) );
	}

	public function field_default_limit() {
		$value = $this->get_option( 'default_limit', 8 );
		echo '<input type="number" min="1" name="' . esc_attr( self::OPTION_KEY ) . '[default_limit]" value="' . esc_attr( $value ) . '" />';
	}

	private function render_category_dropdown( $field_key, $selected_id = 0 ) {
		$taxonomy = 'product_cat';
		$terms = get_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => false ] );
		echo '<select name="' . esc_attr( self::OPTION_KEY ) . '[' . esc_attr( $field_key ) . ']">';
		echo '<option value="0">' . esc_html__( '— Select a category —', 'wcccs' ) . '</option>';
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				printf( '<option value="%d" %s>%s</option>', $term->term_id, selected( (int) $selected_id, (int) $term->term_id, false ), esc_html( $term->name ) );
			}
		}
		echo '</select>';
	}

	/**
	 * Register shortcodes
	 */
	public function register_shortcodes() {
		add_shortcode( 'wc_cat_one', function( $atts ) { return $this->render_products_shortcode( 'one', $atts ); } );
		add_shortcode( 'wc_cat_two', function( $atts ) { return $this->render_products_shortcode( 'two', $atts ); } );
		add_shortcode( 'wc_cat', function( $atts ) { return $this->render_products_by_category_attr( $atts ); } );
	}

	private function render_products_shortcode( string $which, $atts ) : string {
		$cat_id = ( 'one' === $which ) ? (int) $this->get_option( 'cat_one', 0 ) : (int) $this->get_option( 'cat_two', 0 );
		if ( ! $cat_id ) {
			return '<div class="wcccs-note">' . esc_html__( 'Category is not configured yet.', 'wcccs' ) . '</div>';
		}
		$term = get_term( $cat_id, 'product_cat' );
		if ( ! $term || is_wp_error( $term ) ) {
			return '';
		}
		$atts = shortcode_atts([
			'limit'   => $this->get_option( 'default_limit', 8 ),
			'columns' => 4,
			'orderby' => 'date',
			'order'   => 'DESC',
		], $atts, 'wc_cat_' . $which );
		return $this->query_and_render_products( [ 'category' => $term->slug ] , $atts );
	}

	private function render_products_by_category_attr( $atts ) : string {
		$atts = shortcode_atts([
			'category' => '', // slug
			'limit'    => $this->get_option( 'default_limit', 8 ),
			'columns'  => 4,
			'orderby'  => 'date',
			'order'    => 'DESC',
		], $atts, 'wc_cat' );
		if ( empty( $atts['category'] ) ) {
			return '<div class="wcccs-note">' . esc_html__( 'Please provide a category slug, e.g., [wc_cat category="hoodies"]', 'wcccs' ) . '</div>';
		}
		return $this->query_and_render_products( [ 'category' => sanitize_title( $atts['category'] ) ], $atts );
	}

	private function query_and_render_products( array $tax_args, array $atts ) : string {
		if ( ! $this->wc_active() ) { return ''; }

		$limit   = max( 1, (int) $atts['limit'] );
		$columns = max( 1, (int) $atts['columns'] );
		$orderby = sanitize_key( $atts['orderby'] );
		$order   = ( 'ASC' === strtoupper( $atts['order'] ) ) ? 'ASC' : 'DESC';

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'orderby'        => $orderby,
			'order'          => $order,
			'tax_query'      => [
				[
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => isset( $tax_args['category'] ) ? (array) $tax_args['category'] : [],
				]
			],
		];

		$q = new WP_Query( $args );

		if ( ! $q->have_posts() ) {
			return '<div class="wcccs-empty">' . esc_html__( 'No products found for this category.', 'wcccs' ) . '</div>';
		}

		ob_start();
		echo '<div class="wcccs-grid columns-' . esc_attr( $columns ) . '">';
		while ( $q->have_posts() ) : $q->the_post();
			global $product;
			if ( ! $product instanceof WC_Product ) { continue; }
			?>
			<div class="wcccs-item">
				<a class="wcccs-thumb" href="<?php the_permalink(); ?>"><?php echo $product->get_image(); ?></a>
				<h3 class="wcccs-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
				<div class="wcccs-price"><?php echo wp_kses_post( $product->get_price_html() ); ?></div>
				<div class="wcccs-cart"><?php woocommerce_template_loop_add_to_cart(); ?></div>
			</div>
			<?php
		endwhile;
		echo '</div>';
		wp_reset_postdata();

		$this->print_styles_once();
		return ob_get_clean();
	}

	private function print_styles_once() {
		static $done = false;
		if ( $done ) return; $done = true;
		?>
		<style>
			.wcccs-grid{display:grid;gap:20px}
			.wcccs-grid.columns-1{grid-template-columns:repeat(1,1fr)}
			.wcccs-grid.columns-2{grid-template-columns:repeat(2,1fr)}
			.wcccs-grid.columns-3{grid-template-columns:repeat(3,1fr)}
			.wcccs-grid.columns-4{grid-template-columns:repeat(4,1fr)}
			.wcccs-item{border:1px solid #eee;padding:12px;border-radius:8px;background:#fff}
			.wcccs-title{font-size:16px;margin:8px 0}
			.wcccs-price{margin-bottom:8px}
			.wcccs-note,.wcccs-empty{padding:10px;background:#f8f9fa;border:1px solid #eee;border-radius:6px}
		</style>
		<?php
	}
}


