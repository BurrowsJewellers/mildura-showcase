<?php

namespace App\Services\GraphQL;

class ProductMutations
{
    /**
     * Mutation to create a product
     */
    public static function createProduct(): string
    {
        return <<<'GRAPHQL'
        mutation createProduct($input: ProductInput!) {
            productCreate(input: $input) {
                product {
                    id
                    title
                    handle
                    vendor
                    productType
                    status
                    tags
                    variants(first: 100) {
                        nodes {
                            id
                            sku
                            title
                            price
                            compareAtPrice
                            position
                            inventoryPolicy
                            fulfillmentService
                            inventoryManagement
                            selectedOptions {
                                name
                                value
                            }
                            taxable
                            barcode
                            weight
                            weightUnit
                            requiresShipping
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
     * Mutation to update a product
     */
    public static function updateProduct(): string
    {
        return <<<'GRAPHQL'
        mutation updateProduct($input: ProductInput!) {
            productUpdate(input: $input) {
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
     * Mutation to update product variants in bulk
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
     * Mutation to update inventory quantities
     */
    public static function setInventoryQuantities(): string
    {
        return <<<'GRAPHQL'
        mutation inventorySetQuantities($input: InventorySetQuantitiesInput!) {
            inventorySetQuantities(input: $input) {
                inventoryAdjustmentGroup {
                    id
                    reason
                    changes {
                        name
                        delta
                        quantityAfterChange
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
     * Mutation to create product media (images)
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
     * Mutation to update variant media
     */
    public static function updateVariantMedia(): string
    {
        return <<<'GRAPHQL'
        mutation productVariantAppendMedia($id: ID!, $mediaIds: [ID!]!) {
            productVariantAppendMedia(id: $id, mediaIds: $mediaIds) {
                productVariant {
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
     * Mutation to archive/activate a product
     */
    public static function updateProductStatus(): string
    {
        return <<<'GRAPHQL'
        mutation updateProductStatus($input: ProductInput!) {
            productUpdate(input: $input) {
                product {
                    id
                    title
                    status
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
     * Mutation to create a product variant
     */
    public static function createProductVariant(): string
    {
        return <<<'GRAPHQL'
        mutation productVariantCreate($input: ProductVariantInput!) {
            productVariantCreate(input: $input) {
                productVariant {
                    id
                    sku
                    price
                    compareAtPrice
                    barcode
                    inventoryPolicy
                    inventoryQuantity
                    taxable
                    weight
                    weightUnit
                }
                userErrors {
                    field
                    message
                }
            }
        }
        GRAPHQL;
    }
}
