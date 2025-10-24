<?php

// phpcs:ignore

namespace Otomaties\WpSyncPosts;

/**
 * Sync WordPress posts with an external API
 */
class Product extends Post
{
    private $woocommerceArgs = [];

    private $existingVariationQuery;

    /**
     * List of valid arguments
     *
     * @var array
     */
    private $availableWoocommerceMeta = [
        '_visibility',
        '_stock_status',
        'total_sales',
        '_downloadable',
        '_virtual',
        '_regular_price',
        '_sale_price',
        '_purchase_note',
        '_featured',
        '_weight',
        '_length',
        '_width',
        '_height',
        '_sku',
        '_product_attributes',
        '_sale_price_dates_from',
        '_sale_price_dates_to',
        '_price',
        '_sold_individually',
        '_backorders',
        '_variation_description',
    ];

    private $defaultWoocommerceArgs = [
        'meta_input' => [],
        'product_type' => 'simple',
        'available_attributes' => [],
        'variations' => [],
    ];

    public function __construct(
        string $postType,
        array $args,
        array $existingPostQuery,
        ?array $existingVariationQuery = null
    ) {
        $this->existingVariationQuery = $existingVariationQuery;
        $this->availableArgs[] = 'woocommerce';

        $this->woocommerceArgs = wp_parse_args($args['woocommerce'], $this->defaultWoocommerceArgs);
        $this->woocommerceArgs['meta_input'] = $this->removeUnsupportedArgs(
            $this->woocommerceArgs['meta_input'],
            $this->availableWoocommerceMeta
        );

        $this->checkRequiredVariationParamaters();

        parent::__construct($postType, $args, $existingPostQuery);
    }

    private function checkRequiredVariationParamaters(): void
    {
        $requiredVariationParameters = [
            'available_attributes' => ! empty($this->woocommerceArgs['available_attributes']),
            'existingVariationQuery' => (bool) $this->existingVariationQuery,
        ];
        if (array_sum($requiredVariationParameters) != 0 && array_sum($requiredVariationParameters) != 2) {
            throw new \Exception('Not all required variation parameters {$args[\'woocommerce\'][\'available_attributes\'], $existingVariationQuery} are set. Add them all or remove them all'); // phpcs:ignore Generic.Files.LineLength.TooLong
        }
    }

    public function save(): int
    {
        $productId = parent::save();
        $product = wc_get_product($productId);

        $this->updateProductMeta($product);

        wc_delete_product_transients($productId);
        wp_cache_delete($productId, 'products');
        clean_post_cache($productId);

        Logger::log('Product save triggered');

        return $productId;
    }

    private function updateProductMeta($product): void
    {
        $internalMetaKeys = $product->get_data_store()->get_internal_meta_keys();
        foreach ($this->woocommerceArgs['meta_input'] as $key => $value) {
            if (in_array($key, $internalMetaKeys, true)) {
                $function = 'set_'.ltrim($key, '_');
                $product->{$function}($value);
            } else {
                $product->update_meta_data($key, $value);
            }
        }

        // Sync stock.
        $this->syncProductStock(
            $product,
            $this->woocommerceArgs['meta_input']['_stock'] ?? null,
            $this->woocommerceArgs['meta_input']['_backorders'] ?? null
        );

        // Set product type.
        $productType = $this->woocommerceArgs['product_type'] ?? null;
        if ($productType) {
            wp_set_object_terms($product->get_ID(), $productType, 'product_type');
        }

        // Variations.
        if (! empty($this->woocommerceArgs['available_attributes'])) {
            // Create attributes.
            $this->insertProductAttributes(
                $product->get_ID(),
                $this->woocommerceArgs['available_attributes'],
                $this->woocommerceArgs['variations']
            );

            if ($productType && $productType == 'variable') {
                // Create variations.
                $this->syncProductVariations($product->get_ID(), $this->woocommerceArgs['variations']);
            }
        }
    }

    protected function find(array $query, ?string $postType = null): int
    {
        if ($query['by'] == 'sku') {
            $postId = 0;
            $args = [
                'post_type' => $postType ?: $this->postType,
                'post_status' => get_post_stati(),
                'suppress_filters' => apply_filters('wp_sync_posts_suppress_filters', false),
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_query' => [
                    [
                        'key' => '_sku',
                        'value' => $query['value'],
                        'compare' => $query['compare'] ?: '=',
                    ],
                ],
            ];

            $postIds = get_posts($args);

            if (isset($postIds[0])) {
                $postId = $postIds[0];
                Logger::log(sprintf('Found product: #%s', $postId));
            } else {
                Logger::log(sprintf('Product not found'));
            }

            return $postId;
        }

        return parent::find($query);
    }

    /**
     * Sync product stock, set backorders, manage stock
     *
     * @param  ?int  $stockAmount  The amount of products in stock.
     * @param  string  $backorders  Whether backorders are allowed.
     */
    private function syncProductStock(\WC_Product $product, ?int $stockAmount = null, string $backorders = 'no'): void
    {
        $product->set_backorders($backorders);

        if ($stockAmount) {
            wc_update_product_stock($product->get_ID(), $stockAmount);
            if ($stockAmount <= 0) {
                if ($backorders != 'no') {
                    $product->set_stock_status('instock');
                } else {
                    $product->set_stock_status('outofstock');
                }
            } else {
                $product->set_stock_status('instock');
            }
            $product->set_manage_stock(true);
        } else {
            $product->set_manage_stock(false);
        }
    }

    /**
     * Add new product attributes
     *
     * @param  int  $postId  The post ID.
     * @param  array  $availableAttributes  The available attributes.
     * @param  array  $variations  The variations.
     */
    private function insertProductAttributes(int $postId, array $availableAttributes, array $variations): void
    {
        foreach ($availableAttributes as $attribute) {
            $values = [];

            foreach ($variations as $variation) {
                $attribute_keys = array_keys($variation['woocommerce']['attributes']);

                foreach ($attribute_keys as $key) {
                    if ($key === $attribute) {
                        $values[] = $variation['woocommerce']['attributes'][$key];
                    }
                }
            }
            $values = array_unique($values);
            $this->proccessAddAttribute([
                'attribute_name' => $attribute,
                'attribute_label' => $attribute,
                'attribute_type' => 'text',
                'attribute_orderby' => 'menu_order',
                'attribute_public' => false,
            ]);
            wp_set_object_terms($postId, $values, 'pa_'.$attribute);
        }

        $productAttributesData = [];

        foreach ($availableAttributes as $attribute) {
            $productAttributesData['pa_'.$attribute] = [
                'name' => 'pa_'.$attribute,
                'value' => '',
                'is_visible' => '1',
                'is_variation' => '1',
                'is_taxonomy' => '1',
            ];
        }

        update_post_meta($postId, '_product_attributes', $productAttributesData);
    }

    /**
     * Insert attribute
     *
     * @param  array  $attribute  Attribute array.
     */
    private function proccessAddAttribute(array $attribute): \WP_Error|bool
    {
        global $wpdb;

        $attribute['attribute_type'] = $attribute['attribute_type'] ?? 'text';
        $attribute['attribute_orderby'] = $attribute['attribute_orderby'] ?? 'menu_order';
        $attribute['attribute_public'] = $attribute['attribute_public'] ?? 0;

        if (empty($attribute['attribute_name']) || empty($attribute['attribute_label'])) {
            return new \WP_Error('error', __('Please, provide an attribute name and slug.', 'woocommerce'));
        } elseif (($validAttributeName = $this->validAttributeName($attribute['attribute_name'])) && is_wp_error($validAttributeName)) { // phpcs:ignore Generic.Files.LineLength.TooLong
            return $validAttributeName;
        } elseif (taxonomy_exists(wc_attribute_taxonomy_name($attribute['attribute_name']))) {
            return new \WP_Error('error', sprintf(
                __('Slug "%s" is already in use. Change it, please.', 'woocommerce'),
                sanitize_title($attribute['attribute_name'])
            ));
        }

        $wpdb->insert($wpdb->prefix.'woocommerce_attribute_taxonomies', $attribute);

        do_action('woocommerce_attribute_added', $wpdb->insert_id, $attribute);

        flush_rewrite_rules();
        delete_transient('wc_attribute_taxonomies');

        return true;
    }

    /**
     * Check if attribute name is valid
     *
     * @param  string  $attributeName  The desired name of the attribute
     */
    private function validAttributeName(string $attributeName): \WP_Error|bool
    {
        if (strlen($attributeName) >= 28) {
            return new \WP_Error('error', sprintf(
                __('Slug "%s" is too long (28 characters max). Shorten it, please.', 'woocommerce'),
                sanitize_title($attributeName)
            ));
        } elseif (wc_check_if_attribute_name_is_reserved($attributeName)) {
            return new \WP_Error('error', sprintf(
                __('Slug "%s" is not allowed because it is a reserved term. Change it, please.', 'woocommerce'),
                sanitize_title($attributeName)
            ));
        }

        return true;
    }

    /**
     * Synchronize production variations
     *
     * @param  int  $productId  The product ID.
     * @param  array  $variations  Variations.
     */
    private function syncProductVariations(int $productId, array $variations): void
    {
        $syncedVariations = [];
        $existingVariationQuery = $this->existingVariationQuery;
        if (isset($existingVariationQuery['by'])) {
            $by = $existingVariationQuery['by'];

            foreach ($variations as $index => $variation) {
                if ($by == 'sku') {
                    $existingVariationQuery = [
                        'by' => $by,
                        'value' => $variation['woocommerce']['meta_input']['_sku'],
                    ];
                }

                $postId = $this->find($existingVariationQuery, 'product_variation');
                if ($postId == 0) {
                    $postId = $this->insertProductVariation($productId, $index, $variation, count($variations));
                } else {
                    $postId = $this->updateProductVariation($postId, $variation);
                }

                // Import media.
                if (isset($variation['media'])) {
                    foreach ($variation['media'] as $key => $item) {
                        $media_sync = new Media($item);
                        $attachment_id = $media_sync->importAndAttachToPost($postId);

                        if (isset($item['key'])) {
                            update_post_meta($postId, $item['key'], $attachment_id);
                        }

                        if (isset($item['featured']) && $item['featured']) {
                            set_post_thumbnail($postId, $attachment_id);
                        }
                    }
                }

                array_push($syncedVariations, $postId);
            }
        }

        $this->cleanUpVariations($productId, $syncedVariations);
    }

    private function cleanUpVariations(int $postParent, array $syncedVariations): void
    {
        collect(get_posts([
            'post_type' => 'product_variation',
            'posts_per_page' => -1,
            'post_parent' => $postParent,
            'post__not_in' => $syncedVariations,
            'post_status' => get_post_stati(),
            'suppress_filters' => apply_filters('wp_sync_posts_suppress_filters', false),
        ]))
            ->each(
                function ($deletePost) {
                    Logger::log(sprintf('Deleting variation %s', $deletePost->ID));
                    wp_delete_post($deletePost->ID, true);
                }
            );
    }

    /**
     * Add new product variations
     *
     * @param  int  $productId  The product ID.
     * @param  int  $index  Index of the current variation.
     * @param  array  $variation  Variation array.
     * @param  int  $variationsCount  Total amount of variations.
     * @return int The variation ID.
     */
    private function insertProductVariation(int $productId, int $index, array $variation, int $variationsCount): int
    {
        $variation_post = [
            'post_title' => 'Variation #'.$index.' of '.$variationsCount.' for product#'.$productId,
            'post_name' => 'product-'.$productId.'-variation-'.$index,
            'post_status' => 'publish',
            'post_parent' => $productId,
            'post_type' => 'product_variation',
            'guid' => home_url().'/?product_variation=product-'.$productId.'-variation-'.$index,
        ];

        $variationId = wp_insert_post($variation_post);
        Logger::log(sprintf('Inserted variation %s', $variationId));

        foreach ($variation['woocommerce']['attributes'] as $attribute => $value) {
            $attribute_term = get_term_by('name', $value, 'pa_'.$attribute);
            update_post_meta($variationId, 'attribute_pa_'.$attribute, $attribute_term->slug);
        }

        $this->syncVariationMeta($variationId, $variation);
        $this->syncProductStock(
            wc_get_product($variationId),
            (isset($variation['woocommerce']['_stock']) ? $variation['woocommerce']['_stock'] : null),
            (isset($variation['woocommerce']['_backorders']) ? $variation['woocommerce']['_backorders'] : 'no')
        );

        return $variationId;
    }

    /**
     * Update product variation
     *
     * @param  int  $variationId  The ID of the variation.
     * @param  array  $variation  The variation array.
     */
    private function updateProductVariation(int $variationId, array $variation): int
    {
        Logger::log(sprintf('Updating variation %s', $variationId));

        foreach ($variation['woocommerce']['attributes'] as $attr => $value) {
            update_post_meta($variationId, 'attribute_pa_'.$attr, $value);
        }

        $this->syncVariationMeta($variationId, $variation);
        $this->syncProductStock(
            wc_get_product($variationId),
            (isset($variation['woocommerce']['_stock']) ? $variation['woocommerce']['_stock'] : null),
            (isset($variation['woocommerce']['_backorders']) ? $variation['woocommerce']['_backorders'] : 'no')
        );

        return $variationId;
    }

    /**
     * Sync variation meta
     *
     * @param  int  $variationId  The ID of the variation.
     * @param  array  $variation  The variation array.
     * @return void
     */
    private function syncVariationMeta($variationId, $variation)
    {
        $variationMeta = $this->removeUnsupportedArgs(
            $variation['woocommerce']['meta_input'],
            $this->availableWoocommerceMeta
        );

        foreach ($variationMeta as $key => $value) {
            update_post_meta($variationId, $key, $value);
        }
    }
}
