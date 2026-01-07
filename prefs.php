<?php

require_once __DIR__ . '/lib.php';

$pdo = db();

$message = '';
$messageType = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = $_POST['action'] ?? '';
  
  if ($action === 'save_profile') {
    $profile = trim($_POST['profile_text'] ?? '');
    if ($profile !== '') {
      $stmt = $pdo->prepare("
        INSERT INTO prefs (id, profile_text) VALUES (1, :profile)
        ON DUPLICATE KEY UPDATE profile_text = VALUES(profile_text)
      ");
      $stmt->execute([':profile' => $profile]);
      $message = 'Profile saved. Changes will apply to newly classified articles.';
      $messageType = 'success';
    }
  }
  
  if ($action === 'save_thresholds') {
    $thresholds = [
      'relevance' => max(0, min(100, (int)($_POST['th_relevance'] ?? 45))),
      'ragebait' => max(0, min(100, (int)($_POST['th_ragebait'] ?? 65))),
      'culture_war' => max(0, min(100, (int)($_POST['th_culture_war'] ?? 45))),
      'challenge_value' => max(0, min(100, (int)($_POST['th_challenge_value'] ?? 60))),
      'challenge_ragebait' => max(0, min(100, (int)($_POST['th_challenge_ragebait'] ?? 50))),
      'challenge_culture_war' => max(0, min(100, (int)($_POST['th_challenge_culture_war'] ?? 45))),
    ];
    
    $stmt = $pdo->prepare("
      UPDATE prefs SET thresholds_json = :thresholds WHERE id = 1
    ");
    $stmt->execute([':thresholds' => json_encode($thresholds)]);
    $message = 'Thresholds saved. Changes will apply to newly classified articles.';
    $messageType = 'success';
  }
  
  if ($action === 'reclassify') {
    // Clear all scores to force reclassification
    $pdo->exec("DELETE FROM scores");
    $pdo->exec("DELETE FROM `reads`");
    $message = 'All scores cleared. Run the classification cron to re-score with new settings.';
    $messageType = 'warning';
  }
}

// Load current preferences
$row = $pdo->query("SELECT profile_text, thresholds_json FROM prefs WHERE id = 1")->fetch();
$profile = $row ? (string)$row['profile_text'] : '';
$thresholds = $row && $row['thresholds_json'] ? json_decode($row['thresholds_json'], true) : [];

// Defaults
$th = [
  'relevance' => $thresholds['relevance'] ?? 45,
  'ragebait' => $thresholds['ragebait'] ?? 65,
  'culture_war' => $thresholds['culture_war'] ?? 45,
  'challenge_value' => $thresholds['challenge_value'] ?? 60,
  'challenge_ragebait' => $thresholds['challenge_ragebait'] ?? 50,
  'challenge_culture_war' => $thresholds['challenge_culture_war'] ?? 45,
];

// Stats
$stats = $pdo->query("
  SELECT 
    COUNT(*) as total,
    SUM(should_read = 1) as accepted,
    SUM(should_read = 0) as rejected,
    AVG(relevance) as avg_relevance,
    AVG(ragebait) as avg_ragebait,
    AVG(culture_war) as avg_culture_war
  FROM scores
  WHERE created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
")->fetch();

$topRejections = $pdo->query("
  SELECT i.title, s.relevance, s.ragebait, s.culture_war, s.calm_reason, src.name as source_name
  FROM scores s
  JOIN items i ON i.id = s.item_id
  JOIN sources src ON src.id = i.source_id
  WHERE s.should_read = 0
    AND s.created_at >= (UTC_TIMESTAMP() - INTERVAL 3 DAY)
  ORDER BY s.relevance DESC
  LIMIT 10
")->fetchAll();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="en" data-theme="dark">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Preferences — News</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Newsreader:ital,opsz,wght@0,6..72,400;0,6..72,500;1,6..72,400&family=DM+Sans:wght@400;500&family=DM+Mono&display=swap" rel="stylesheet">
  <style>
    :root {
      --font-serif: 'Newsreader', Georgia, serif;
      --font-sans: 'DM Sans', system-ui, sans-serif;
      --font-mono: 'DM Mono', monospace;
    }
    
    [data-theme="dark"] {
      --bg: #0f0f0f;
      --bg-card: #1a1a1a;
      --bg-card-hover: #222;
      --bg-input: #151515;
      --text: #e8e6e3;
      --text-dim: #888;
      --text-dimmer: #555;
      --accent: #7eb8da;
      --accent-hover: #9fcce8;
      --border: #2a2a2a;
      --border-focus: #444;
      --success: #4ade80;
      --warning: #fbbf24;
      --danger: #f87171;
    }
    
    [data-theme="light"] {
      --bg: #faf9f7;
      --bg-card: #fff;
      --bg-card-hover: #f5f4f2;
      --bg-input: #fff;
      --text: #1a1a1a;
      --text-dim: #666;
      --text-dimmer: #999;
      --accent: #2563eb;
      --accent-hover: #1d4ed8;
      --border: #e5e4e2;
      --border-focus: #ccc;
      --success: #22c55e;
      --warning: #f59e0b;
      --danger: #ef4444;
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
      max-width: 800px;
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
    .logo a {
      color: inherit;
      text-decoration: none;
    }
    
    .back-link {
      color: var(--text-dim);
      text-decoration: none;
      font-size: 14px;
    }
    .back-link:hover { color: var(--accent); }
    
    h2 {
      font-family: var(--font-serif);
      font-size: 22px;
      font-weight: 500;
      margin: 32px 0 16px;
      color: var(--text);
    }
    h2:first-of-type { margin-top: 0; }
    
    .card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      padding: 24px;
      margin-bottom: 20px;
    }
    
    .message {
      padding: 14px 18px;
      border-radius: 8px;
      margin-bottom: 20px;
      font-size: 14px;
    }
    .message.success {
      background: color-mix(in srgb, var(--success) 15%, transparent);
      border: 1px solid var(--success);
      color: var(--success);
    }
    .message.warning {
      background: color-mix(in srgb, var(--warning) 15%, transparent);
      border: 1px solid var(--warning);
      color: var(--warning);
    }
    
    label {
      display: block;
      font-size: 14px;
      font-weight: 500;
      margin-bottom: 8px;
      color: var(--text-dim);
    }
    
    textarea, input[type="number"] {
      width: 100%;
      background: var(--bg-input);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 12px 14px;
      font-family: var(--font-mono);
      font-size: 14px;
      color: var(--text);
      resize: vertical;
    }
    textarea:focus, input[type="number"]:focus {
      outline: none;
      border-color: var(--border-focus);
    }
    
    textarea {
      min-height: 300px;
      line-height: 1.6;
    }
    
    .hint {
      font-size: 12px;
      color: var(--text-dimmer);
      margin-top: 8px;
    }
    
    .threshold-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 20px;
    }
    
    .threshold-item {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
    }
    
    .threshold-item label {
      margin-bottom: 4px;
    }
    
    .threshold-item .desc {
      font-size: 12px;
      color: var(--text-dimmer);
      margin-bottom: 10px;
    }
    
    .threshold-item input[type="number"] {
      width: 80px;
      text-align: center;
      font-size: 18px;
      font-weight: 500;
      padding: 8px 12px;
    }
    
    .threshold-item .range-hint {
      font-size: 11px;
      color: var(--text-dimmer);
      margin-top: 6px;
    }
    
    .btn {
      display: inline-block;
      padding: 12px 24px;
      border-radius: 8px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      border: none;
      transition: all 0.15s ease;
    }
    
    .btn-primary {
      background: var(--accent);
      color: #000;
    }
    .btn-primary:hover {
      background: var(--accent-hover);
    }
    
    .btn-danger {
      background: transparent;
      border: 1px solid var(--danger);
      color: var(--danger);
    }
    .btn-danger:hover {
      background: var(--danger);
      color: #fff;
    }
    
    .form-actions {
      display: flex;
      gap: 12px;
      margin-top: 20px;
    }
    
    .stats-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 16px;
    }
    
    .stat {
      background: var(--bg);
      border: 1px solid var(--border);
      border-radius: 8px;
      padding: 16px;
      text-align: center;
    }
    
    .stat-value {
      font-size: 28px;
      font-weight: 500;
      color: var(--text);
    }
    
    .stat-label {
      font-size: 12px;
      color: var(--text-dim);
      margin-top: 4px;
    }
    
    .rejection-list {
      margin: 0;
      padding: 0;
      list-style: none;
    }
    
    .rejection-item {
      padding: 14px 0;
      border-bottom: 1px solid var(--border);
    }
    .rejection-item:last-child { border-bottom: none; }
    
    .rejection-title {
      font-size: 14px;
      margin-bottom: 6px;
    }
    
    .rejection-meta {
      font-size: 12px;
      color: var(--text-dim);
      display: flex;
      gap: 12px;
      flex-wrap: wrap;
    }
    
    .rejection-reason {
      font-size: 12px;
      color: var(--text-dimmer);
      margin-top: 6px;
      font-style: italic;
    }
    
    .section-desc {
      font-size: 14px;
      color: var(--text-dim);
      margin-bottom: 16px;
    }
  </style>
</head>
<body>
  <div class="container">
    <header>
      <div class="logo"><a href="index.php">News</a></div>
      <a href="index.php" class="back-link">← Back to feed</a>
    </header>
    
    <?php if ($message): ?>
      <div class="message <?= $messageType ?>"><?= h($message) ?></div>
    <?php endif; ?>
    
    <!-- Stats -->
    <h2>This Week</h2>
    <div class="card">
      <div class="stats-grid">
        <div class="stat">
          <div class="stat-value"><?= (int)$stats['total'] ?></div>
          <div class="stat-label">Articles scored</div>
        </div>
        <div class="stat">
          <div class="stat-value"><?= (int)$stats['accepted'] ?></div>
          <div class="stat-label">Accepted</div>
        </div>
        <div class="stat">
          <div class="stat-value"><?= (int)$stats['rejected'] ?></div>
          <div class="stat-label">Filtered out</div>
        </div>
        <div class="stat">
          <div class="stat-value"><?= $stats['total'] > 0 ? round((int)$stats['accepted'] / (int)$stats['total'] * 100) : 0 ?>%</div>
          <div class="stat-label">Accept rate</div>
        </div>
      </div>
    </div>
    
    <!-- Thresholds -->
    <h2>Scoring Thresholds</h2>
    <p class="section-desc">
      Adjust these to tune what gets through. Higher relevance = stricter topic matching. 
      Lower ragebait/culture-war = stricter filtering of outrage content.
    </p>
    <form method="POST" class="card">
      <input type="hidden" name="action" value="save_thresholds">
      
      <div class="threshold-grid">
        <div class="threshold-item">
          <label>Min Relevance</label>
          <div class="desc">Article must score at least this to pass</div>
          <input type="number" name="th_relevance" value="<?= $th['relevance'] ?>" min="0" max="100">
          <div class="range-hint">Higher = stricter</div>
        </div>
        
        <div class="threshold-item">
          <label>Max Ragebait</label>
          <div class="desc">Filter if ragebait score exceeds this</div>
          <input type="number" name="th_ragebait" value="<?= $th['ragebait'] ?>" min="0" max="100">
          <div class="range-hint">Lower = stricter</div>
        </div>
        
        <div class="threshold-item">
          <label>Max Culture War</label>
          <div class="desc">Filter if culture-war framing exceeds this</div>
          <input type="number" name="th_culture_war" value="<?= $th['culture_war'] ?>" min="0" max="100">
          <div class="range-hint">Lower = stricter</div>
        </div>
        
        <div class="threshold-item">
          <label>Challenge Value</label>
          <div class="desc">Allow constructive counterpoints if they score this high</div>
          <input type="number" name="th_challenge_value" value="<?= $th['challenge_value'] ?>" min="0" max="100">
          <div class="range-hint">Higher = fewer counterpoints</div>
        </div>
        
        <div class="threshold-item">
          <label>Challenge Max Ragebait</label>
          <div class="desc">Max ragebait for challenge-lane articles</div>
          <input type="number" name="th_challenge_ragebait" value="<?= $th['challenge_ragebait'] ?>" min="0" max="100">
          <div class="range-hint">Lower = stricter</div>
        </div>
        
        <div class="threshold-item">
          <label>Challenge Max Culture War</label>
          <div class="desc">Max culture-war for challenge-lane articles</div>
          <input type="number" name="th_challenge_culture_war" value="<?= $th['challenge_culture_war'] ?>" min="0" max="100">
          <div class="range-hint">Lower = stricter</div>
        </div>
      </div>
      
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Thresholds</button>
      </div>
    </form>
    
    <!-- Profile -->
    <h2>Interest Profile</h2>
    <p class="section-desc">
      Describe your interests, preferences, and what you want to avoid. 
      The AI uses this to score relevance and make filtering decisions.
    </p>
    <form method="POST" class="card">
      <input type="hidden" name="action" value="save_profile">
      
      <label for="profile_text">Your Profile</label>
      <textarea id="profile_text" name="profile_text" placeholder="Describe your interests..."><?= h($profile) ?></textarea>
      <p class="hint">
        Be specific about topics you care about, topics you want to avoid, and your preferences for tone and framing.
      </p>
      
      <div class="form-actions">
        <button type="submit" class="btn btn-primary">Save Profile</button>
      </div>
    </form>
    
    <!-- Recent rejections -->
    <?php if (!empty($topRejections)): ?>
    <h2>Recently Filtered Out</h2>
    <p class="section-desc">
      High-relevance articles that were filtered. If you see things here you wanted, consider adjusting thresholds.
    </p>
    <div class="card">
      <ul class="rejection-list">
        <?php foreach ($topRejections as $rej): ?>
          <li class="rejection-item">
            <div class="rejection-title"><?= h($rej['title']) ?></div>
            <div class="rejection-meta">
              <span><?= h($rej['source_name']) ?></span>
              <span>rel <?= (int)$rej['relevance'] ?></span>
              <span>rage <?= (int)$rej['ragebait'] ?></span>
              <span>cw <?= (int)$rej['culture_war'] ?></span>
            </div>
            <?php if ($rej['calm_reason']): ?>
              <div class="rejection-reason"><?= h($rej['calm_reason']) ?></div>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
    
    <!-- Danger zone -->
    <h2>Reset</h2>
    <div class="card">
      <p class="section-desc" style="margin-bottom: 16px;">
        Clear all scores to force re-classification with your new settings. 
        This won't delete articles, just their scores.
      </p>
      <form method="POST" onsubmit="return confirm('This will clear all scores and read status. Continue?')">
        <input type="hidden" name="action" value="reclassify">
        <button type="submit" class="btn btn-danger">Clear All Scores</button>
      </form>
    </div>
  </div>
  
  <script>
    // Restore theme preference
    const savedTheme = localStorage.getItem('theme');
    if (savedTheme) {
      document.documentElement.setAttribute('data-theme', savedTheme);
    }
  </script>
</body>
</html>

