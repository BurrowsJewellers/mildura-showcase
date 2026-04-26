<?php

namespace App\Services\GraphQL;

class ProductMutations
{
    /**
     * Create a product. Uses ProductCreateInput (the non-deprecated input
     * type). productCreate auto-creates a single default variant with no SKU;
     * its ID is returned so the caller can populate it via
     * productVariantsBulkUpdate.
     */
    public static function createProduct(): string
    {
        return <<<'GRAPHQL'
        mutation createProduct($product: ProductCreateInput!) {
            productCreate(product: $product) {
                product {
                    id
                    title
                    handle
                    vendor
                    productType
                    status
                    tags
                    variants(first: 1) {
                        nodes {
                            id
                            inventoryItem {
                                id
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Update a product (tags, status, etc.) using ProductUpdateInput.
     */
    public static function updateProduct(): string
    {
        return <<<'GRAPHQL'
        mutation updateProduct($product: ProductUpdateInput!) {
            productUpdate(product: $product) {
                product {
                    id
                    title
                    status
                    tags
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Update product status only — same payload as updateProduct, kept as a
     * separate method for readability at call sites that toggle ARCHIVED /
     * ACTIVE.
     */
    public static function updateProductStatus(): string
    {
        return self::updateProduct();
    }

    /**
     * Bulk-update variants. SKU lives on inventoryItem from API 2024-04
     * onwards; weight, requiresShipping and tracked all live on inventoryItem
     * too.
     */
    public static function bulkUpdateVariants(): string
    {
        return <<<'GRAPHQL'
        mutation productVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
            productVariantsBulkUpdate(productId: $productId, variants: $variants) {
                product {
                    id
                    variants(first: 100) {
                        nodes {
                            id
                            sku
                            price
                            compareAtPrice
                            barcode
                            inventoryPolicy
                            taxable
                            inventoryItem {
                                id
                                sku
                                tracked
                                requiresShipping
                                measurement {
                                    weight {
                                        value
                                        unit
                                    }
                                }
                            }
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * inventorySetQuantities — required @idempotent directive from API
     * 2026-04. The mutation expects a state name ("available" or "on_hand")
     * and either a compareQuantity per item or ignoreCompareQuantity:true at
     * the top level. We pass ignoreCompareQuantity because RetailEdge is the
     * source of truth — concurrent writes are not expected.
     */
    public static function setInventoryQuantities(): string
    {
        return <<<'GRAPHQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!, $idempotencyKey: String!) {
            inventorySetQuantities(input: $input) @idempotent(key: $idempotencyKey) {
                inventoryAdjustmentGroup {
                    id
                    reason
                    referenceDocumentUri
                    changes {
                        name
                        delta
                        quantityAfterChange
                    }
                }
                userErrors {
                    code
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Add media to a product. The mutation name productCreateMedia is
     * unchanged; the input type is CreateMediaInput.
     */
    public static function createProductMedia(): string
    {
        return <<<'GRAPHQL'
        mutation productCreateMedia($media: [CreateMediaInput!]!, $productId: ID!) {
            productCreateMedia(media: $media, productId: $productId) {
                media {
                    id
                    alt
                    mediaContentType
                    status
                    mediaErrors {
                        code
                        details
                        message
                    }
                }
                product {
                    id
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Append media references to a variant.
     */
    public static function updateVariantMedia(): string
    {
        return <<<'GRAPHQL'
        mutation productVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
            productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
                productVariants {
                    id
                    media(first: 10) {
                        nodes {
                            id
                        }
                    }
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Permanently delete a product. Used as a rollback when the upstream
     * create succeeded but the local DB write failed.
     */
    public static function deleteProduct(): string
    {
        return <<<'GRAPHQL'
        mutation productDelete($input: ProductDeleteInput!) {
            productDelete(input: $input) {
                deletedProductId
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }
}
