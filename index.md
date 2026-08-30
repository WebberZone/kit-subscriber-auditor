---
title: Kit Subscriber Audit
description: A local-first PHP dashboard for auditing and deliberately cleaning Kit subscribers.
permalink: /
---

<div class="hero">
  <p class="eyebrow">Free · Open source · Local first</p>
  <h1>Know who is still reading.</h1>
  <p class="lead">Kit Subscriber Audit gives you a private, review-first dashboard for finding inactive subscribers, running a re-engagement campaign, and cleaning your list deliberately.</p>
  <div class="button-row">
    <a class="button" href="{{ '/docs/getting-started/' | relative_url }}">Get started</a>
    <a class="button secondary" href="https://github.com/WebberZone/kit-subscriber-auditor/releases/latest" target="_blank" rel="noreferrer">Latest release ↗</a>
  </div>
</div>

<section>
  <p class="eyebrow">Why it exists</p>
  <h2>Review first. Act deliberately.</h2>
  <p class="section-lead">The app downloads a local snapshot of your Kit subscribers and engagement stats. It never unsubscribes anyone during a sync, and live cleanup requires an explicit review, export confirmation, and typed confirmation.</p>
  <div class="feature-grid">
    <article class="card"><h3>Local snapshot</h3><p>Subscriber data and job progress live in SQLite on your machine. Normal syncs skip fresh stats to keep repeat audits efficient.</p></article>
    <article class="card"><h3>Useful signals</h3><p>Filter by inactivity, opens, clicks, sends, rates, subscription date, or the configurable removal-candidate rule.</p></article>
    <article class="card"><h3>Safe cleanup</h3><p>Preview, export, dry-run, revalidate, and then explicitly unsubscribe only the records you approve.</p></article>
  </div>
</section>

<section class="split">
  <div><p class="eyebrow">Start here</p><h2>Five minutes to a private dashboard.</h2><p>It runs as a small PHP application with no framework and no frontend build step. Herd users can link the <code>public/</code> directory directly.</p></div>
  <div class="quick-links"><a href="{{ '/docs/getting-started/' | relative_url }}"><strong>Installation</strong><span>Requirements, Herd, API key, and first sync →</span></a><a href="{{ '/docs/security/' | relative_url }}"><strong>Security model</strong><span>What is protected and what remains your responsibility →</span></a><a href="{{ '/docs/releasing/' | relative_url }}"><strong>Releasing</strong><span>Build a clean versioned ZIP and publish it on GitHub →</span></a></div>
</section>

<section class="callout"><strong>Important:</strong> this is a local operator tool, not a hosted multi-user service. Do not expose it directly to the public internet.</section>
