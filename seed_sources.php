<?php

// Seed or update RSS sources. Run once (idempotent):
// php seed_sources.php

require_once __DIR__ . '/lib.php';

$pdo = db();

// Lean heavily on AGGREGATORS and COMMUNITY-CURATED sources.
// Traditional news site RSS is dying; aggregators are reliable.
$sources = [
  // ═══ COMMUNITY-CURATED (high signal, active, pre-filtered) ═══
  ['name' => 'Hacker News',              'feed_url' => 'https://hnrss.org/frontpage'],
  ['name' => 'Hacker News Best',         'feed_url' => 'https://hnrss.org/best'],
  ['name' => 'Hacker News Newest 50+',   'feed_url' => 'https://hnrss.org/newest?points=50'],
  ['name' => 'Lobsters',                 'feed_url' => 'https://lobste.rs/rss'],
  
  // ═══ REDDIT (very active, subreddit RSS) ═══
  ['name' => 'r/technology',             'feed_url' => 'https://www.reddit.com/r/technology/.rss'],
  ['name' => 'r/programming',            'feed_url' => 'https://www.reddit.com/r/programming/.rss'],
  ['name' => 'r/science',                'feed_url' => 'https://www.reddit.com/r/science/.rss'],
  ['name' => 'r/space',                  'feed_url' => 'https://www.reddit.com/r/space/.rss'],
  ['name' => 'r/games',                  'feed_url' => 'https://www.reddit.com/r/games/.rss'],
  ['name' => 'r/worldnews',              'feed_url' => 'https://www.reddit.com/r/worldnews/.rss'],
  ['name' => 'r/news',                   'feed_url' => 'https://www.reddit.com/r/news/.rss'],
  ['name' => 'r/Iowa',                   'feed_url' => 'https://www.reddit.com/r/Iowa/.rss'],
  ['name' => 'r/IowaCity',               'feed_url' => 'https://www.reddit.com/r/IowaCity/.rss'],
  
  // ═══ AGGREGATORS (they do the scraping) ═══
  ['name' => 'Techmeme',                 'feed_url' => 'https://www.techmeme.com/feed.xml'],
  ['name' => 'Memeorandum',              'feed_url' => 'https://www.memeorandum.com/feed.xml'],
  ['name' => 'Google News - Tech',       'feed_url' => 'https://news.google.com/rss/topics/CAAqKggKIiRDQkFTRlFvSUwyMHZNRGRqTVhZU0JXVnVMVWRDR2dKVlV5Z0FQAQ'],
  ['name' => 'Google News - Science',    'feed_url' => 'https://news.google.com/rss/topics/CAAqKggKIiRDQkFTRlFvSUwyMHZNRE5mZEdNU0JXVnVMVWRDR2dKVlV5Z0FQAQ'],
  ['name' => 'Google News - US',         'feed_url' => 'https://news.google.com/rss/topics/CAAqIggKIhxDQkFTRHdvSkwyMHZNRGxqTjNjd0VnSmxiaWdBUAE'],

  // ═══ TECH (actually active feeds) ═══
  ['name' => 'Ars Technica',             'feed_url' => 'https://feeds.arstechnica.com/arstechnica/index'],
  ['name' => 'The Verge',                'feed_url' => 'https://www.theverge.com/rss/index.xml'],
  ['name' => 'TechCrunch',               'feed_url' => 'https://techcrunch.com/feed/'],
  ['name' => 'Wired',                    'feed_url' => 'https://www.wired.com/feed/rss'],
  ['name' => 'MIT Tech Review',          'feed_url' => 'https://www.technologyreview.com/feed/'],

  // ═══ SPACE / SCIENCE ═══
  ['name' => 'NASA',                     'feed_url' => 'https://www.nasa.gov/news-release/feed/'],
  ['name' => 'SpaceNews',                'feed_url' => 'https://spacenews.com/feed/'],
  ['name' => 'Space.com',                'feed_url' => 'https://www.space.com/feeds/all'],
  ['name' => 'Ars Science',              'feed_url' => 'https://feeds.arstechnica.com/arstechnica/science'],

  // ═══ GENERAL NEWS (still somewhat active) ═══
  ['name' => 'NPR',                      'feed_url' => 'https://feeds.npr.org/1001/rss.xml'],
  ['name' => 'BBC World',                'feed_url' => 'https://feeds.bbci.co.uk/news/world/rss.xml'],
  ['name' => 'BBC Tech',                 'feed_url' => 'https://feeds.bbci.co.uk/news/technology/rss.xml'],

  // ═══ GAMING ═══
  ['name' => 'Ars Gaming',               'feed_url' => 'https://feeds.arstechnica.com/arstechnica/gaming'],
  ['name' => 'PC Gamer',                 'feed_url' => 'https://www.pcgamer.com/rss/'],
  ['name' => 'Polygon',                  'feed_url' => 'https://www.polygon.com/rss/index.xml'],
  
  // ═══ IOWA ═══
  ['name' => 'Iowa Capital Dispatch',    'feed_url' => 'https://iowacapitaldispatch.com/feed/'],
];

// Ensure there is a unique index on name or feed_url in your schema.
$stmt = $pdo->prepare("
  INSERT INTO sources (name, feed_url, enabled)
  VALUES (:name, :feed_url, 1)
  ON DUPLICATE KEY UPDATE
    feed_url = VALUES(feed_url),
    enabled = VALUES(enabled)
");

foreach ($sources as $src) {
  $stmt->execute([
    ':name' => $src['name'],
    ':feed_url' => $src['feed_url']
  ]);
  echo "Upserted source: {$src['name']}\n";
}

echo "Done.\n";



