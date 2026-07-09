# DD WooCommerce Customizer

A single-file WordPress plugin (no build step, no dependencies) that layers bespoke
WooCommerce behavior on top of a GeneratePress theme for the Digitally Disruptive site.
Everything lives in one PHP class plus one stylesheet — there is no PHP autoloader,
Composer, npm, or test suite in this repo.

## Files

- `dd-woocommerce-customizer.php` — the entire plugin. One class, `DD_WooCommerce_Customizer`,
  instantiated at the bottom of the file. All hooks are registered in `__construct()`;
  every method below it is a callback named for the hook it serves.
- `assets/css/dd-woo-customizer.css` — enqueued only on WooCommerce pages
  (`enqueue_custom_styles()`), plus a large block of `wp_add_inline_style()` CSS defined
  inline in that same method (FBT UI, variation price overrides, enquire button, etc.).
  Layout/typography rules for single-product and shop pages live in the CSS file;
  component-level rules for JS-driven widgets tend to live inline in the PHP (kept next
  to the markup/script that generates them).

There is no build process — edit the PHP/CSS directly and the changes are live (WordPress
picks up plugin file changes on the next request; browsers may cache the enqueued CSS,
bump the version string in `wp_enqueue_style()` if changes aren't showing).

## Architecture

The plugin bolts several independent features onto WooCommerce via actions/filters,
all wired in `__construct()`:

- **Layout wrapper**: wraps main WooCommerce content in a `.dd-woo-custom-container` div
  (`add_custom_wrapper_open/close`) for CSS targeting.
- **Variation cards**: replaces WooCommerce's default `<select>` variation dropdowns with
  clickable image/price cards (`render_custom_variation_cards`, `inject_variation_ui_assets`)
  for attributes an admin has flagged via a checkbox added to the Attributes tab
  (`add_card_layout_checkbox` / `save_card_layout_configuration`, stored as post meta
  `_dd_card_attributes`). Only applies to custom (non-taxonomy) attributes.
- **Custom product data tabs** (admin): adds "Features" (WYSIWYG), "Downloads" (a JS
  repeater of title/file pairs with a jQuery UI sortable + media uploader), and "Product
  Settings" (an "Enquire Product Only" checkbox) tabs to the Product Data metabox
  (`add_custom_product_data_tabs/panels`, `save_custom_product_meta_data`). Meta keys:
  `_dd_product_features`, `_dd_product_downloads`, `_dd_enquire_only`.
- **Frontend product tabs**: surfaces Features/Downloads content as customer-facing tabs
  when populated (`add_frontend_product_tabs`).
- **Reviews disabled globally**: `disable_woocommerce_reviews`, `remove_reviews_tab`,
  `force_close_product_comments` fully strip WooCommerce's rating/review system.
- **Layout reordering**: moves "You May Also Like" (upsells) below Related Products
  (`reorder_upsells_and_related_products`); strips default breadcrumbs/titles on Shop and
  category archive pages (`remove_woocommerce_archive_headers`).
- **Frequently Bought Together (FBT) + Enquire system**: the largest feature.
  `display_frequently_bought_together_and_enquire_btn` renders cross-sell products as
  checkbox rows (with quantity steppers and, for variable products, native variation
  dropdowns — variation *cards* are deliberately disabled here so FBT rows stay compact)
  directly inside the main add-to-cart form. `inject_ajax_add_to_cart_scripts` provides
  the client-side logic: live grand-total calculation, a unified AJAX submit handler that
  adds the main product plus all checked FBT items in one request, and (when a product is
  flagged `_dd_enquire_only`) an "Enquire Now" button that populates a GenerateBlocks
  overlay form field with a formatted summary of the selected items instead of adding to
  cart. `handle_ajax_add_to_cart` is the server-side AJAX endpoint
  (`wp_ajax_dd_ajax_add_to_cart`) that processes both the main product and FBT items in one
  cart operation.
- **Global settings page**: WooCommerce → DD Customizer (`add_admin_menu`,
  `register_admin_settings`, `render_admin_settings_page`) lets an admin pick which
  GenerateBlocks Overlay Panel the Enquire button opens (`dd_enquire_overlay_id`) and which
  CSS selector inside it receives the enquiry text (`dd_enquire_target_field`).
- **Product Categories accordion**: `inject_product_categories_accordion_scripts`
  progressively enhances the WooCommerce Product Categories block's plain nested
  `<ul>`/`<li>` markup into a collapsible accordion (one branch open at a time) via
  vanilla JS; corresponding styles are in the CSS file under the
  `.dd-cat-accordion` section.

## Conventions / gotchas

- All CSS/JS for a feature is usually inlined in its PHP method (via `<style>`/`<script>`
  tags echoed on `wp_footer`, or `wp_add_inline_style`/`wp_add_inline_script`) rather than
  split into separate asset files — keep new features consistent with this unless the
  amount of CSS/JS is large enough to warrant a stylesheet section.
  `assets/css/dd-woo-customizer.css` is reserved mainly for page/layout-level rules and the
  category accordion.
  - **Global overlay hard-coded**: `single_add_to_cart_button` is force-hidden with an
  inline `<style>` when `_dd_enquire_only` is set — a targeted disable, not a form removal,
  so quantity fields stay visible per plugin description.
- The custom AJAX add-to-cart handler strips the native `add-to-cart` field from the
  submitted `FormData` before posting — leaving it in causes WooCommerce's core
  `WC_Form_Handler` to double-add the product (see comment at the submit handler in
  `inject_ajax_add_to_cart_scripts`).
- Variation cards and FBT variation dropdowns share the same
  `woocommerce_dropdown_variation_attribute_options_html` filter; FBT rendering
  temporarily unhooks/rehooks `render_custom_variation_cards` so cross-sell items always
  get plain dropdowns regardless of the main product's card configuration.
- Bump the version string passed to `wp_enqueue_style()` in `enqueue_custom_styles()`
  (and the plugin header `Version:`) when shipping CSS changes, since asset caching keys
  off it.
