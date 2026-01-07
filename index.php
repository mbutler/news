<?php

require_once __DIR__ . '/lib.php';

$pdo = db();

// Handle mark-as-read AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
  header('Content-Type: application/json');
  
  if ($_POST['action'] === 'mark_read' && isset($_POST['item_id'])) {
    $stmt = $pdo->prepare("INSERT IGNORE INTO `reads` (item_id) VALUES (:id)");
    $stmt->execute([':id' => (int)$_POST['item_id']]);
    echo json_encode(['ok' => true]);
    exit;
  }
  
  if ($_POST['action'] === 'mark_all_read') {
    $pdo->exec("
      INSERT IGNORE INTO `reads` (item_id)
      SELECT i.id FROM items i
      JOIN scores s ON s.item_id = i.id
      WHERE s.should_read = 1
    ");
    echo json_encode(['ok' => true]);
    exit;
  }
  
  echo json_encode(['ok' => false]);
  exit;
}

$show = $_GET['show'] ?? 'unread'; // unread | all | borderline
$limit = (int)($_GET['limit'] ?? 100);
if ($limit < 10) $limit = 10;
if ($limit > 500) $limit = 500;

// Build WHERE clause
$where = "s.should_read = 1";
if ($show === 'unread') {
  $where = "s.should_read = 1 AND r.item_id IS NULL";
} elseif ($show === 'borderline') {
  $where = "s.should_read = 0";
} elseif ($show === 'all') {
  $where = "s.should_read = 1";
}

// Query with clustering support
// We'll fetch all matching items, then cluster in PHP for flexibility
$q = $pdo->prepare("
  SELECT
    i.id, i.title, i.title_neutral, i.snippet_neutral, i.url, i.published_at, i.created_at,
    s.relevance, s.ragebait, s.culture_war, s.tone, s.calm_reason,
    s.challenge_value, s.perspective, s.cluster_key, s.novelty,
    src.name AS source_name,
    r.read_at
  FROM items i
  JOIN sources src ON src.id = i.source_id
  JOIN scores s ON s.item_id = i.id
  LEFT JOIN `reads` r ON r.item_id = i.id
  WHERE {$where}
    AND i.created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
    AND COALESCE(i.published_at, i.created_at) >= (UTC_TIMESTAMP() - INTERVAL 14 DAY)
  ORDER BY
    COALESCE(i.published_at, i.created_at) DESC,
    s.relevance DESC
  LIMIT :limit
");
$q->bindValue(':limit', $limit, PDO::PARAM_INT);
$q->execute();
$rows = $q->fetchAll();

// Cluster stories by cluster_key
// For each cluster, pick the best item — prioritize trusted sources (HN, Lobsters)
// and collect alternate sources
$trustedSources = ['Hacker News', 'Hacker News Best', 'Hacker News Newest 50+', 'Lobsters'];
$clusters = [];
foreach ($rows as $r) {
  $key = $r['cluster_key'] ?: 'other/misc';
  $isTrusted = in_array($r['source_name'], $trustedSources);
  
  if (!isset($clusters[$key])) {
    $clusters[$key] = [
      'primary' => $r,
      'sources' => [['name' => $r['source_name'], 'url' => $r['url'], 'id' => $r['id']]]
    ];
  } else {
    // Add as alternate source
    $clusters[$key]['sources'][] = ['name' => $r['source_name'], 'url' => $r['url'], 'id' => $r['id']];
    
    // Replace primary if this one is from a trusted source, or better relevance/recency
    $cur = $clusters[$key]['primary'];
    $curIsTrusted = in_array($cur['source_name'], $trustedSources);
    
    $shouldReplace = false;
    if ($isTrusted && !$curIsTrusted) {
      // Trusted source always wins over non-trusted
      $shouldReplace = true;
    } elseif ($isTrusted == $curIsTrusted) {
      // Same trust level: compare relevance then recency
      if ($r['relevance'] > $cur['relevance'] || 
          ($r['relevance'] == $cur['relevance'] && strtotime($r['published_at'] ?? $r['created_at']) > strtotime($cur['published_at'] ?? $cur['created_at']))) {
        $shouldReplace = true;
      }
    }
    
    if ($shouldReplace) {
      $clusters[$key]['primary'] = $r;
    }
  }
}

// Sort clusters by primary item's publish date (newest first)
uasort($clusters, function($a, $b) {
  $ta = strtotime($a['primary']['published_at'] ?? $a['primary']['created_at']);
  $tb = strtotime($b['primary']['published_at'] ?? $b['primary']['created_at']);
  return $tb - $ta;
});

// DIVERSITY: Limit items per source (max 3 from any single source)
$maxPerSource = 3;
$sourceCounts = [];
$diverseClusters = [];
foreach ($clusters as $key => $cluster) {
  $src = $cluster['primary']['source_name'];
  $sourceCounts[$src] = ($sourceCounts[$src] ?? 0) + 1;
  if ($sourceCounts[$src] <= $maxPerSource) {
    $diverseClusters[$key] = $cluster;
  }
}
$clusters = $diverseClusters;

// Group by time period
$now = time();
$today = strtotime('today midnight');
$yesterday = strtotime('yesterday midnight');
$groups = [
  'today' => [],
  'yesterday' => [],
  'earlier' => []
];

foreach ($clusters as $key => $cluster) {
  $t = strtotime($cluster['primary']['published_at'] ?? $cluster['primary']['created_at']);
  if ($t >= $today) {
    $groups['today'][] = $cluster;
  } elseif ($t >= $yesterday) {
    $groups['yesterday'][] = $cluster;
  } else {
    $groups['earlier'][] = $cluster;
  }
}

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function timeAgo(string $datetime): string {
  $t = strtotime($datetime);
  $diff = time() - $t;
  
  if ($diff < 60) return 'just now';
  if ($diff < 3600) return floor($diff / 60) . 'm ago';
  if ($diff < 86400) return floor($diff / 3600) . 'h ago';
  if ($diff < 172800) return 'yesterday';
  return floor($diff / 86400) . 'd ago';
}

$totalUnread = 0;
foreach ($clusters as $c) {
  if (!$c['primary']['read_at']) $totalUnread++;
}

?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>News</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --font-serif: 'Newsreader', Georgia, serif;
      --font-sans: 'DM Sans', system-ui, sans-serif;
    }
    
    [data-theme="dark"] {
      --bg: #0f0f0f;
      --bg-card: #1a1a1a;
      --bg-card-hover: #222;
      --bg-card-read: #141414;
      --text: #e8e6e3;
      --text-dim: #888;
      --text-dimmer: #555;
      --accent: #7eb8da;
      --accent-dim: #4a7a99;
      --border: #2a2a2a;
      --tag-bg: #252525;
    }
    
    [data-theme="light"] {
      --bg: #faf9f7;
      --bg-card: #fff;
      --bg-card-hover: #f5f4f2;
      --bg-card-read: #f0efed;
      --text: #1a1a1a;
      --text-dim: #666;
      --text-dimmer: #999;
      --accent: #2563eb;
      --accent-dim: #6b8cce;
      --border: #e5e4e2;
      --tag-bg: #f0efed;
    }
    
    * { box-sizing: border-box; }
    
    body {
      font-family: var(--font-sans);
      background: var(--bg);
      color: var(--text);
      margin: 0;
      padding: 0;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }
    
    .container {
      max-width: 720px;
      margin: 0 auto;
      padding: 24px 20px 80px;
    }
    
    header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 32px;
      padding-bottom: 20px;
      border-bottom: 1px solid var(--border);
    }
    
    .logo {
      font-family: var(--font-serif);
      font-size: 28px;
      font-weight: 500;
      letter-spacing: -0.5px;
    }
    
    .header-actions {
      display: flex;
      gap: 12px;
      align-items: center;
    }
    
    .theme-toggle {
      background: none;
      border: none;
      color: var(--text-dim);
      cursor: pointer;
      padding: 8px;
      border-radius: 8px;
      font-size: 18px;
    }
    .theme-toggle:hover { color: var(--text); }
    
    nav {
      display: flex;
      gap: 6px;
      margin-bottom: 28px;
    }
    
    .nav-pill {
      padding: 8px 16px;
      border-radius: 20px;
      text-decoration: none;
      color: var(--text-dim);
      font-size: 14px;
      font-weight: 500;
      background: transparent;
      border: 1px solid transparent;
      transition: all 0.15s ease;
    }
    .nav-pill:hover {
      color: var(--text);
      background: var(--bg-card);
    }
    .nav-pill.active {
      color: var(--text);
      background: var(--bg-card);
      border-color: var(--border);
    }
    
    .nav-pill .count {
      background: var(--accent);
      color: var(--bg);
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 10px;
      margin-left: 6px;
    }
    
    .section-label {
      font-size: 12px;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: var(--text-dimmer);
      margin: 32px 0 16px;
      padding-left: 4px;
    }
    .section-label:first-of-type { margin-top: 0; }
    
    .story {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 20px;
      margin-bottom: 12px;
      transition: all 0.15s ease;
      cursor: pointer;
    }
    .story:hover {
      background: var(--bg-card-hover);
      border-color: var(--text-dimmer);
    }
    .story.read {
      background: var(--bg-card-read);
      opacity: 0.7;
    }
    .story.read:hover { opacity: 1; }
    
    .story-meta {
      display: flex;
      align-items: center;
      gap: 12px;
      font-size: 13px;
      color: var(--text-dim);
      margin-bottom: 10px;
    }
    
    .source {
      font-weight: 500;
      color: var(--accent);
    }
    
    .time { color: var(--text-dimmer); }
    
    .story-title {
      font-family: var(--font-serif);
      font-size: 20px;
      font-weight: 400;
      line-height: 1.35;
      margin: 0 0 8px;
      color: var(--text);
    }
    .story-title a {
      color: inherit;
      text-decoration: none;
    }
    .story-title a:hover { text-decoration: underline; }
    
    .story-snippet {
      font-size: 15px;
      color: var(--text-dim);
      line-height: 1.5;
      margin: 0;
    }
    
    .story-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 14px;
      padding-top: 12px;
      border-top: 1px solid var(--border);
    }
    
    .tags {
      display: flex;
      gap: 6px;
      flex-wrap: wrap;
    }
    
    .tag {
      font-size: 11px;
      padding: 4px 8px;
      border-radius: 6px;
      background: var(--tag-bg);
      color: var(--text-dim);
    }
    .tag.cluster {
      color: var(--accent-dim);
      font-weight: 500;
    }
    
    .alt-sources {
      font-size: 12px;
      color: var(--text-dimmer);
    }
    .alt-sources a {
      color: var(--text-dim);
      text-decoration: none;
    }
    .alt-sources a:hover {
      color: var(--accent);
    }
    
    .empty {
      text-align: center;
      padding: 60px 20px;
      color: var(--text-dim);
    }
    .empty-icon {
      font-size: 48px;
      margin-bottom: 16px;
      opacity: 0.5;
    }
    
    .mark-all-read {
      background: var(--bg-card);
      border: 1px solid var(--border);
      color: var(--text-dim);
      padding: 8px 14px;
      border-radius: 8px;
      font-size: 13px;
      cursor: pointer;
      transition: all 0.15s ease;
    }
    .mark-all-read:hover {
      background: var(--bg-card-hover);
      color: var(--text);
    }
    
    .prefs-link {
      color: var(--text-dim);
      text-decoration: none;
      font-size: 18px;
      padding: 8px;
      border-radius: 8px;
    }
    .prefs-link:hover { color: var(--text); }
    
    @media (max-width: 600px) {
      .container { padding: 16px 16px 60px; }
      .story-title { font-size: 18px; }
      header { flex-wrap: wrap; gap: 12px; }
    }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div class="logo">News</div>
      <div class="header-actions">
        <?php if ($show === 'unread' && $totalUnread > 0): ?>
          <button class="mark-all-read" onclick="markAllRead()">Mark all read</button>
        <?php endif; ?>
        <a href="prefs.php" class="prefs-link" title="Preferences">⚙</a>
        <button class="theme-toggle" onclick="toggleTheme()" title="Toggle theme">◐</button>
      </div>
    </header>
    
    <nav>
      <a class="nav-pill <?= $show === 'unread' ? 'active' : '' ?>" href="?show=unread">
        Unread<?php if ($totalUnread > 0): ?><span class="count"><?= $totalUnread ?></span><?php endif; ?>
      </a>
      <a class="nav-pill <?= $show === 'all' ? 'active' : '' ?>" href="?show=all">All</a>
      <a class="nav-pill <?= $show === 'borderline' ? 'active' : '' ?>" href="?show=borderline">Borderline</a>
    </nav>
    
    <?php if (empty($clusters)): ?>
      <div class="empty">
        <div class="empty-icon">📭</div>
        <p>Nothing here yet.<br>Check back after the next feed refresh.</p>
      </div>
    <?php else: ?>
      <?php foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'earlier' => 'Older'] as $groupKey => $groupLabel): ?>
        <?php if (!empty($groups[$groupKey])): ?>
          <div class="section-label"><?= $groupLabel ?></div>
          <?php foreach ($groups[$groupKey] as $cluster): ?>
            <?php
              $r = $cluster['primary'];
              $title = $r['title_neutral'] ?: $r['title'];
              $snippet = $r['snippet_neutral'] ?: '';
              $when = $r['published_at'] ?: $r['created_at'];
              $isRead = !empty($r['read_at']);
              $altSources = array_filter($cluster['sources'], fn($s) => $s['id'] != $r['id']);
            ?>
            <article class="story <?= $isRead ? 'read' : '' ?>" data-id="<?= $r['id'] ?>" onclick="openStory(this, '<?= h($r['url']) ?>')">
              <div class="story-meta">
                <span class="source"><?= h($r['source_name']) ?></span>
                <span class="time"><?= timeAgo($when) ?></span>
                <?php if ($r['tone'] && $r['tone'] !== 'neutral'): ?>
                  <span class="tone"><?= h($r['tone']) ?></span>
                <?php endif; ?>
              </div>
              
              <h2 class="story-title">
                <a href="<?= h($r['url']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()"><?= h($title) ?></a>
              </h2>
              
              <?php if ($snippet): ?>
                <p class="story-snippet"><?= h($snippet) ?></p>
              <?php endif; ?>
              
              <div class="story-footer">
                <div class="tags">
                  <?php if ($r['cluster_key'] && $r['cluster_key'] !== 'other/misc'): ?>
                    <span class="tag cluster"><?= h($r['cluster_key']) ?></span>
                  <?php endif; ?>
                  <span class="tag">rel <?= (int)$r['relevance'] ?></span>
                  <?php if ((int)$r['ragebait'] > 30): ?>
                    <span class="tag">rage <?= (int)$r['ragebait'] ?></span>
                  <?php endif; ?>
                </div>
                
                <?php if (!empty($altSources)): ?>
                  <div class="alt-sources">
                    also:
                    <?php foreach (array_slice($altSources, 0, 3) as $i => $alt): ?>
                      <?= $i > 0 ? ', ' : '' ?><a href="<?= h($alt['url']) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()"><?= h($alt['name']) ?></a>
                    <?php endforeach; ?>
                    <?php if (count($altSources) > 3): ?>
                      +<?= count($altSources) - 3 ?> more
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  
  <script>
    function openStory(el, url) {
      const id = el.dataset.id;
      
      // Mark as read
      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_read&item_id=' + id
      });
      
      el.classList.add('read');
      
      // Open link
      window.open(url, '_blank');
    }
    
    function markAllRead() {
      fetch('', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=mark_all_read'
      }).then(() => {
        document.querySelectorAll('.story').forEach(el => el.classList.add('read'));
        document.querySelector('.nav-pill .count')?.remove();
      });
    }
    
    function toggleTheme() {
      const html = document.documentElement;
      const current = html.getAttribute('data-theme');
      const next = current === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      localStorage.setItem('theme', next);
    }
    
    // Restore theme preference
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
      document.documentElement.setAttribute('data-theme', savedTheme);
    }
  </script>
</body>
</html>
