<?php

/**
 * Plugin Name: DD WooCommerce Customizer
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: A foundational plugin to handle bespoke WooCommerce customizations and enqueue specific stylesheet assets, optimized for GeneratePress. Includes custom product tabs, a bespoke file repeater, global review disabling, and reordered upsells.
 * Version:     1.7.2
 * Author:      Digitally Disruptive - Donald Raymundo
 * Author URI:  https://digitallydisruptive.co.uk/
 * Text Domain: dd-woo-customizer
 */

if (! defined('ABSPATH')) {
	exit; // Exit if accessed directly to prevent execution outside of WordPress environment.
}

/**
 * Main class to orchestrate WooCommerce hooks, filters, and asset management.
 */
class DD_WooCommerce_Customizer
{

	/**
	 * Initialize the class and bind actions/filters to WordPress hooks.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function __construct()
	{
		// Enqueue custom CSS specifically for WooCommerce pages.
		add_action('wp_enqueue_scripts', [$this, 'enqueue_custom_styles'], 999);

		// Inject a custom wrapper open tag before the main shop loop.
		add_action('woocommerce_before_main_content', [$this, 'add_custom_wrapper_open'], 5);

		// Inject a custom wrapper close tag after the main shop loop.
		add_action('woocommerce_after_main_content', [$this, 'add_custom_wrapper_close'], 50);

		// Override default variation dropdown HTML for targeted non-global attributes.
		add_filter('woocommerce_dropdown_variation_attribute_options_html', [$this, 'render_custom_variation_cards'], 10, 2);

		// Inject inline script and style for the custom variation cards.
		add_action('wp_footer', [$this, 'inject_variation_ui_assets']);

		// Inject custom checkbox UI into the WooCommerce Product Attributes meta box.
		add_action('woocommerce_after_product_attribute_settings', [$this, 'add_card_layout_checkbox'], 10, 2);

		// Intercept the core product CRUD object save to persist configurations.
		add_action('woocommerce_before_product_object_save', [$this, 'save_card_layout_configuration']);

		// Backend: Add Custom Product Data Tabs (Logical Partitioning)
		add_filter('woocommerce_product_data_tabs', [$this, 'add_custom_product_data_tabs']);

		// Backend: Add Custom Product Data Panels
		add_action('woocommerce_product_data_panels', [$this, 'add_custom_product_data_panels']);

		// Backend: Save Custom Product Meta Data
		add_action('woocommerce_process_product_meta', [$this, 'save_custom_product_meta_data']);

		// Backend: Enqueue Admin Scripts for Repeater & Media Uploader
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);

		// Frontend: Add Custom Single Product Tabs
		add_filter('woocommerce_product_tabs', [$this, 'add_frontend_product_tabs']);

		// Frontend: Modify WooCommerce Breadcrumb Delimiter
		add_filter('woocommerce_breadcrumb_defaults', [$this, 'modify_breadcrumb_delimiter']);

		// System: Completely disable WooCommerce review and rating functionality
		add_action('init', [$this, 'disable_woocommerce_reviews']);
		add_filter('woocommerce_product_tabs', [$this, 'remove_reviews_tab'], 98);
		add_filter('comments_open', [$this, 'force_close_product_comments'], 20, 2);

		// Layout: Position "You May Also Like" (Upsells) below Related Products
		add_action('init', [$this, 'reorder_upsells_and_related_products']);

		// Layout: Display "Frequently bought together" directly above the main add-to-cart form 
		// Hook changed from `woocommerce_before_add_to_cart_button` to prevent invalid HTML nested forms
		add_action('woocommerce_before_add_to_cart_form', [$this, 'display_frequently_bought_together'], 10);

		// JavaScript: Inject bespoke AJAX handler for the nested FBT add-to-cart forms
		add_action('wp_footer', [$this, 'inject_fbt_ajax_scripts']);

		// AJAX Endpoints: Handle Custom Add to Cart for both Simple and Variable Products
		add_action('wp_ajax_dd_fbt_add_to_cart', [$this, 'handle_fbt_ajax_add_to_cart']);
		add_action('wp_ajax_nopriv_dd_fbt_add_to_cart', [$this, 'handle_fbt_ajax_add_to_cart']);
	}

	/**
	 * Custom AJAX endpoint to process cart additions for complex FBT variable products.
	 * Bypasses native endpoint limitations by manually extracting attributes and executing core cart logic.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function handle_fbt_ajax_add_to_cart()
	{
		ob_start();

		$product_id   = apply_filters('woocommerce_add_to_cart_product_id', absint($_POST['product_id']));
		$quantity     = empty($_POST['quantity']) ? 1 : wc_stock_amount(wp_unslash($_POST['quantity']));
		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $_POST);

		if (!$passed_validation) {
			wp_send_json_error(['message' => __('Validation failed. Please select all required options.', 'dd-woo-customizer')]);
		}

		$cart_item_key = false;

		// Handle variable products by extracting exact attribute selections from POST data
		if ($variation_id) {
			$variation = [];
			foreach ($_POST as $key => $value) {
				if (strpos($key, 'attribute_') === 0) {
					$variation[$key] = wc_clean(wp_unslash($value));
				}
			}
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
		} else {
			// Handle standard simple products
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
		}

		if ($cart_item_key) {
			// Leverage WooCommerce core fragments generation to update minicart UIs.
			// Note: This function automatically calls wp_die() and returns raw JSON (not a standard success response)
			WC_AJAX::get_refreshed_fragments();
		} else {
			wp_send_json_error(['message' => __('Failed to add product to cart.', 'dd-woo-customizer')]);
		}

		wp_die();
	}

	/**
	 * Injects specialized JavaScript handling to convert native WooCommerce variable/simple 
	 * form POST submissions inside the FBT module into seamless AJAX events via a custom endpoint.
	 * Explicitly handles raw fragment JSON returns to prevent TypeError thread crashing.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function inject_fbt_ajax_scripts()
	{
		if (! is_product()) {
			return;
		}
?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {

				// Re-initialize WooCommerce variation scripts for dynamically injected FBT forms
				if ($.fn.wc_variation_form) {
					$('.dd-fbt-item .variations_form').each(function() {
						$(this).wc_variation_form();
					});
				}

				// Intercept standard form submissions within the FBT wrappers
				$(document).on('submit', '.dd-fbt-item form.cart', function(e) {
					e.preventDefault();

					// CRITICAL: Prevent theme scripts (like Elementor/GeneratePress) from double-firing on this form
					e.stopImmediatePropagation();

					var $form = $(this);
					var $item = $form.closest('.dd-fbt-item');
					var $btn = $form.find('button[type="submit"]');

					// Respect WooCommerce's native disabled state (e.g., missing variation selection)
					if ($btn.is('.disabled')) {
						return false;
					}

					// Add our custom loading class + WooCommerce's native loading class
					$btn.addClass('loading wc-loading');

					// Utilize FormData to safely parse all inputs, including dynamically generated attribute variations
					var formData = new FormData($form[0]);

					// Route to the custom AJAX endpoint
					formData.append('action', 'dd_fbt_add_to_cart');

					// Ensure the core product ID is passed (especially crucial for simple products lacking variation IDs)
					var productId = $btn.val() || $form.find('input[name="add-to-cart"]').val();
					if (productId) {
						formData.append('product_id', productId);
					}

					var ajaxUrl = (typeof woocommerce_params !== 'undefined') ? woocommerce_params.ajax_url : '/wp-admin/admin-ajax.php';

					$.ajax({
						type: 'POST',
						url: ajaxUrl,
						data: formData,
						processData: false,
						contentType: false,
						success: function(response) {
							// FIX: WC_AJAX::get_refreshed_fragments() returns raw JSON object with 'fragments', not 'success'
							if (response && response.fragments) {

								// Trigger native WooCommerce fragment refresh to update headers/minicarts
								$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);

								// Safely remove the loading states
								$btn.removeClass('loading wc-loading');
								$item.addClass('is-in-cart');

								if ($item.find('.dd-fbt-badge').length === 0) {
									$item.prepend('<span class="dd-fbt-badge">Added to cart</span>');
								}

							} else if (response && response.success === false) {

								$btn.removeClass('loading wc-loading');
								alert((response.data && response.data.message) ? response.data.message : 'Failed to add item to cart.');

							} else {
								$btn.removeClass('loading wc-loading');
							}
						},
						error: function() {
							$btn.removeClass('loading wc-loading');
							alert('An error occurred during network execution. Please try again.');
						}
					});
				});
			});
		</script>
	<?php
	}

	/**
	 * Enqueue the plugin's custom stylesheet and inline assets.
	 * Conditionally loads the CSS asset solely on WooCommerce-related pages.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function enqueue_custom_styles()
	{
		if (function_exists('is_woocommerce') && is_woocommerce()) {
			wp_enqueue_style(
				'dd-woo-customizer-css',
				plugin_dir_url(__FILE__) . 'assets/css/dd-woo-customizer.css',
				[],
				'1.7.2',
				'all'
			);

			// Inline styles for the frontend variation-style download cards and modern FBT cross-sell layout
			$custom_css = "
				.dd-downloads-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-top: 15px; }
				.dd-download-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: flex; flex-direction: column; align-items: flex-start; background: #f8fafc; transition: all 0.2s ease-in-out; }
				.dd-download-card:hover { border-color: #cbd5e1; background: #f1f5f9; }
				.dd-download-card h4 { margin: 0 0 12px 0; font-size: 1.25rem; font-weight: 600; color: #1e293b; }
				.dd-download-btn { display: inline-flex; align-items: center; background: var(--accent); color: #fff; padding: 8px 12px; text-decoration: none; border-radius: 4px; font-weight: 500; font-size: 0.75rem; transition: background 0.2s; }
				.dd-download-btn:hover { background: #000; color: #fff; }
				.dd-download-btn svg { width: 16px; height: 16px; margin-left: 8px; fill: currentColor; }
				.upsells.products { margin-top: 4em; } 
				
				/* FBT Modern Layout Customization */
				.dd-fbt-wrapper { margin-bottom: 25px; padding: 20px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; width: 100% }
				.dd-fbt-wrapper h4 { margin: 0 0 15px 0; font-size: 1.1rem; color: #1e293b; font-weight: 600; }
				.dd-fbt-list { display: flex; flex-direction: column; gap: 15px; }
				.dd-fbt-item { position: relative; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; transition: all 0.2s ease; }
				.dd-fbt-item:hover, .dd-fbt-item:focus-within { border-color: #ff0000; box-shadow: 0 2px 10px rgba(255,0,0,0.05); }
				.dd-fbt-item.is-in-cart { border-color: #22c55e; background: #f0fdf4; }
				.dd-fbt-badge { position: absolute; top: -10px; right: 15px; background: #22c55e; color: #fff; font-size: 0.75rem; font-weight: 600; padding: 3px 10px; border-radius: 12px; z-index: 2; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
				.dd-fbt-main { display: flex; align-items: center; gap: 15px; }
				.dd-fbt-main img { width: 55px; height: 55px; object-fit: cover; border-radius: 6px; flex-shrink: 0; border: 1px solid #f1f5f9; }
				.dd-fbt-details { display: flex; flex-direction: column; flex-grow: 1; }
				.dd-fbt-header-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 10px; }
				.dd-fbt-title { font-size: 0.95rem; font-weight: 500; text-decoration: none; color: #334155; line-height: 1.3; }
				.dd-fbt-title:hover { color: #ff0000; }
				.dd-fbt-price { font-size: 0.95rem; font-weight: 700; color: #ff0000; text-align: right; }
				
				/* Sub-Form Integrations */
				.dd-fbt-action form.cart { margin: 12px 0 0 0 !important; padding: 12px 0 0 0 !important; border-top: 1px dashed #e2e8f0; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
				.dd-fbt-action form.cart .variations { margin: 0 0 10px 0 !important; width: 100%; border: none !important; }
				.dd-fbt-action form.cart .variations th, .dd-fbt-action form.cart .variations td { padding: 4px 0 !important; background: transparent !important; display: block; }
				.dd-fbt-action form.cart .variations select { width: 100%; max-width: 250px; font-size: 0.85rem; padding: 4px 8px; border-radius: 4px; }
				.dd-fbt-action form.cart .quantity { margin-right: 10px; }
				.dd-fbt-action form.cart button.single_add_to_cart_button { background: #ff0000; color: #fff; border: none; padding: 8px 20px; border-radius: 4px; font-weight: 600; font-size: 0.85rem; cursor: pointer; transition: opacity 0.2s; flex-shrink: 0; }
				.dd-fbt-action form.cart button.single_add_to_cart_button:hover { opacity: 0.9; }
				.dd-fbt-action form.cart button.single_add_to_cart_button.disabled { background: #ccc !important; cursor: not-allowed; }
				.dd-fbt-action form.cart button.single_add_to_cart_button.loading { opacity: 0.5; pointer-events: none; }
			";
			wp_add_inline_style('dd-woo-customizer-css', $custom_css);
		}
	}

	/**
	 * Output a custom opening HTML `<div>` wrapper before the main WooCommerce content.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_custom_wrapper_open()
	{
		echo '<div class="dd-woo-custom-container">';
	}

	/**
	 * Output a custom closing HTML `<div>` wrapper after the main WooCommerce content.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_custom_wrapper_close()
	{
		echo '</div>';
	}

	/**
	 * Render a custom checkbox in the Product -> Attributes tab within the admin dashboard.
	 * Utilizes multi-context ID resolution to maintain state during asynchronous Backbone.js renders.
	 *
	 * @since 1.2.3
	 * @param WC_Product_Attribute $attribute The product attribute object.
	 * @param int                  $i         The numeric index of the attribute loop.
	 * @return void
	 */
	public function add_card_layout_checkbox($attribute, $i)
	{
		global $post, $thepostid;

		$is_card    = false;
		$product_id = 0;

		if (! empty($thepostid)) {
			$product_id = $thepostid;
		} elseif (is_object($post) && ! empty($post->ID)) {
			$product_id = $post->ID;
		} elseif (isset($_POST['post_id'])) {
			$product_id = absint($_POST['post_id']);
		}

		$attribute_slug = sanitize_title($attribute->get_name());

		if ($product_id) {
			$card_attributes = get_post_meta($product_id, '_dd_card_attributes', true);
			if (! is_array($card_attributes)) {
				$card_attributes = [];
			}
			$is_card = in_array($attribute_slug, $card_attributes, true);
		}
	?>
		<div style="padding: 13px; display: block;">
			<label>
				<input type="checkbox" class="checkbox" name="attribute_is_card[<?php echo esc_attr($attribute_slug); ?>]" value="1" <?php checked($is_card, true); ?> />
				<?php esc_html_e('Display as variation cards', 'dd-woo-customizer'); ?>
			</label>
		</div>
	<?php
	}

	/**
	 * Process and save the custom card layout configuration upon product update.
	 *
	 * @since 1.2.2
	 * @param WC_Product $product The WooCommerce product object currently being saved.
	 * @return void
	 */
	public function save_card_layout_configuration($product)
	{
		$post_data = $_POST;
		$is_ajax   = false;

		if (isset($_POST['action']) && 'woocommerce_save_attributes' === $_POST['action'] && isset($_POST['data'])) {
			parse_str(wp_unslash($_POST['data']), $post_data);
			$is_ajax = true;
		}

		if (! isset($post_data['woocommerce_meta_nonce']) && ! $is_ajax) {
			return;
		}

		$attribute_is_card = isset($post_data['attribute_is_card']) ? wc_clean(wp_unslash($post_data['attribute_is_card'])) : [];
		$card_attributes   = [];

		if (is_array($attribute_is_card)) {
			foreach ($attribute_is_card as $slug => $value) {
				if (! empty($value)) {
					$card_attributes[] = sanitize_title($slug);
				}
			}
		}

		$product->update_meta_data('_dd_card_attributes', $card_attributes);
	}

	/**
	 * Transform default variation dropdowns into interactive cards.
	 *
	 * @since 1.2.0
	 * @param string $html Original HTML of the select dropdown.
	 * @param array  $args Arguments passed to the variation dropdown.
	 * @return string
	 */
	public function render_custom_variation_cards($html, $args)
	{
		if (taxonomy_exists($args['attribute'])) {
			return $html;
		}

		$product = $args['product'];

		if (! $product) {
			return $html;
		}

		$card_attributes = get_post_meta($product->get_id(), '_dd_card_attributes', true);
		if (! is_array($card_attributes)) {
			$card_attributes = [];
		}

		$sanitized_card_attributes = array_map('sanitize_title', $card_attributes);
		$attribute_slug            = sanitize_title($args['attribute']);

		if (! in_array($attribute_slug, $sanitized_card_attributes, true)) {
			return $html;
		}

		$options   = $args['options'];
		$attribute = $args['attribute'];
		$name      = $args['name'] ? $args['name'] : 'attribute_' . sanitize_title($attribute);
		$id        = $args['id'] ? $args['id'] : sanitize_title($attribute);
		$class     = $args['class'];

		if (empty($options)) {
			return $html;
		}

		$custom_html  = '<select id="' . esc_attr($id) . '" class="' . esc_attr($class) . ' dd-hidden-variation-select" name="' . esc_attr($name) . '" data-attribute_name="attribute_' . esc_attr(sanitize_title($attribute)) . '" style="display:none;">';
		$custom_html .= '<option value="">' . esc_html__('Choose an option', 'woocommerce') . '</option>';

		foreach ($options as $option) {
			$selected = sanitize_title($args['selected']) === $args['selected'] ? selected($args['selected'], sanitize_title($option), false) : selected($args['selected'], $option, false);
			$custom_html .= '<option value="' . esc_attr($option) . '" ' . $selected . '>' . esc_html(apply_filters('woocommerce_variation_option_name', $option, null, $attribute, $product)) . '</option>';
		}
		$custom_html .= '</select>';

		$custom_html .= '<div class="dd-custom-variations-grid" data-select-id="' . esc_attr($id) . '">';

		$available_variations = $product->get_available_variations();

		foreach ($options as $option) {
			$option_val  = esc_attr($option);
			$option_name = esc_html(apply_filters('woocommerce_variation_option_name', $option, null, $attribute, $product));
			$image_html  = '';

			$attr_key = 'attribute_' . sanitize_title($attribute);
			foreach ($available_variations as $variation) {
				if (isset($variation['attributes'][$attr_key]) && $variation['attributes'][$attr_key] === $option) {
					if (! empty($variation['image']['thumb_src'])) {
						$image_html = '<img src="' . esc_url($variation['image']['thumb_src']) . '" alt="' . esc_attr($option_name) . '">';
						break;
					}
				}
			}

			$is_selected = ($args['selected'] === $option || sanitize_title($args['selected']) === sanitize_title($option)) ? ' selected' : '';

			$custom_html .= '<div class="dd-variation-card' . $is_selected . '" data-value="' . $option_val . '">';
			if ($image_html) {
				$custom_html .= '<div class="dd-variation-card-img">' . $image_html . '</div>';
			}
			$custom_html .= '<div class="dd-variation-card-title">' . $option_name . '</div>';
			$custom_html .= '</div>';
		}

		$custom_html .= '</div>';

		return $custom_html;
	}

	/**
	 * Inject necessary JavaScript logic and CSS styling for the interactive variations.
	 *
	 * @since 1.1.1
	 * @return void
	 */
	public function inject_variation_ui_assets()
	{
		if (! is_product()) {
			return;
		}
	?>
		<style>
			.dd-custom-variations-grid {
				display: flex;
				flex-direction: column;
				gap: 12px;
			}

			.dd-variation-card {
				display: flex;
				align-items: center;
				padding: 12px 16px;
				border: 1px solid #e2e8f0;
				border-radius: 8px;
				background: #f8fafc;
				cursor: pointer;
				transition: all 0.2s ease-in-out;
			}

			.dd-variation-card:not(.disabled):not(.selected):hover {
				border-color: #cbd5e1;
				background: #f1f5f9;
			}

			.dd-variation-card.selected {
				border-color: #ef4444;
				background: #ffffff;
			}

			.dd-variation-card.disabled {
				opacity: 0.4;
				cursor: not-allowed;
				background: #e2e8f0;
				border-color: #cbd5e1;
				filter: grayscale(100%);
			}

			.dd-variation-card-img {
				width: 60px;
				height: 60px;
				margin-right: 16px;
				flex-shrink: 0;
			}

			.dd-variation-card-img img {
				width: 100%;
				height: 100%;
				object-fit: contain;
			}

			.dd-variation-card-title {
				font-size: 15px;
				font-weight: 500;
				color: #1e293b;
			}
		</style>

		<script type="text/javascript">
			jQuery(document).ready(function($) {
				function syncCustomVariationsState() {
					$('.dd-custom-variations-grid').each(function() {
						var $grid = $(this);
						var selectId = $grid.data('select-id');
						var $select = $('#' + selectId);
						if (!$select.length) return;
						var currentVal = $select.val();
						$grid.find('.dd-variation-card').each(function() {
							var $card = $(this);
							var cardVal = $card.data('value');
							var $option = $select.find('option[value="' + cardVal + '"]');
							if ($option.length === 0 || $option.prop('disabled') || $option.hasClass('disabled')) {
								$card.addClass('disabled').removeClass('selected');
							} else {
								$card.removeClass('disabled');
								if (cardVal === currentVal && currentVal !== '') {
									$card.addClass('selected');
								} else {
									$card.removeClass('selected');
								}
							}
						});
					});
				}

				$('.variations_form').on('woocommerce_update_variation_values reset_data', function() {
					setTimeout(syncCustomVariationsState, 50);
				});

				$(document).on('click', '.dd-variation-card:not(.disabled)', function() {
					var $card = $(this);
					var $grid = $card.closest('.dd-custom-variations-grid');
					var selectId = $grid.data('select-id');
					var $select = $('#' + selectId);
					var val = $card.data('value');
					if ($card.hasClass('selected')) {
						$card.removeClass('selected');
						$select.val('').change();
					} else {
						$grid.find('.dd-variation-card').removeClass('selected');
						$card.addClass('selected');
						$select.val(val).change();
					}
				});
				setTimeout(syncCustomVariationsState, 100);
			});
		</script>
	<?php
	}

	/**
	 * Registers custom tabs within the WooCommerce Product Data meta box.
	 *
	 * @since 1.3.0
	 * @param array $tabs Existing product data tabs.
	 * @return array
	 */
	public function add_custom_product_data_tabs($tabs)
	{
		$tabs['dd_features'] = [
			'label'    => __('Features', 'dd-woo-customizer'),
			'target'   => 'dd_features_product_data',
			'class'    => ['show_if_simple', 'show_if_variable'],
			'priority' => 70,
		];

		$tabs['dd_downloads'] = [
			'label'    => __('Downloads', 'dd-woo-customizer'),
			'target'   => 'dd_downloads_product_data',
			'class'    => ['show_if_simple', 'show_if_variable'],
			'priority' => 71,
		];

		return $tabs;
	}

	/**
	 * Outputs the HTML structures for the custom product data panels.
	 *
	 * @since 1.3.0
	 * @return void
	 */
	public function add_custom_product_data_panels()
	{
		global $post;

		echo '<div id="dd_features_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group" style="padding: 10px 20px;">';
		echo '<p><strong>' . esc_html__('Product Features', 'dd-woo-customizer') . '</strong></p>';

		$features_content = get_post_meta($post->ID, '_dd_product_features', true);
		wp_editor($features_content, 'dd_product_features', ['textarea_name' => '_dd_product_features', 'media_buttons' => true, 'textarea_rows' => 10]);
		echo '</div></div>';

		echo '<div id="dd_downloads_product_data" class="panel woocommerce_options_panel hidden">';
		echo '<div class="options_group" style="padding: 10px 20px;">';
		$downloads_data = get_post_meta($post->ID, '_dd_product_downloads', true);
		if (! is_array($downloads_data)) {
			$downloads_data = [];
		}
	?>
		<div class="dd-repeater-wrapper">
			<style>
				.dd-repeater-row {
					border: 1px solid #dfdfdf;
					background: #f9f9f9;
					margin-bottom: 10px;
					border-radius: 3px;
				}

				.dd-repeater-header {
					padding: 10px;
					background: #eee;
					cursor: move;
					display: flex;
					justify-content: space-between;
					align-items: center;
					border-bottom: 1px solid #dfdfdf;
				}

				.dd-repeater-header h4 {
					margin: 0;
					font-size: 13px;
				}

				.dd-repeater-actions {
					display: flex;
					gap: 8px;
				}

				.dd-repeater-actions a {
					text-decoration: none;
					cursor: pointer;
					color: #555;
				}

				.dd-repeater-actions a:hover {
					color: #0073aa;
				}

				.dd-repeater-content {
					padding: 15px;
					background: #fff;
				}

				.dd-repeater-row.collapsed .dd-repeater-content {
					display: none;
				}
			</style>
			<div id="dd-downloads-container">
				<?php
				if (! empty($downloads_data)) {
					foreach ($downloads_data as $index => $download) {
						$this->render_repeater_row($index, $download['title'], $download['url']);
					}
				}
				?>
			</div>
			<button type="button" class="button button-primary" id="dd-add-download-row"><?php esc_html_e('+ Add Download', 'dd-woo-customizer'); ?></button>
		</div>
	<?php
		echo '</div></div>';
	}

	/**
	 * Helper method to render a single repeater row.
	 *
	 * @since 1.3.0
	 */
	private function render_repeater_row($index, $title = '', $url = '')
	{
	?>
		<div class="dd-repeater-row">
			<div class="dd-repeater-header">
				<h4 class="row-title"><?php echo esc_html($title ? $title : __('New Download', 'dd-woo-customizer')); ?></h4>
				<div class="dd-repeater-actions">
					<a class="dd-toggle-row" title="Collapse/Expand"><span class="dashicons dashicons-arrow-up-alt2"></span></a>
					<a class="dd-duplicate-row" title="Duplicate"><span class="dashicons dashicons-admin-page"></span></a>
					<a class="dd-delete-row" title="Delete"><span class="dashicons dashicons-trash"></span></a>
				</div>
			</div>
			<div class="dd-repeater-content">
				<p class="form-field">
					<label><?php esc_html_e('Title', 'dd-woo-customizer'); ?></label>
					<input type="text" class="dd-row-title-input short" name="_dd_product_downloads[<?php echo esc_attr($index); ?>][title]" value="<?php echo esc_attr($title); ?>" />
				</p>
				<p class="form-field">
					<label><?php esc_html_e('File URL', 'dd-woo-customizer'); ?></label>
					<input type="text" class="dd-row-url-input short" name="_dd_product_downloads[<?php echo esc_attr($index); ?>][url]" value="<?php echo esc_attr($url); ?>" style="width: 50%; margin-right: 5px;" />
					<a href="#" class="button dd-upload-file-btn"><?php esc_html_e('Choose File', 'dd-woo-customizer'); ?></a>
				</p>
			</div>
		</div>
	<?php
	}

	/**
	 * Enqueues scripts for the admin panel.
	 *
	 * @since 1.3.0
	 */
	public function enqueue_admin_scripts($hook)
	{
		if (! in_array($hook, ['post.php', 'post-new.php'], true)) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script('jquery-ui-sortable');
		ob_start();
		$this->render_repeater_row('___INDEX___');
		$template = ob_get_clean();

		$custom_js = "
		jQuery(document).ready(function($) {
			const container = $('#dd-downloads-container');
			let rowCount = container.find('.dd-repeater-row').length;
			const template = `" . $template . "`;
			container.sortable({ handle: '.dd-repeater-header', axis: 'y', opacity: 0.7, update: function() { updateIndexes(); } });
			function updateIndexes() {
				container.find('.dd-repeater-row').each(function(index) {
					$(this).find('input').each(function() {
						const name = $(this).attr('name');
						if(name) { $(this).attr('name', name.replace(/\[\d+\]/, '[' + index + ']')); }
					});
				});
			}
			$('#dd-add-download-row').on('click', function(e) {
				e.preventDefault();
				container.append($(template.replace(/___INDEX___/g, rowCount)));
				rowCount++;
				updateIndexes();
			});
			container.on('click', '.dd-delete-row', function(e) {
				e.preventDefault();
				if(confirm('Are you sure?')) { $(this).closest('.dd-repeater-row').remove(); updateIndexes(); }
			});
			container.on('click', '.dd-duplicate-row', function(e) {
				e.preventDefault();
				const currentRow = $(this).closest('.dd-repeater-row');
				const clone = currentRow.clone();
				clone.removeClass('collapsed');
				clone.find('.dashicons-arrow-down-alt2').removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
				currentRow.after(clone);
				rowCount++;
				updateIndexes();
			});
			container.on('click', '.dd-toggle-row', function(e) {
				e.preventDefault();
				const row = $(this).closest('.dd-repeater-row');
				const icon = $(this).find('.dashicons');
				row.toggleClass('collapsed');
				if (row.hasClass('collapsed')) { icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2'); }
				else { icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2'); }
			});
			container.on('input', '.dd-row-title-input', function() { $(this).closest('.dd-repeater-row').find('.row-title').text($(this).val() || 'New Download'); });
			container.on('click', '.dd-upload-file-btn', function(e) {
				e.preventDefault();
				const inputField = $(this).siblings('.dd-row-url-input');
				wp.media({ title: 'Choose Download File', button: { text: 'Use this file' }, multiple: false })
				.on('select', function() { inputField.val(this.get('selection').first().toJSON().url); }).open();
			});
		});
		";
		wp_add_inline_script('jquery-ui-sortable', $custom_js);
	}

	/**
	 * Sanitizes and persists custom product meta fields.
	 *
	 * @since 1.3.0
	 */
	public function save_custom_product_meta_data($post_id)
	{
		if (isset($_POST['_dd_product_features'])) {
			update_post_meta($post_id, '_dd_product_features', wp_kses_post(wp_unslash($_POST['_dd_product_features'])));
		}
		if (isset($_POST['_dd_product_downloads']) && is_array($_POST['_dd_product_downloads'])) {
			$sanitized_downloads = [];
			foreach ($_POST['_dd_product_downloads'] as $download) {
				if (! empty($download['title']) || ! empty($download['url'])) {
					$sanitized_downloads[] = [
						'title' => sanitize_text_field(wp_unslash($download['title'])),
						'url'   => esc_url_raw(wp_unslash($download['url'])),
					];
				}
			}
			update_post_meta($post_id, '_dd_product_downloads', $sanitized_downloads);
		} else {
			delete_post_meta($post_id, '_dd_product_downloads');
		}
	}

	/**
	 * Registers custom tabs on the WooCommerce Single Product frontend view.
	 *
	 * @since 1.3.0
	 * @param array $tabs Current frontend tabs.
	 * @return array
	 */
	public function add_frontend_product_tabs($tabs)
	{
		global $post;
		$features_content = get_post_meta($post->ID, '_dd_product_features', true);
		if (! empty($features_content)) {
			$tabs['dd_features_tab'] = ['title' => __('Features', 'dd-woo-customizer'), 'priority' => 25, 'callback' => [$this, 'render_features_tab_content']];
		}
		$downloads_data = get_post_meta($post->ID, '_dd_product_downloads', true);
		if (! empty($downloads_data)) {
			$tabs['dd_downloads_tab'] = ['title' => __('Downloads', 'dd-woo-customizer'), 'priority' => 30, 'callback' => [$this, 'render_downloads_tab_content']];
		}
		return $tabs;
	}

	/**
	 * Callback for Features WYSIWYG content.
	 */
	public function render_features_tab_content()
	{
		global $post;
		echo apply_filters('the_content', get_post_meta($post->ID, '_dd_product_features', true));
	}

	/**
	 * Callback for Downloads repeater data.
	 */
	public function render_downloads_tab_content()
	{
		global $post;
		$downloads_data = get_post_meta($post->ID, '_dd_product_downloads', true);
		echo '<div class="dd-downloads-grid">';
		foreach ($downloads_data as $download) {
			$title = esc_html($download['title']);
			$url   = esc_url($download['url']);
			if (! $title && ! $url) continue;
			echo '<div class="dd-download-card"><h4>' . $title . '</h4>';
			if ($url) {
				echo '<a href="' . $url . '" class="dd-download-btn" target="_blank" rel="noopener noreferrer">' . esc_html__('Download', 'dd-woo-customizer') . '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="M19 9h-4V3H9v6H5l7 7 7-7zM5 18v2h14v-2H5z"/></svg></a>';
			}
			echo '</div>';
		}
		echo '</div>';
	}

	/**
	 * Modifies the default WooCommerce breadcrumb arguments.
	 *
	 * @since 1.4.0
	 */
	public function modify_breadcrumb_delimiter($defaults)
	{
		$defaults['delimiter'] = ' <span class="sep">❯</span> ';
		return $defaults;
	}

	/**
	 * Disables core WooCommerce review and rating functionalities.
	 *
	 * @since 1.5.0
	 */
	public function disable_woocommerce_reviews()
	{
		remove_post_type_support('product', 'comments');
		remove_action('woocommerce_after_shop_loop_item_title', 'woocommerce_template_loop_rating', 5);
		remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_rating', 10);
	}

	/**
	 * Intercepts the product tabs to unset the reviews tab.
	 */
	public function remove_reviews_tab($tabs)
	{
		if (isset($tabs['reviews'])) {
			unset($tabs['reviews']);
		}
		return $tabs;
	}

	/**
	 * Explicitly force a 'closed' state for product comments.
	 */
	public function force_close_product_comments($open, $post_id)
	{
		return (get_post_type($post_id) === 'product') ? false : $open;
	}

	/**
	 * Reorders the single product page output to place "You May Also Like" (Upsells) 
	 * after "Related Products".
	 *
	 * @since 1.6.0
	 * @return void
	 */
	public function reorder_upsells_and_related_products()
	{
		remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
		add_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 25);
	}

	/**
	 * Display "Frequently Bought Together" natively configured with dynamic add-to-cart injection.
	 * By temporarily switching the global $product variable, we force WooCommerce to render
	 * full, form-capable single add-to-cart templates (including complex variable dropdowns).
	 * Utilizes a static recursion lock to prevent infinite loops when rendering nested forms.
	 *
	 * @since 1.7.4
	 * @return void
	 */
	public function display_frequently_bought_together()
	{
		// Prevent infinite recursion. The woocommerce_template_single_add_to_cart() function 
		// inherently triggers the 'woocommerce_before_add_to_cart_form' hook. Since this 
		// method is attached to that exact hook, it will loop infinitely without this lock.
		static $is_rendering = false;
		if ($is_rendering) {
			return;
		}

		global $product, $post;

		if (! $product) {
			return;
		}

		$cross_sell_ids = $product->get_cross_sell_ids();

		if (empty($cross_sell_ids)) {
			return;
		}

		// Engage the recursion lock before proceeding to render nested product templates
		$is_rendering = true;

		// Retrieve IDs of all products currently in the user's cart to handle the dynamic tag
		$cart_product_ids = [];
		if (is_object(WC()->cart)) {
			foreach (WC()->cart->get_cart() as $cart_item) {
				$cart_product_ids[] = $cart_item['product_id'];
			}
		}

		echo '<div class="dd-fbt-wrapper">';
		echo '<h4>' . esc_html__('Frequently bought together', 'dd-woo-customizer') . '</h4>';
		echo '<div class="dd-fbt-list">';

		// Backup the primary product and post instances to restore post-loop
		$original_product = $product;
		$original_post    = $post;

		foreach ($cross_sell_ids as $cross_sell_id) {
			$cross_sell = wc_get_product($cross_sell_id);

			if (! $cross_sell) {
				continue;
			}

			// Normalize specific variation IDs to their parent variable product to prevent 
			// fatal method calls within the core add-to-cart templating engine.
			if ($cross_sell->is_type('variation')) {
				$cross_sell = wc_get_product($cross_sell->get_parent_id());
			}

			if (! $cross_sell || ! $cross_sell->is_visible()) {
				continue;
			}

			$is_in_cart = in_array($cross_sell->get_id(), $cart_product_ids);
			$cart_class = $is_in_cart ? ' is-in-cart' : '';

			echo '<div class="dd-fbt-item' . esc_attr($cart_class) . '">';

			if ($is_in_cart) {
				echo '<span class="dd-fbt-badge">' . esc_html__('Added to cart', 'dd-woo-customizer') . '</span>';
			}

			echo '<div class="dd-fbt-main">';

			echo '<a href="' . esc_url($cross_sell->get_permalink()) . '">';
			echo wp_kses_post($cross_sell->get_image('woocommerce_gallery_thumbnail'));
			echo '</a>';

			echo '<div class="dd-fbt-details">';
			echo '<div class="dd-fbt-header-row">';
			echo '<a href="' . esc_url($cross_sell->get_permalink()) . '" class="dd-fbt-title">' . esc_html($cross_sell->get_name()) . '</a>';
			echo '<span class="dd-fbt-price">' . wp_kses_post($cross_sell->get_price_html()) . '</span>';
			echo '</div>';
			echo '</div>'; // end details

			echo '</div>'; // end main

			echo '<div class="dd-fbt-action">';

			// Temporarily mutate BOTH global contexts to trick native add-to-cart logic
			$GLOBALS['product'] = $cross_sell;
			$GLOBALS['post']    = get_post($cross_sell->get_id());
			setup_postdata($GLOBALS['post']);

			woocommerce_template_single_add_to_cart();

			echo '</div>'; // end action

			echo '</div>'; // end item
		}

		// Strictly restore global execution environment
		$GLOBALS['product'] = $original_product;
		$GLOBALS['post']    = $original_post;

		if ($original_post) {
			setup_postdata($original_post);
		} else {
			wp_reset_postdata();
		}

		echo '</div>';
		echo '</div>';

		// Release the recursion lock allowing future legitimate hook calls to process
		$is_rendering = false;
	}

	/**
	 * Custom AJAX endpoint to process cart additions for complex FBT variable products.
	 * Bypasses native endpoint limitations by manually extracting attributes and executing core cart logic.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function handle_fbt_ajax_add_to_cart()
	{
		ob_start();

		$product_id   = apply_filters('woocommerce_add_to_cart_product_id', absint($_POST['product_id']));
		$quantity     = empty($_POST['quantity']) ? 1 : wc_stock_amount(wp_unslash($_POST['quantity']));
		$variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;
		$passed_validation = apply_filters('woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $_POST);

		if (!$passed_validation) {
			wp_send_json_error(['message' => __('Validation failed. Please select all required options.', 'dd-woo-customizer')]);
		}

		$cart_item_key = false;

		// Handle variable products by extracting exact attribute selections from POST data
		if ($variation_id) {
			$variation = [];
			foreach ($_POST as $key => $value) {
				if (strpos($key, 'attribute_') === 0) {
					$variation[$key] = wc_clean(wp_unslash($value));
				}
			}
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
		} else {
			// Handle standard simple products
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
		}

		if ($cart_item_key) {
			// Leverage WooCommerce core fragments generation to update minicart UIs.
			// Note: This function automatically calls wp_die() and returns raw JSON (not a standard success response)
			WC_AJAX::get_refreshed_fragments();
		} else {
			wp_send_json_error(['message' => __('Failed to add product to cart.', 'dd-woo-customizer')]);
		}

		wp_die();
	}

	/**
	 * Injects specialized JavaScript handling to convert native WooCommerce variable/simple 
	 * form POST submissions inside the FBT module into seamless AJAX events via a custom endpoint.
	 * Explicitly handles raw fragment JSON returns to prevent TypeError thread crashing.
	 *
	 * @since 1.7.5
	 * @return void
	 */
	public function inject_fbt_ajax_scripts()
	{
		if (! is_product()) {
			return;
		}
	?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {

				// Re-initialize WooCommerce variation scripts for dynamically injected FBT forms
				if ($.fn.wc_variation_form) {
					$('.dd-fbt-item .variations_form').each(function() {
						$(this).wc_variation_form();
					});
				}

				// Intercept standard form submissions within the FBT wrappers
				$(document).on('submit', '.dd-fbt-item form.cart', function(e) {
					e.preventDefault();

					// CRITICAL: Prevent theme scripts (like Elementor/GeneratePress) from double-firing on this form
					e.stopImmediatePropagation();

					var $form = $(this);
					var $item = $form.closest('.dd-fbt-item');
					var $btn = $form.find('button[type="submit"]');

					// Respect WooCommerce's native disabled state (e.g., missing variation selection)
					if ($btn.is('.disabled')) {
						return false;
					}

					// Add our custom loading class + WooCommerce's native loading class
					$btn.addClass('loading wc-loading');

					// Utilize FormData to safely parse all inputs, including dynamically generated attribute variations
					var formData = new FormData($form[0]);

					// Route to the custom AJAX endpoint
					formData.append('action', 'dd_fbt_add_to_cart');

					// Ensure the core product ID is passed (especially crucial for simple products lacking variation IDs)
					var productId = $btn.val() || $form.find('input[name="add-to-cart"]').val();
					if (productId) {
						formData.append('product_id', productId);
					}

					var ajaxUrl = (typeof woocommerce_params !== 'undefined') ? woocommerce_params.ajax_url : '/wp-admin/admin-ajax.php';

					$.ajax({
						type: 'POST',
						url: ajaxUrl,
						data: formData,
						processData: false,
						contentType: false,
						success: function(response) {
							// FIX: WC_AJAX::get_refreshed_fragments() returns raw JSON object with 'fragments', not 'success'
							if (response && response.fragments) {

								// Trigger native WooCommerce fragment refresh to update headers/minicarts
								$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);

								// Safely remove the loading states
								$btn.removeClass('loading wc-loading');
								$item.addClass('is-in-cart');

								if ($item.find('.dd-fbt-badge').length === 0) {
									$item.prepend('<span class="dd-fbt-badge">Added to cart</span>');
								}

							} else if (response && response.success === false) {

								$btn.removeClass('loading wc-loading');
								alert((response.data && response.data.message) ? response.data.message : 'Failed to add item to cart.');

							} else {
								$btn.removeClass('loading wc-loading');
							}
						},
						error: function() {
							$btn.removeClass('loading wc-loading');
							alert('An error occurred during network execution. Please try again.');
						}
					});
				});
			});
		</script>
<?php
	}
}

// Initialize the plugin instance.
new DD_WooCommerce_Customizer();
