<?php

require_once __DIR__ . '/lib.php';

// cron_ingest.php
// Fetch enabled RSS sources, insert new items, best-effort fetch and extract text, skip paywalls

$pdo = db();

$sources = $pdo->query("SELECT id, name, feed_url FROM sources WHERE enabled = 1")->fetchAll();
if (count($sources) === 0) {
  echo "No enabled sources\n";
  exit(0);
}

$insItem = $pdo->prepare("
  INSERT INTO items (
    source_id, title, url, published_at, excerpt, raw_text, paywalled, url_hash, created_at
  ) VALUES (
    :source_id, :title, :url, :published_at, :excerpt, :raw_text, :paywalled, :url_hash, :created_at
  )
");

foreach ($sources as $src) {
  $feedUrl = (string)$src['feed_url'];
  $resp = fetch_url($feedUrl, 25);

  if (!$resp['ok']) {
    echo "Feed fetch failed: {$src['name']} ({$feedUrl}) HTTP {$resp['code']}\n";
    continue;
  }

  $rssItems = parse_rss_items($resp['body']);
  if (count($rssItems) === 0) {
    echo "No items parsed: {$src['name']}\n";
    continue;
  }

  foreach ($rssItems as $it) {
    $title = $it['title'] ?: '';
    $link = $it['link'] ?: '';
    if ($title === '' || $link === '') continue;

    $url = canonicalize_url($link);
    $urlHash = md5_bin16($url);

    // Parse published date (best effort)
    $publishedAt = null;
    if ($it['pubDate'] !== '') {
      $ts = strtotime($it['pubDate']);
      if ($ts !== false) $publishedAt = gmdate('Y-m-d H:i:s', $ts);
    }

    // Excerpt: strip tags from RSS description and shorten
    $excerpt = trim(html_entity_decode(strip_tags($it['description'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (mb_strlen($excerpt) > 800) $excerpt = mb_substr($excerpt, 0, 800);

    // Try to insert first (dedupe by unique key)
    try {
      $insItem->execute([
        ':source_id' => (int)$src['id'],
        ':title' => $title,
        ':url' => $url,
        ':published_at' => $publishedAt,
        ':excerpt' => $excerpt !== '' ? $excerpt : null,
        ':raw_text' => null,
        ':paywalled' => 0,
        ':url_hash' => $urlHash,
        ':created_at' => now_utc()
      ]);
      $itemId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
      // Duplicate, skip
      continue;
    }

    // Fetch article HTML and extract text
    $page = fetch_url($url, 25);
    if (!$page['ok']) {
      // Leave raw_text null, still allow classification on title+excerpt later
      continue;
    }

    $text = html_to_text_best_effort($page['body']);
    $paywalled = looks_paywalled($text, (int)$page['code']) ? 1 : 0;

    $upd = $pdo->prepare("UPDATE items SET raw_text = :raw_text, paywalled = :paywalled WHERE id = :id");
    $upd->execute([
      ':raw_text' => $paywalled ? null : $text,
      ':paywalled' => $paywalled,
      ':id' => $itemId
    ]);
  }

  echo "Ingested: {$src['name']}\n";
}

echo "Done\n";
