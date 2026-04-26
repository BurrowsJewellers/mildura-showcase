# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a Laravel-based integration system for Burrows Jewellers that synchronizes data between their RetailEdge/EWeb POS system and Shopify e-commerce platform. The system acts as middleware ensuring data consistency across both platforms.

## Architecture

The application follows a service-oriented architecture with three main components:

1. **Data Models** organized by platform:
   - `app/Models/EWeb/` - RetailEdge/EWeb models
   - `app/Models/Shopify/` - Shopify models

2. **Services** for external API integrations:
   - `app/Services/` - Business logic for API communication

3. **Console Commands** for automation:
   - `app/Console/Commands/EWeb/` - RetailEdge data synchronization
   - `app/Console/Commands/Shopify/` - Shopify operations

## Common Commands

### Development
```bash
# Start local development server
php artisan serve

# Run frontend development server (Vite)
npm run dev

# Build frontend assets
npm run build

# Run database migrations
php artisan migrate

# Clear all caches
php artisan optimize:clear

# Cache configuration for production
php artisan optimize
```

### Testing
```bash
# Run all tests
php artisan test

# Run tests in parallel
php artisan test --parallel

# Run tests with coverage
php artisan test --coverage

# Run a specific test file
php artisan test tests/Feature/ExampleTest.php
```

### Code Quality
```bash
# Format code with Laravel Pint
./vendor/bin/pint

# Format specific file or directory
./vendor/bin/pint app/Models
```

### Data Synchronization Commands
```bash
# RetailEdge/EWeb Commands
php artisan getBrandsFromEWeb        # Sync brands from EWeb
php artisan getProductsFromEWeb      # Sync products from EWeb

# Shopify Commands (GraphQL only — REST commands have been retired)
php artisan shopify:get-products-graphql     # Get products from Shopify
php artisan shopify:create-product-graphql   # Create products in Shopify
php artisan shopify:update-product-graphql   # Update product tags
php artisan shopify:update-inventory-graphql # Update inventory levels
php artisan shopify:update-price-graphql     # Update product prices
php artisan shopify:upload-images-graphql    # Upload product images
php artisan shopify:get-orders-graphql       # Retrieve orders from Shopify
php artisan shopify:archive-products-graphql # Archive out-of-stock products
php artisan shopify:get-webhooks-graphql     # List configured webhook subscriptions
php artisan shopify:dedupe-products          # Find/archive duplicate-SKU products (use --apply)
```

### Scheduled Tasks
```bash
# Run scheduler locally (for testing scheduled tasks)
php artisan schedule:work

# List scheduled tasks
php artisan schedule:list
```

## Key Integrations

### Shopify Integration
- Configuration: `config/shopify.php`
- Uses `shopify/shopify-api` package (`^6.1.1`)
- Admin GraphQL API version is pinned in `app/Services/ShopifyConnectionService.php` (currently `2026-04`); bump intentionally per quarterly release
- Handles products, inventory, orders, and webhooks via GraphQL only — REST resource shims are no longer used
- GraphQL service: `app/Services/ShopifyGraphQLService.php`
- Query/mutation builders: `app/Services/GraphQL/`
- Job locking: `app/Services/SyncJobService.php::claim()` provides a transactional, atomic lock so two scheduled invocations of the same command can't double-process rows

### RetailEdge/EWeb Integration
- Configuration: `config/marketplace.php`
- Custom API integration for POS system
- Manages product data and images from retail system

## Database

The application uses MySQL (see `.env` `DB_CONNECTION=mysql`). The `database.php` default falls back to `sqlite` when `DB_CONNECTION` is unset, but every migration in this repo (notably the dedupe migration that uses `DELETE … JOIN`) is written for MySQL. Key tables include:
- Shopify: `products`, `product_variants`, `inventory_levels`, `orders`
- RetailEdge: `retail_edge_products`, `retail_edge_product_images`
- Synchronization: `sync_jobs`, `brands`

## Scheduled Tasks

The system runs automated synchronization tasks defined in `routes/console.php`:
- **Daily**: Brand synchronization
- **Every 15 minutes**: Orders and product updates from EWeb
- **Every 3 hours**: Image uploads to Shopify
- **Every 4 hours**: Product sync from Shopify

## Environment Configuration

Required environment variables include Shopify API credentials and RetailEdge/EWeb connection settings. Copy `.env.example` to `.env` and configure appropriately.