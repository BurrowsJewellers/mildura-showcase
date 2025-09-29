<?php

namespace App\Services\GraphQL;

class ProductQueries
{
    /**
     * Query to fetch products with variants and inventory
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
                                inventoryLevels(first: 10) {
                                    nodes {
                                        id
                                        available
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
     * Query to fetch a single product by ID
     */
    public static function getProductById(): string
    {
        return <<<'GRAPHQL'
        query getProduct($id: ID!) {
            product(id: $id) {
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
        }
        GRAPHQL;
    }

    /**
     * Query to search products by SKU
     */
    public static function searchProductsBySku(): string
    {
        return <<<'GRAPHQL'
        query searchProductsBySku($query: String!, $first: Int!) {
            products(first: $first, query: $query) {
                nodes {
                    id
                    title
                    variants(first: 100) {
                        nodes {
                            id
                            sku
                            inventoryItem {
                                id
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;
    }

    /**
     * Query to fetch locations
     */
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

    /**
     * Query to fetch inventory levels for a location
     */
    public static function getInventoryLevels(): string
    {
        return <<<'GRAPHQL'
        query getInventoryLevels($locationId: ID!, $first: Int!, $after: String) {
            location(id: $locationId) {
                id
                inventoryLevels(first: $first, after: $after) {
                    pageInfo {
                        hasNextPage
                        endCursor
                    }
                    nodes {
                        id
                        available
                        item {
                            id
                            sku
                            variant {
                                id
                                sku
                                product {
                                    id
                                }
                            }
                        }
                        updatedAt
                    }
                }
            }
        }
        GRAPHQL;
    }
}
