<?php
/**
 * Plugin Name: Ma's House Collection Page
 * Description: Restores the [ma_collection_artworks] shortcode used by the public collection page.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

function ma_collection_artwork_products(): array {
    if (!function_exists('wc_get_products')) {
        return [];
    }

    $products = wc_get_products([
        'status'  => 'publish',
        'limit'   => -1,
        'return'  => 'objects',
        'orderby' => 'date',
        'order'   => 'DESC',
    ]);

    return array_values(array_filter($products, static function ($product): bool {
        if (!$product instanceof WC_Product || $product->get_catalog_visibility() === 'hidden' || !$product->get_image_id()) {
            return false;
        }

        $availability = strtolower(trim((string) get_post_meta($product->get_id(), 'ma_artwork_availability', true)));
        if ($availability === "ma's house collection") {
            return true;
        }

        $slugs = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'slugs']);
        return is_array($slugs) && in_array('mas-house-collection', $slugs, true);
    }));
}

function ma_collection_detail_rows(WC_Product $product): array {
    $product_id = $product->get_id();
    $artist = trim((string) get_post_meta($product_id, 'ma_artist_name', true));
    if (!$artist) {
        $artists = wp_get_post_terms($product_id, 'pa_artist', ['fields' => 'names']);
        $artist = !is_wp_error($artists) && $artists ? trim((string) $artists[0]) : '';
    }

    $series = trim((string) get_post_meta($product_id, 'ma_artwork_series', true));
    $medium = trim((string) get_post_meta($product_id, 'material', true));
    $dimensions = trim((string) get_post_meta($product_id, 'dimensions', true));
    $year = trim((string) get_post_meta($product_id, 'year', true));
    $sku = trim((string) $product->get_sku());

    return array_values(array_filter([
        $artist ? ['label' => 'Artist', 'value' => $artist] : null,
        $year ? ['label' => 'Year', 'value' => $year] : null,
        $medium ? ['label' => 'Medium', 'value' => $medium] : null,
        $dimensions ? ['label' => 'Size', 'value' => $dimensions] : null,
        $series ? ['label' => 'Series', 'value' => $series] : null,
        $sku ? ['label' => 'Inventory', 'value' => $sku] : null,
    ]));
}

function ma_collection_artworks_shortcode(): string {
    $products = ma_collection_artwork_products();

    ob_start();
    ?>
    <section class="ma-collection-page">
        <header class="ma-collection-page__header">
            <h1>Ma&rsquo;s House Collection</h1>
            <div>
                <p>Artworks in the Ma&rsquo;s House Collection come through gifts from artists, gifts from collectors, and loans from artists or collectors who want the work cared for, documented, and shared in relationship with Ma&rsquo;s House. These works are part of the house&rsquo;s living archive and are not listed for purchase in the store.</p>
            </div>
        </header>
        <?php if ($products) : ?>
            <div class="ma-collection-page__grid">
                <?php foreach ($products as $product) : ?>
                    <?php
                    $image_id = $product->get_image_id();
                    $image = $image_id ? wp_get_attachment_image($image_id, 'large', false, ['loading' => 'lazy']) : '';
                    $rows = ma_collection_detail_rows($product);
                    ?>
                    <article class="ma-collection-card">
                        <a class="ma-collection-card__image" href="<?php echo esc_url(get_permalink($product->get_id())); ?>">
                            <?php echo $image ?: '<span aria-hidden="true"></span>'; ?>
                        </a>
                        <div class="ma-collection-card__body">
                            <p class="ma-collection-card__status">Ma&rsquo;s House Collection</p>
                            <h2><a href="<?php echo esc_url(get_permalink($product->get_id())); ?>"><?php echo esc_html($product->get_name()); ?></a></h2>
                            <?php if ($rows) : ?>
                                <dl>
                                    <?php foreach ($rows as $row) : ?>
                                        <div>
                                            <dt><?php echo esc_html($row['label']); ?></dt>
                                            <dd><?php echo esc_html($row['value']); ?></dd>
                                        </div>
                                    <?php endforeach; ?>
                                </dl>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <p class="ma-collection-page__empty">No Ma&rsquo;s House Collection artworks are currently published.</p>
        <?php endif; ?>
    </section>
    <style id="ma-collection-page-css">
        body.page-id-42601 .nv-page-title-wrap,body.page-id-42601 .entry-title,body.page-id-42601 h1.entry-title,body.page-id-42601 .page-title,body:has(.ma-collection-page) .nv-page-title-wrap,body:has(.ma-collection-page) .entry-title{display:none!important}
        .ma-collection-page{width:min(1280px,calc(100% - 48px));margin:74px auto 90px;color:#111;font-family:Inter,Arial,sans-serif}
        .ma-collection-page__header{display:grid;grid-template-columns:minmax(0,1fr) minmax(280px,560px);gap:28px;align-items:end;margin:0 0 34px;padding:0 0 28px;border-bottom:1px solid #dedbd4}
        .ma-collection-page__header h1{margin:0;color:#111;font-size:clamp(44px,6vw,82px);line-height:.95;font-weight:560;letter-spacing:0}
        .ma-collection-page__header div p{margin:0;color:#333;font-size:15px;line-height:1.55}
        .ma-collection-page__grid{columns:4 240px;column-gap:34px}
        .ma-collection-card{break-inside:avoid;margin:0 0 42px;padding:0 0 24px;border-bottom:1px solid #e2ded6}
        .ma-collection-card__image{display:block;width:100%;margin:0 0 14px;background:#f6f4ef;text-decoration:none}
        .ma-collection-card__image img{display:block;width:100%;height:auto;object-fit:contain;background:#f6f4ef}
        .ma-collection-card__image span{display:block;aspect-ratio:4/3;background:#f6f4ef}
        .ma-collection-card__status{margin:0 0 8px;color:#8f1711;font-size:10px;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
        .ma-collection-card h2{margin:0 0 10px;color:#111;font-size:18px;line-height:1.22;font-weight:650;letter-spacing:0}
        .ma-collection-card h2 a{color:inherit;text-decoration:none}
        .ma-collection-card h2 a:hover,.ma-collection-card h2 a:focus{text-decoration:underline;text-underline-offset:3px}
        .ma-collection-card dl{display:grid;gap:5px;margin:0;color:#555;font-size:12px;line-height:1.35}
        .ma-collection-card dl div{display:grid;grid-template-columns:92px minmax(0,1fr);gap:8px}
        .ma-collection-card dt{margin:0;color:#777;font-weight:760;letter-spacing:.08em;text-transform:uppercase}
        .ma-collection-card dd{margin:0}
        .ma-collection-page__empty{margin:0;color:#555;font-size:15px}
        @media(max-width:760px){
            .ma-collection-page{width:calc(100% - 32px);margin-top:46px}
            .ma-collection-page__header{display:block}
            .ma-collection-page__header h1{margin-bottom:14px}
            .ma-collection-page__grid{columns:1}
            .ma-collection-card dl div{grid-template-columns:82px minmax(0,1fr)}
        }
    </style>
    <?php

    return (string) ob_get_clean();
}

add_shortcode('ma_collection_artworks', 'ma_collection_artworks_shortcode');
