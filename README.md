# Ma's House Airtable Artwork Woo Sync

Custom WordPress plugin for syncing available artworks from the Ma's House Airtable base into WooCommerce.

## Deployment

This plugin is intended to be managed from GitHub and deployed to SiteGround with GitHub Actions.

See [DEPLOYMENT.md](DEPLOYMENT.md) for the GitHub repository setup, required secrets, and rollback workflow.

Last deployment wiring check: 2026-05-20.

## Install

Upload `ma-artwork-airtable-woo-sync.zip` in WordPress, or copy the `ma-artwork-airtable-woo-sync` folder into `wp-content/plugins`.

After activation, open:

`Tools -> Ma Artwork Sync`

## Safe Setup Order

1. Save the Ma's House Airtable token.
2. Save the Airtable base ID for `Ma's House Stuff`.
3. Save the `Artwork Inventory` table ID or table name.
4. Click `Fetch Airtable fields`.
5. Review the discovered fields and adjust the field mapping if needed.
6. Click `Dry run one artwork`.
7. Only after the one-record dry run looks right, click `Sync all available artworks`.

## Safety Notes

- WooCommerce matching is by SKU only, using Airtable `Inventory Number`.
- The plugin intentionally does not fall back to old imported inventory fields or Jeremy Native helper fields.
- Synced products are marked with `_ma_artwork_airtable_*` metadata.
- Books, merch, events, donations, and other products are not changed unless their SKU exactly matches an Airtable `Inventory Number`.
- Images are matched by Airtable record, Airtable attachment, and SKU/inventory number. The plugin does not match images by similar title alone.

## Sync Behavior

- Creates or updates available artworks only.
- Product title is `Artwork Title, Year` when year exists, otherwise `Artwork Title`.
- Assigns parent WooCommerce category `Artwork`.
- Assigns a child category from Airtable photo series/artwork series when available.
- Removes `Uncategorized` whenever a real artwork category exists.
- Adds a `See this in scale` product tab when dimensions can be parsed.
- Adds `On View Now` above the catalog when active exhibit data exists.
- Adds `All Art` heading above the full catalog.
- Styles shop and product category archives as a clean 4:3 artwork grid.

## Manual Sync

The admin page shows a secret manual sync URL:

`/wp-json/ma-artwork-sync/v1/run?secret=...`

Useful query parameters:

- `dry_run=1`
- `max_records=1`
- `inventory_number=...`
- `force_all=1`

WordPress cron is scheduled every 5 minutes on activation.
