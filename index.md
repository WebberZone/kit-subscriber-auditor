---
title: Kit Subscriber Audit
description: A local-first PHP dashboard for auditing and deliberately cleaning Kit subscribers.
permalink: /
---

<section class="hero" id="overview">
  <div class="hero-inner">
    <p class="eyebrow"><span aria-hidden="true"></span>WebberZone · Open source</p>
    <h1>Clean your Kit list <em>with confidence.</em></h1>
    <p class="hero-sub">A self-hosted, review-first dashboard for auditing subscriber engagement, planning re-engagement, and cleaning a Kit.com list deliberately.</p>
    <div class="hero-ctas">
      <a class="button" href="{{ '/docs/getting-started/' | relative_url }}">Get started <span aria-hidden="true">›</span></a>
      <a class="button outline" href="https://github.com/WebberZone/kit-subscriber-auditor/releases/latest" target="_blank" rel="noreferrer">Latest release ↗</a>
    </div>
    <div class="hero-proof" aria-label="Project characteristics">
      <div class="hero-stat-grid">
        <div class="hero-stat"><strong>Local</strong><span>Operator-run</span></div>
        <div class="hero-stat"><strong>PHP 8.3+</strong><span>Framework-free</span></div>
        <div class="hero-stat"><strong>SQLite</strong><span>Local snapshot</span></div>
        <div class="hero-stat"><strong>Review first</strong><span>Explicit cleanup</span></div>
      </div>
    </div>
  </div>
</section>

<section class="section-band" id="why-it-exists">
  <div class="container">
    <div class="section-heading">
      <div>
        <p class="eyebrow"><span aria-hidden="true"></span>Why it exists</p>
        <h2 class="section-title">Review first. Act deliberately.</h2>
      </div>
      <p class="section-desc">The app downloads a local snapshot of your Kit subscribers and engagement stats. It never unsubscribes anyone during a sync.</p>
    </div>
    <div class="feature-grid">
      <article class="card"><div class="card-icon">01</div><h3>Local snapshot</h3><p>Subscriber data and job progress live in SQLite on your machine. Normal syncs skip fresh stats to keep repeat audits efficient.</p></article>
      <article class="card"><div class="card-icon">02</div><h3>Useful signals</h3><p>Filter by inactivity, opens, clicks, rates, subscription date, or the configurable removal-candidate rule.</p></article>
      <article class="card"><div class="card-icon">03</div><h3>Safe cleanup</h3><p>Preview, export, dry-run, revalidate, and then explicitly unsubscribe only the records you approve.</p></article>
    </div>
  </div>
</section>

<section class="section-band section-band-light" id="start-here">
  <div class="container split">
    <div><p class="eyebrow"><span aria-hidden="true"></span>Start here</p><h2 class="section-title">A private dashboard you control.</h2><p class="section-desc">It runs as a small PHP application with no framework and no frontend build step. Run it locally, on a private server, or anywhere else you control. Herd users can link the <code>public/</code> directory directly.</p></div>
    <div class="quick-links"><a href="{{ '/docs/getting-started/' | relative_url }}"><strong>Installation <span aria-hidden="true">→</span></strong><span>Requirements, Herd, API key, and first sync</span></a><a href="{{ '/docs/security/' | relative_url }}"><strong>Security model <span aria-hidden="true">→</span></strong><span>What is protected and what remains your responsibility</span></a></div>
  </div>
</section>

<section class="notice-wrap"><div class="container"><div class="callout"><strong>Important: you control the deployment.</strong><span>This is a self-hosted operator tool, not a shared multi-user service. If you host it beyond a trusted local environment, protect it with HTTPS, strong authentication, and network access controls.</span></div></div></section>
