<?php

/**
 * Plugin Name: DD WooCommerce Customizer
 * Plugin URI:  https://digitallydisruptive.co.uk/
 * Description: A foundational plugin to handle bespoke WooCommerce customizations and enqueue specific stylesheet assets, optimized for GeneratePress. Includes custom product tabs, a bespoke file repeater, global review disabling, reordered upsells, and a composite unified FBT cart/enquiry system.
 * Version:     1.9.3
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

		// General Product Meta: Add Enquire Only Checkbox
		add_action('woocommerce_product_options_general_product_data', [$this, 'add_enquire_only_checkbox']);
		add_action('woocommerce_process_product_meta', [$this, 'save_enquire_only_checkbox']);

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

		// Layout: Display "Frequently bought together" and Enquire Now button INSIDE the main add-to-cart form
		add_action('woocommerce_before_add_to_cart_button', [$this, 'display_frequently_bought_together_and_enquire_btn'], 10);

		// JavaScript: Inject bespoke AJAX handler globally for all product forms
		add_action('wp_footer', [$this, 'inject_ajax_add_to_cart_scripts']);

		// AJAX Endpoints: Handle Custom Add to Cart for both Simple and Variable Products
		add_action('wp_ajax_dd_ajax_add_to_cart', [$this, 'handle_ajax_add_to_cart']);
		add_action('wp_ajax_nopriv_dd_ajax_add_to_cart', [$this, 'handle_ajax_add_to_cart']);
	}

	/**
	 * Render the Enquire Only checkbox in the general product data tab.
	 *
	 * @since 1.9.0
	 * @return void
	 */
	public function add_enquire_only_checkbox()
	{
		echo '<div class="options_group">';
		
		woocommerce_wp_checkbox([
			'id'          => '_dd_enquire_only',
			'label'       => __('Enquire Product Only', 'dd-woo-customizer'),
			'description' => __('Replaces the Add to Cart button with an Enquire Now overlay trigger.', 'dd-woo-customizer')
		]);
		
		echo '</div>';
	}

	/**
	 * Save the Enquire Only checkbox data upon product update.
	 *
	 * @since 1.9.0
	 * @param int $post_id The ID of the current product being saved.
	 * @return void
	 */
	public function save_enquire_only_checkbox($post_id)
	{
		$enquire_only = isset($_POST['_dd_enquire_only']) ? 'yes' : 'no';
		update_post_meta($post_id, '_dd_enquire_only', $enquire_only);
	}

	/**
	 * Custom AJAX endpoint to process cart additions for complex variable products alongside FBT selections.
	 * Now natively accepts dynamic variation mappings processed from the unified frontend interface.
	 *
	 * @since 1.9.1
	 * @return void
	 */
	public function handle_ajax_add_to_cart()
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

		// 1. Process Main Product
		if ($variation_id) {
			$variation = [];
			foreach ($_POST as $key => $value) {
				if (strpos($key, 'attribute_') === 0) {
					$variation[$key] = wc_clean(wp_unslash($value));
				}
			}
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);
		} else {
			$cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
		}

		// 2. Process Composite FBT Items seamlessly
		if (!empty($_POST['fbt_items'])) {
			$fbt_items = json_decode(wp_unslash($_POST['fbt_items']), true);
			
			if (is_array($fbt_items)) {
				foreach ($fbt_items as $item) {
					$item_id  = absint($item['id']);
					$item_qty = absint($item['qty']);
					
					if ($item_id && $item_qty) {
						$fbt_prod = wc_get_product($item_id);
						
						if ($fbt_prod) {
							// If an explicit variation was chosen from our custom FBT dropdowns
							if (isset($item['variation_id']) && !empty($item['variation_id'])) {
								WC()->cart->add_to_cart($item_id, $item_qty, absint($item['variation_id']), $item['attributes']);
							} 
							// If a single variation was explicitly assigned as a cross-sell via the backend
							elseif ($fbt_prod->is_type('variation')) {
								WC()->cart->add_to_cart($fbt_prod->get_parent_id(), $item_qty, $item_id, $fbt_prod->get_attributes());
							} 
							// Standard simple product addition
							else {
								WC()->cart->add_to_cart($item_id, $item_qty);
							}
						}
					}
				}
			}
		}

		if ($cart_item_key) {
			// Leverage WooCommerce core fragments generation to update minicart UIs.
			WC_AJAX::get_refreshed_fragments();
		} else {
			wp_send_json_error(['message' => __('Failed to add product to cart.', 'dd-woo-customizer')]);
		}

		wp_die();
	}

	/**
	 * Enqueue the plugin's custom stylesheet and inline assets.
	 * Conditionally loads the CSS asset solely on WooCommerce-related pages.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public function enqueue_custom_styles()
	{
		if (function_exists('is_woocommerce') && is_woocommerce()) {
			wp_enqueue_style(
				'dd-woo-customizer-css',
				plugin_dir_url(__FILE__) . 'assets/css/dd-woo-customizer.css',
				[],
				'1.9.3',
				'all'
			);

			// Inline styles mapping the bespoke unified variation selections and FBT components
			$custom_css = "
				.dd-downloads-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-top: 15px; }
				.dd-download-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px; display: flex; flex-direction: column; align-items: flex-start; background: #f8fafc; transition: all 0.2s ease-in-out; }
				.dd-download-card:hover { border-color: #cbd5e1; background: #f1f5f9; }
				.dd-download-card h4 { margin: 0 0 12px 0; font-size: 1.25rem; font-weight: 600; color: var(--contrast); }
				.dd-download-btn { display: inline-flex; align-items: center; background: var(--accent); color: var(--base-3); padding: 8px 12px; text-decoration: none; border-radius: 4px; font-weight: 500; font-size: 0.75rem; transition: background 0.2s; }
				.dd-download-btn:hover { background: var(--contrast); color: var(--base-3); }
				.dd-download-btn svg { width: 16px; height: 16px; margin-left: 8px; fill: currentColor; }
				.upsells.products { margin-top: 4em; } 
				
				/* FBT Unified Modern Layout Customization */
				.dd-fbt-wrapper { margin-bottom: 25px; }
				.dd-fbt-wrapper h4 { margin: 0 0 15px 0; font-size: 14px; font-weight: 600; }
				.dd-fbt-list { display: flex; flex-direction: column; gap: 10px; }
				.dd-fbt-item { gap: 1rem; display: flex; align-items: center; justify-content: space-between; padding: 15px; border: 1px solid #e2e8f0; border-radius: 8px; background: #fff; transition: all 0.2s ease; }
				.dd-fbt-item.is-selected { border-color: #ff0000; background: #fff5f5; }
				
				/* FBT Locked/Disabled states */
				.dd-fbt-item:not(.is-selected) .dd-fbt-qty,
				.dd-fbt-item:not(.is-selected) .dd-fbt-variable-options { opacity: 0.5; pointer-events: none; transition: all 0.2s ease; }
				.dd-fbt-item:not(.is-selected) .dd-fbt-qty { background: #f1f5f9; }
				.dd-fbt-item:not(.is-selected) .dd-fbt-variation-select { background: #e2e8f0; border-color: #cbd5e1; color: #64748b; }
				
				.dd-fbt-checkbox-wrapper { position: relative; padding-left: 25px; cursor: pointer; display: flex; align-items: center; height: 20px; margin: 0;}
				.dd-fbt-checkbox-wrapper input { position: absolute; opacity: 0; cursor: pointer; height: 0; width: 0; }
				.dd-fbt-checkmark { position: absolute; left: 0; top: 0; height: 20px; width: 20px; background-color: #fff; border-radius: 50%; border: 2px solid #cbd5e1; transition: 0.2s;}
				.dd-fbt-checkbox-wrapper input:checked ~ .dd-fbt-checkmark { border-color: #ff0000; }
				.dd-fbt-checkbox-wrapper input:checked ~ .dd-fbt-checkmark:after { content: ''; position: absolute; display: block; top: 4px; left: 4px; width: 8px; height: 8px; border-radius: 50%; background: #ff0000; }
				
				.dd-fbt-main { display: flex; align-items: flex-start; gap: 15px; flex-grow: 1;}
				.dd-fbt-main img { width: 60px; height: 60px; object-fit: contain; }
				.dd-fbt-details { display: flex; flex-direction: column; gap: 8px; width: 100%;}
				.dd-fbt-title { font-size: 0.9rem; font-weight: 500; color: #000000; line-height: 1.2;}
				
				.dd-fbt-qty { display: flex; align-items: center; border-radius: 4px; width: fit-content;}
				.dd-qty-btn.dd-qty-btn { padding: 2px 8px; cursor: pointer; font-size: 1rem; color: #64748b; line-height: 1; border: 1px solid #EAEAEA; background-color: #fff; height: 24px; width: 24px; display: flex; align-items: center; justify-content: center; }
				.dd-qty-btn:hover { color: #000000; }
				.dd-fbt-qty-input.dd-fbt-qty-input { min-height: unset; border: none !important; background: transparent !important; width: 30px !important; text-align: center; padding: 0 !important; font-size: 0.85rem; -moz-appearance: textfield; box-shadow: none !important;}
				.dd-fbt-qty-input::-webkit-outer-spin-button, .dd-fbt-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
				
				.dd-fbt-price-wrap { text-align: right; white-space: nowrap; align-self: flex-start; }
				.dd-fbt-price { font-size: 0.95rem; font-weight: 700; color: #ff0000; display: block; }
				.dd-fbt-price-suffix { font-size: 0.65rem; color: #94a3b8; font-weight: 400;}

				/* Variable FBT Options Layout */
				.dd-fbt-variable-options { width: 100%; margin-top: 12px; padding-top: 10px; border-top: 1px dashed #e2e8f0; }
				.dd-fbt-attribute-row { margin-bottom: 8px; width: 100%; display: flex; align-items: center; gap: 0.5rem }
				.dd-fbt-attribute-row label { display: block; font-size: 0.8rem; font-weight: 600; color: #000; margin-bottom: 4px; white-space: nowrap}
				.dd-fbt-variation-select.dd-fbt-variation-select { min-height: unset; width: 100%; max-width: 100%; font-size: 0.85rem; padding: 6px 8px; border-radius: 4px; border: 1px solid #cbd5e1; background: #f8fafc; }

				/* Enquire Now Button overriding */
				.dd-enquire-btn { width: 100%; margin-top: 15px; background: #ff0000 !important; color: #fff !important; border-radius: 4px; font-weight: 600; padding: 15px !important; border: none; cursor: pointer; transition: opacity 0.2s; }
				.dd-enquire-btn:hover { opacity: 0.9; }
			";
			wp_add_inline_style('dd-woo-customizer-css', $custom_css);
		}
	}

	/**
	 * Output a custom opening HTML `<div>` wrapper before the main WooCommerce content.
	 */
	public function add_custom_wrapper_open()
	{
		echo '<div class="dd-woo-custom-container">';
	}

	/**
	 * Output a custom closing HTML `<div>` wrapper after the main WooCommerce content.
	 */
	public function add_custom_wrapper_close()
	{
		echo '</div>';
	}

	/**
	 * Render a custom checkbox in the Product -> Attributes tab within the admin dashboard.
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
				color: var(--contrast);
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
	 */
	public function modify_breadcrumb_delimiter($defaults)
	{
		$defaults['delimiter'] = ' <span class="sep">❯</span> ';
		return $defaults;
	}

	/**
	 * Disables core WooCommerce review and rating functionalities.
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
	 */
	public function reorder_upsells_and_related_products()
	{
		remove_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 15);
		add_action('woocommerce_after_single_product_summary', 'woocommerce_upsell_display', 25);
	}

	/**
	 * Renders the unified Frequently Bought Together checkboxes directly inside the main cart form.
	 * Now injects dynamic variation dropdowns for Parent Variable products, explicitly disabling 
	 * the custom variation card layouts for these nested FBT items to force native dropdowns.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public function display_frequently_bought_together_and_enquire_btn()
	{
		global $product;

		if (! $product) {
			return;
		}

		$cross_sell_ids = $product->get_cross_sell_ids();

		// Only output the FBT wrapper if there are actually cross sells assigned
		if (!empty($cross_sell_ids)) {
			echo '<div class="dd-fbt-wrapper">';
			echo '<h4>' . esc_html__('Frequently bought together', 'dd-woo-customizer') . '</h4>';
			echo '<div class="dd-fbt-list">';

			foreach ($cross_sell_ids as $cross_sell_id) {
				$cross_sell = wc_get_product($cross_sell_id);

				if (! $cross_sell) {
					continue;
				}

				// Exclude products that are not visible (explicit variations skip this check)
				if (! $cross_sell->is_type('variation') && ! $cross_sell->is_visible()) {
					continue;
				}

				$title = $cross_sell->get_name();
				$price_html = $cross_sell->get_price_html();
				$image = $cross_sell->get_image('woocommerce_gallery_thumbnail');

				// Output unified checkbox row
				echo '<div class="dd-fbt-item">';
				
				// Checkbox toggle
				echo '<label class="dd-fbt-checkbox-wrapper">';
				echo '<input type="checkbox" class="dd-fbt-checkbox" value="' . absint($cross_sell->get_id()) . '" data-title="' . esc_attr($title) . '" />';
				echo '<span class="dd-fbt-checkmark"></span>';
				echo '</label>';

				// Main visual area (Image, Title, Quantity Increments)
				echo '<div class="dd-fbt-main">';
				echo wp_kses_post($image);
				
				echo '<div class="dd-fbt-details" style="width: 100%;">';
				echo '<span class="dd-fbt-title">' . esc_html($title) . '</span>';
				
				// Custom inline quantity selector for FBT elements
				echo '<div class="dd-fbt-qty">';
				echo '<button type="button" class="dd-qty-btn dd-qty-minus" disabled>-</button>';
				echo '<input type="number" class="dd-fbt-qty-input" value="1" min="1" step="1" readonly disabled />';
				echo '<button type="button" class="dd-qty-btn dd-qty-plus" disabled>+</button>';
				echo '</div>'; // close qty

				// Variable Product Options Injection - Enables selection without breaking `<form>` boundaries
				if ($cross_sell->is_type('variable')) {
					$attributes = $cross_sell->get_variation_attributes();
					$available_variations = $cross_sell->get_available_variations();

					echo '<div class="dd-fbt-variable-options" data-product-id="' . esc_attr($cross_sell->get_id()) . '" data-variations="' . htmlspecialchars( wp_json_encode( $available_variations ), ENT_QUOTES, 'UTF-8' ) . '">';
					
					// Temporarily remove variation cards filter to force native dropdowns inside FBT
					remove_filter('woocommerce_dropdown_variation_attribute_options_html', [$this, 'render_custom_variation_cards'], 10);

					foreach ( $attributes as $attribute_name => $options ) {
						echo '<div class="dd-fbt-attribute-row">';
						echo '<label>' . wc_attribute_label( $attribute_name ) . '</label>';
						
						// Dynamically attach the 'fbt_attribute_' prefix to avoid mutating the main product attributes
						wc_dropdown_variation_attribute_options( array(
							'options'   => $options,
							'attribute' => $attribute_name,
							'product'   => $cross_sell,
							'class'     => 'dd-fbt-variation-select',
							'name'      => 'fbt_attribute_' . sanitize_title( $attribute_name ),
							'id'        => 'fbt_attr_' . $cross_sell->get_id() . '_' . sanitize_title( $attribute_name )
						) );
						echo '</div>';
					}

					// Restore custom variation cards for the rest of the page
					add_filter('woocommerce_dropdown_variation_attribute_options_html', [$this, 'render_custom_variation_cards'], 10, 2);
					
					// This captures the derived variation ID once the JS parses the user selections above
					echo '<input type="hidden" class="dd-fbt-variation-id" value="" />';
					echo '</div>';
				}

				echo '</div>'; // close details
				echo '</div>'; // close main

				// Right-aligned Price Area
				echo '<div class="dd-fbt-price-wrap">';
				echo '<span class="dd-fbt-price">' . wp_kses_post($price_html) . '</span>';
				echo '<span class="dd-fbt-price-suffix">' . esc_html__('excl. VAT', 'dd-woo-customizer') . '</span>';
				echo '</div>';

				echo '</div>'; // close item
			}

			echo '</div>'; // close list
			echo '</div>'; // close wrapper
		}

		// Dynamically inject Enquire Now button logic based on custom Meta Flag
		$is_enquire_only = get_post_meta($product->get_id(), '_dd_enquire_only', true) === 'yes';
		
		if ($is_enquire_only) {
			// Output our custom trigger button that mimics the native add to cart styling
			echo '<button type="button" class="dd-enquire-btn button alt">' . esc_html__('ENQUIRE NOW', 'dd-woo-customizer') . '</button>';
			// Inject CSS to gracefully hide the standard WooCommerce add to cart button
			echo '<style>.single_add_to_cart_button { display: none !important; }</style>';
		}
	}

	/**
	 * Injects unified JavaScript logic for the composite Add to Cart parsing, including
	 * the new dynamic variation attribute matcher ensuring Variable FBT products require valid selections.
	 *
	 * @since 1.9.3
	 * @return void
	 */
	public function inject_ajax_add_to_cart_scripts()
	{
		if (! is_product()) {
			return;
		}
	?>
		<script type="text/javascript">
			jQuery(document).ready(function($) {

				// UI Logics for FBT Checkboxes and Quantity Increments
				$(document).on('click', '.dd-qty-plus', function() {
					var $input = $(this).siblings('.dd-fbt-qty-input');
					$input.val(parseInt($input.val()) + 1);
				});

				$(document).on('click', '.dd-qty-minus', function() {
					var $input = $(this).siblings('.dd-fbt-qty-input');
					var val = parseInt($input.val());
					if (val > 1) {
						$input.val(val - 1);
					}
				});

				// Initialize disabled states for FBT controls on page load
				$('.dd-fbt-item').each(function() {
					var isChecked = $(this).find('.dd-fbt-checkbox').is(':checked');
					$(this).find('.dd-qty-btn, .dd-fbt-variation-select').prop('disabled', !isChecked);
				});

				// Manage control accessibility based on checkbox selection
				$(document).on('change', '.dd-fbt-checkbox', function() {
					var $item = $(this).closest('.dd-fbt-item');
					
					if ($(this).is(':checked')) {
						$item.addClass('is-selected');
						$item.find('.dd-qty-btn, .dd-fbt-variation-select').prop('disabled', false);
					} else {
						$item.removeClass('is-selected');
						$item.find('.dd-qty-btn, .dd-fbt-variation-select').prop('disabled', true);
					}
				});

				// FBT Dynamic Variation Matcher for Parent Variable cross-sells
				$(document).on('change', '.dd-fbt-variation-select', function() {
					var $optionsContainer = $(this).closest('.dd-fbt-variable-options');
					var variations = JSON.parse($optionsContainer.attr('data-variations'));
					var selectedAttributes = {};
					var allSelected = true;
					
					// Aggregate the specific attributes assigned by the user
					$optionsContainer.find('.dd-fbt-variation-select').each(function() {
						var val = $(this).val() || '';
						var name = $(this).attr('name').replace('fbt_', ''); // Isolate base taxonomy key
						selectedAttributes[name] = val;
						if (val === '') {
							allSelected = false;
						}
					});

					// Reset matching states if not all dropdowns are satisfied
					if (!allSelected) {
						$optionsContainer.find('.dd-fbt-variation-id').val('');
						return; 
					}

					// Intercept and match the isolated attributes against the core WooCommerce JSON object
					var match = variations.find(function(v) {
						return Object.keys(selectedAttributes).every(function(key) {
							// Return true if variation allows 'Any', or if explicit value equals dropdown string
							return v.attributes[key] === '' || v.attributes[key] === selectedAttributes[key];
						});
					});

					var $item = $optionsContainer.closest('.dd-fbt-item');

					// Dynamic DOM overrides mirroring native WC behaviors
					if (match) {
						$optionsContainer.find('.dd-fbt-variation-id').val(match.variation_id);
						if (match.price_html) {
							$item.find('.dd-fbt-price').html(match.price_html);
						}
						if (match.image && match.image.thumb_src) {
							$item.find('.dd-fbt-main img').attr('src', match.image.thumb_src).attr('srcset', '');
						}
					} else {
						$optionsContainer.find('.dd-fbt-variation-id').val('');
					}
				});

				// Pre-initialize variation matchers in case variables load with specific default selections
				$('.dd-fbt-variation-select').trigger('change');

				// Unified Cart Submission Interceptor
				$(document).on('submit', 'form.cart', function(e) {
					e.preventDefault();
					e.stopImmediatePropagation();

					var $form = $(this);
					var $btn = $form.find('button[type="submit"]');

					if ($btn.is('.disabled')) {
						return false;
					}

					$btn.addClass('loading wc-loading');

					var formData = new FormData($form[0]);
					formData.append('action', 'dd_ajax_add_to_cart');

					var productId = $btn.val() || $form.find('input[name="add-to-cart"]').val();
					if (productId) {
						formData.append('product_id', productId);
					}

					// Safely parse dynamically selected FBT variables and specific variation matrices
					var fbtItems = [];
					var hasValidationErrors = false;

					$('.dd-fbt-checkbox:checked').each(function() {
						var $item = $(this).closest('.dd-fbt-item');
						var pid = $(this).val();
						var qty = $item.find('.dd-fbt-qty-input').val();
						var isVariable = $item.find('.dd-fbt-variable-options').length > 0;

						// If FBT item requires options, halt execution and enforce completion
						if (isVariable) {
							var variationId = $item.find('.dd-fbt-variation-id').val();
							if (!variationId) {
								alert('Please select all options for: ' + $(this).data('title'));
								hasValidationErrors = true;
								return false; // Safely breaks out of the loop iteration
							}
							
							var attributes = {};
							$item.find('.dd-fbt-variation-select').each(function() {
								var name = $(this).attr('name').replace('fbt_', '');
								attributes[name] = $(this).val();
							});

							fbtItems.push({ id: pid, variation_id: variationId, qty: qty, attributes: attributes });
						} else {
							fbtItems.push({ id: pid, qty: qty });
						}
					});

					// Abandon AJAX execution if the user failed the strict variation selection parameters
					if (hasValidationErrors) {
						$btn.removeClass('loading wc-loading');
						return false; 
					}

					formData.append('fbt_items', JSON.stringify(fbtItems));

					var ajaxUrl = (typeof woocommerce_params !== 'undefined') ? woocommerce_params.ajax_url : '/wp-admin/admin-ajax.php';

					$.ajax({
						type: 'POST',
						url: ajaxUrl,
						data: formData,
						processData: false,
						contentType: false,
						success: function(response) {
							if (response && response.fragments) {
								$(document.body).trigger('added_to_cart', [response.fragments, response.cart_hash, $btn]);
								$btn.removeClass('loading wc-loading');
								
								var originalText = $btn.html();
								$btn.html('Added to cart!');
								setTimeout(function() {
									$btn.html(originalText);
								}, 3000);

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

				// Enquire Now GeneratePress Overlay Action Handler
				$(document).on('click', '.dd-enquire-btn', function(e) {
					e.preventDefault();

					// 1. Capture Contextual Data Strings
					var mainTitle = $('h1.product_title').text().trim();
					var mainQty   = $('form.cart .quantity input.qty').val() || 1;
					
					var productsText = mainTitle + " - Qty: " + mainQty + "\n";

					$('.dd-fbt-checkbox:checked').each(function() {
						var $item = $(this).closest('.dd-fbt-item');
						var title = $(this).data('title');
						var qty   = $item.find('.dd-fbt-qty-input').val();
						var variationString = "";

						// Append selected variation options to the enquiry text
						var $varOptions = $item.find('.dd-fbt-variation-select');
						if ($varOptions.length > 0) {
							var opts = [];
							$varOptions.each(function() {
								if ($(this).val()) {
									opts.push($(this).val());
								}
							});
							if (opts.length > 0) {
								variationString = " (" + opts.join(', ') + ")";
							}
						}

						productsText += title + variationString + " - Qty: " + qty + "\n";
					});

					// 2. Locate the specific textarea in the GeneratePress off-canvas menu and map data
					var $textarea = $('textarea[name="products"]');
					if ($textarea.length > 0) {
						$textarea.val(productsText.trim());
					}

					// 3. Trigger GP Slideout Execution
					var $slideoutToggleLink = $('.slideout-toggle a');
					var $slideoutToggleObj  = $('.slideout-toggle');

					if ($slideoutToggleLink.length > 0) {
						$slideoutToggleLink[0].click(); // Mimic exact native mouse click logic
					} else if ($slideoutToggleObj.length > 0) {
						$slideoutToggleObj[0].click();
					} else {
						// Fallback trigger mechanisms for missing icon containers
						$('body').addClass('offside-js--is-open slide-opened');
						$('.slideout-overlay').show();
					}
				});

			});
		</script>
<?php
	}
}

// Initialize the plugin instance only if WooCommerce is active to prevent fatal errors on deactivation.
add_action('plugins_loaded', function () {
	if (class_exists('WooCommerce')) {
		new DD_WooCommerce_Customizer();
	}
});