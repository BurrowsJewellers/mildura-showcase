<?php

namespace App\Services\GraphQL;

class ProductQueries
{
    /**
     * Walk every product with the variants needed by GetProductsGraphQL.
     * From API 2024-04 onwards: sku, requiresShipping and weight all live on
     * inventoryItem; inventoryManagement and fulfillmentService have been
     * removed. inventoryQuantity is still on the variant directly.
     */
    public static function getProducts(): string
    {
        return <<<'GRAPHQL'
        query getProducts($first: Int!, $after: String) {
            products(first: $first, after: $after) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                nodes {
                    id
                    title
                    handle
                    vendor
                    productType
                    status
                    tags
                    createdAt
                    updatedAt
                    variants(first: 100) {
                        nodes {
                            id
                            title
                            price
                            compareAtPrice
                            position
                            inventoryPolicy
                            selectedOptions {
                                name
                                value
                            }
                            taxable
                            barcode
                            inventoryQuantity
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
                                inventoryLevels(first: 10) {
                                    nodes {
                                        id
                                        quantities(names: ["available"]) {
                                            name
                                            quantity
                                        }
                                        location {
                                            id
                                            name
                                        }
                                        updatedAt
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Lighter product walk used by the dedupe command.
     */
    public static function getProductsForDedupe(): string
    {
        return <<<'GRAPHQL'
        query getProductsForDedupe($first: Int!, $after: String) {
            products(first: $first, after: $after) {
                pageInfo { hasNextPage endCursor }
                nodes {
                    id
                    title
                    createdAt
                    status
                    totalInventory
                    media(first: 1) {
                        nodes { id }
                    }
                    variants(first: 100) {
                        nodes {
                            id
                            inventoryItem {
                                sku
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Find a single variant (and its parent product) by exact SKU.
     */
    public static function findVariantBySku(): string
    {
        return <<<'GRAPHQL'
        query findVariantBySku($query: String!) {
            productVariants(first: 5, query: $query) {
                nodes {
                    id
                    title
                    price
                    compareAtPrice
                    barcode
                    taxable
                    inventoryPolicy
                    sku
                    inventoryItem {
                        id
                        sku
                        tracked
                        requiresShipping
                    }
                    product {
                        id
                        title
                        handle
                        vendor
                        productType
                        status
                        tags
                    }
                }
            }
        }
        GRAPHQL;
    }

    public static function getLocations(): string
    {
        return <<<'GRAPHQL'
        query getLocations($first: Int!) {
            locations(first: $first) {
                nodes {
                    id
                    name
                    address {
                        address1
                        address2
                        city
                        zip
                        province
                        country
                        phone
                        countryCode
                        provinceCode
                    }
                    isActive
                    fulfillsOnlineOrders
                }
            }
        }
        GRAPHQL;
    }
}
