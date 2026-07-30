<?php
/**
 * Plugin Name: Ma's House Visit / Contact Page
 * Description: Restores the [ma_visit_contact] shortcode used by the Visit / Contact page.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

function ma_visit_contact_shortcode(): string {
    $email = 'mashousestudio@gmail.com';
    $guide_image = 'https://www.mashouse.studio/wp-content/uploads/2022/12/Artboard-1.png';
    $map_url = 'https://maps.google.com/maps?q=Ma%27s%20House%2C%20159%20Old%20Point%20Road%2C%20Southampton%2C%20NY&t=m&z=12&output=embed&iwloc=near';

    ob_start();
    ?>
    <style id="ma-contact-page-css">
        body.page-id-40576 .nv-page-title-wrap{display:none!important}
        .ma-contact-page{max-width:1180px;margin:0 auto;padding:clamp(34px,6vw,64px) 20px;color:#111;font-family:Inter,Arial,sans-serif}
        .ma-contact-page *{box-sizing:border-box}
        .ma-contact-hero{display:grid;grid-template-columns:minmax(0,1fr) minmax(300px,390px);gap:clamp(24px,5vw,56px);align-items:start;margin-bottom:34px}
        .ma-contact-hero__eyebrow{margin:0 0 12px;color:#666;font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;font-weight:760}
        .ma-contact-page h1{margin:0;font-size:clamp(2rem,3.8vw,3rem);line-height:1.08;letter-spacing:0;font-weight:720}
        .ma-contact-hero__copy{max-width:650px;margin:18px 0 0;color:#333;font-size:1.08rem;line-height:1.62}
        .ma-contact-actions{display:flex;flex-wrap:wrap;gap:12px;margin-top:24px}
        .ma-contact-button{display:inline-flex;align-items:center;justify-content:center;min-height:46px;padding:13px 18px;background:#111;color:#fff!important;text-decoration:none!important;font-size:.95rem;font-weight:760;line-height:1}
        .ma-contact-button--secondary{background:#fff;color:#111!important;border:1px solid #111}
        .ma-contact-card{border:1px solid #d9d4ca;background:#fff;padding:22px;box-shadow:0 12px 32px rgba(20,20,20,.06)}
        .ma-contact-card h2{margin:0 0 14px;font-size:1.05rem;line-height:1.2;font-weight:760}
        .ma-contact-list{display:grid;gap:14px;margin:0;padding:0;list-style:none}
        .ma-contact-list li{display:grid;gap:3px}
        .ma-contact-list strong{font-size:.74rem;letter-spacing:.12em;text-transform:uppercase;color:#646464}
        .ma-contact-list span,.ma-contact-list a{color:#111!important;font-size:.98rem;line-height:1.4;text-decoration:none}
        .ma-contact-list a{text-decoration:underline;text-underline-offset:3px}
        .ma-contact-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-top:24px}
        .ma-contact-panel{border-top:1px solid #d9d4ca;padding-top:18px}
        .ma-contact-panel h2{margin:0 0 8px;font-size:1.05rem;line-height:1.25;font-weight:760}
        .ma-contact-panel p{margin:0;color:#333;font-size:.98rem;line-height:1.55}
        .ma-contact-guide{margin-top:28px}
        .ma-contact-guide h2{margin:0 0 12px;font-size:1.15rem;line-height:1.25;font-weight:760}
        .ma-contact-guide figure{margin:0;border:1px solid #d9d4ca;background:#fff;padding:12px}
        .ma-contact-guide img{display:block;width:100%;max-width:760px;height:auto;margin:0 auto}
        .ma-contact-guide figcaption{margin:10px 0 0;color:#555;font-size:.86rem;line-height:1.35;text-align:center}
        .ma-contact-map{margin-top:34px;border:1px solid #d9d4ca;background:#eee;overflow:hidden}
        .ma-contact-map iframe{display:block;width:100%;height:380px;border:0}
        @media(max-width:820px){
            .ma-contact-hero{grid-template-columns:1fr}
            .ma-contact-grid{grid-template-columns:1fr}
            .ma-contact-page{padding-top:32px}
            .ma-contact-map iframe{height:320px}
        }
    </style>
    <article class="ma-contact-page">
        <header class="ma-contact-hero">
            <div>
                <p class="ma-contact-hero__eyebrow">Visit / Contact</p>
                <h1>Plan a visit to Ma's House.</h1>
                <p class="ma-contact-hero__copy">Ma's House is open weekly for visitors and by appointment. For questions, visits outside open hours, group visits, program inquiries, or accessibility needs, please email us directly.</p>
                <div class="ma-contact-actions">
                    <a class="ma-contact-button" href="mailto:<?php echo esc_attr($email); ?>">Email Ma's House</a>
                    <a class="ma-contact-button ma-contact-button--secondary" href="tel:+16315660486">Call 631-566-0486</a>
                </div>
            </div>
            <aside class="ma-contact-card" aria-label="Contact details">
                <h2>Contact Details</h2>
                <ul class="ma-contact-list">
                    <li><strong>Email</strong><a href="mailto:<?php echo esc_attr($email); ?>">mashousestudio<span aria-hidden="true">&#8203;</span>@gmail.com</a></li>
                    <li><strong>Phone</strong><a href="tel:+16315660486">631-566-0486</a></li>
                    <li><strong>Visits</strong><span>Thursdays &amp; Sundays, 10 am - 5 pm weekly, or by appointment</span></li>
                    <li><strong>Location</strong><span>159 Old Point Road, Southampton, NY</span></li>
                </ul>
            </aside>
        </header>
        <section class="ma-contact-grid" aria-label="Visit information">
            <div class="ma-contact-panel">
                <h2>Arriving</h2>
                <p>Please use the West Gate entrance off Montauk Highway to enter the Shinnecock Nation. If you are coming for a specific event, check the event page for any additional arrival details.</p>
            </div>
            <div class="ma-contact-panel">
                <h2>Mailing Address</h2>
                <p>Ma's House<br>P.O. Box 2338<br>Southampton, NY 11969</p>
            </div>
        </section>
        <section class="ma-contact-guide" aria-label="Arrival guide">
            <h2>Arrival Guide</h2>
            <figure>
                <img src="<?php echo esc_url($guide_image); ?>" alt="Map and entrance guide for visiting Ma's House on the Shinnecock Indian Reservation" loading="lazy" decoding="async">
                <figcaption>Use the West Gate entrance off Montauk Highway when visiting Ma's House.</figcaption>
            </figure>
        </section>
        <div class="ma-contact-map" aria-label="Map to Ma's House">
            <iframe title="Map to Ma's House" src="<?php echo esc_url($map_url); ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
        </div>
    </article>
    <?php

    return (string) ob_get_clean();
}

add_shortcode('ma_visit_contact', 'ma_visit_contact_shortcode');
