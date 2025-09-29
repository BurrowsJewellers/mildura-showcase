<?php

namespace App\Services\GraphQL;

class OrderQueries
{
    /**
     * Query to fetch orders with line items
     */
    public static function getOrders(): string
    {
        return <<<'GRAPHQL'
        query getOrders($first: Int!, $after: String, $query: String) {
            orders(first: $first, after: $after, query: $query) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                nodes {
                    id
                    name
                    createdAt
                    updatedAt
                    cancelledAt
                    displayFulfillmentStatus
                    tags
                    email
                    phone
                    totalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    subtotalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalShippingPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalTaxSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalDiscountsSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    shippingAddress {
                        firstName
                        lastName
                        address1
                        address2
                        city
                        province
                        zip
                        country
                        phone
                    }
                    customer {
                        id
                        firstName
                        lastName
                        email
                        phone
                    }
                    lineItems(first: 250) {
                        nodes {
                            id
                            name
                            sku
                            quantity
                            currentQuantity
                            fulfillableQuantity
                            variant {
                                id
                                sku
                                product {
                                    id
                                }
                            }
                            originalUnitPriceSet {
                                shopMoney {
                                    amount
                                    currencyCode
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
     * Query to fetch orders updated after a specific date
     */
    public static function getRecentOrders(): string
    {
        return <<<'GRAPHQL'
        query getRecentOrders($first: Int!, $after: String, $updatedAt: String!) {
            orders(first: $first, after: $after, query: $updatedAt) {
                pageInfo {
                    hasNextPage
                    endCursor
                }
                nodes {
                    id
                    name
                    createdAt
                    updatedAt
                    cancelledAt
                    displayFulfillmentStatus
                    tags
                    email
                    phone
                    totalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    subtotalPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalShippingPriceSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalTaxSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    totalDiscountsSet {
                        shopMoney {
                            amount
                            currencyCode
                        }
                    }
                    shippingAddress {
                        firstName
                        lastName
                        address1
                        address2
                        city
                        province
                        zip
                        country
                        phone
                    }
                    customer {
                        id
                        firstName
                        lastName
                        email
                        phone
                    }
                    lineItems(first: 250) {
                        nodes {
                            id
                            name
                            sku
                            quantity
                            currentQuantity
                            fulfillableQuantity
                            variant {
                                id
                                sku
                                product {
                                    id
                                }
                            }
                            originalUnitPriceSet {
                                shopMoney {
                                    amount
                                    currencyCode
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
     * Query to fetch a single order by ID
     */
    public static function getOrderById(): string
    {
        return <<<'GRAPHQL'
        query getOrder($id: ID!) {
            order(id: $id) {
                id
                name
                createdAt
                updatedAt
                cancelledAt
                displayFulfillmentStatus
                tags
                email
                phone
                totalPriceSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                subtotalPriceSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                totalShippingPriceSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                totalTaxSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                totalDiscountsSet {
                    shopMoney {
                        amount
                        currencyCode
                    }
                }
                shippingAddress {
                    firstName
                    lastName
                    address1
                    address2
                    city
                    province
                    zip
                    country
                    phone
                }
                customer {
                    id
                    firstName
                    lastName
                    email
                    phone
                }
                lineItems(first: 250) {
                    nodes {
                        id
                        name
                        sku
                        quantity
                        currentQuantity
                        fulfillableQuantity
                        variant {
                            id
                            sku
                            product {
                                id
                            }
                        }
                        originalUnitPriceSet {
                            shopMoney {
                                amount
                                currencyCode
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;
    }
}
