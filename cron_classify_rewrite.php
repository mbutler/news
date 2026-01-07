<?php

require_once __DIR__ . '/lib.php';

// cron_classify_rewrite.php
// Process items in batches to avoid API token limits

$pdo = db();
$prefs = get_prefs_profile();

// Load thresholds from database (editable via web UI)
$th = get_thresholds();
$thresholdRelevance = $th['relevance'];
$thresholdRagebait = $th['ragebait'];
$thresholdCultureWar = $th['culture_war'];
$thresholdChallengeValue = $th['challenge_value'];
$thresholdChallengeRagebait = $th['challenge_ragebait'];
$thresholdChallengeCultureWar = $th['challenge_culture_war'];

echo "Thresholds: rel>={$thresholdRelevance}, rage<={$thresholdRagebait}, cw<={$thresholdCultureWar}\n";

// Pull items needing classification - only NEW items (no score yet)
$items = $pdo->query("
  SELECT i.id, i.title, i.url, i.excerpt, i.raw_text, src.name as source_name
  FROM items i
  JOIN sources src ON src.id = i.source_id
  LEFT JOIN scores s ON s.item_id = i.id
  WHERE i.paywalled = 0
    AND s.item_id IS NULL
    AND i.created_at >= (UTC_TIMESTAMP() - INTERVAL 7 DAY)
  ORDER BY i.created_at DESC
  LIMIT 500
")->fetchAll();

echo "Found " . count($items) . " items needing classification\n";

if (count($items) === 0) {
  echo "No items to classify\n";
  exit(0);
}

$classSchema = [
  "name" => "news_classification",
  "schema" => [
    "type" => "object",
    "additionalProperties" => false,
    "properties" => [
      "items" => [
        "type" => "array",
        "items" => [
          "type" => "object",
          "additionalProperties" => false,
          "properties" => [
            "item_id" => ["type" => "integer"],
            "relevance" => ["type" => "integer"],
            "ragebait" => ["type" => "integer"],
            "culture_war" => ["type" => "integer"],
            "novelty" => ["type" => "integer"],
            "topics" => ["type" => "array", "items" => ["type" => "string"]],
            "cluster_key" => ["type" => "string"],
            "challenge_value" => ["type" => "integer"],
            "perspective" => ["type" => "string"],
            "tone" => ["type" => "string"],
            "should_read" => ["type" => "boolean"],
            "calm_reason" => ["type" => "string"]
          ],
          "required" => ["item_id", "relevance", "ragebait", "culture_war", "novelty", "topics", "cluster_key", "challenge_value", "perspective", "tone", "should_read", "calm_reason"]
        ]
      ]
    ],
    "required" => ["items"]
  ],
];

// Prepare statements
$insScore = $pdo->prepare("
  INSERT INTO scores (
    item_id, relevance, ragebait, novelty, topics_json, cluster_key, calm_reason, should_read, culture_war, tone, challenge_value, perspective, created_at
  ) VALUES (
    :item_id, :relevance, :ragebait, :novelty, :topics_json, :cluster_key, :calm_reason, :should_read, :culture_war, :tone, :challenge_value, :perspective, UTC_TIMESTAMP()
  )
  ON DUPLICATE KEY UPDATE
    relevance = VALUES(relevance),
    ragebait = VALUES(ragebait),
    novelty = VALUES(novelty),
    topics_json = VALUES(topics_json),
    cluster_key = VALUES(cluster_key),
    calm_reason = VALUES(calm_reason),
    should_read = VALUES(should_read),
    culture_war = VALUES(culture_war),
    tone = VALUES(tone),
    challenge_value = VALUES(challenge_value),
    perspective = VALUES(perspective),
    created_at = UTC_TIMESTAMP()
");

// Process in batches of 25
$batchSize = 25;
$batches = array_chunk($items, $batchSize);
$totalAccepted = 0;
$totalRejected = 0;
$totalProcessed = 0;

echo "Processing " . count($batches) . " batches of up to {$batchSize} items each...\n\n";

foreach ($batches as $batchNum => $batch) {
  $batchIndex = $batchNum + 1;
  echo "Batch {$batchIndex}/" . count($batches) . ": ";
  
  // Build bundle for this batch
  $bundle = [];
  $byId = [];
  foreach ($batch as $it) {
    $text = $it['raw_text'] ? mb_substr((string)$it['raw_text'], 0, 800) : '';
    $bundle[] = [
      'item_id' => (int)$it['id'],
      'title' => (string)$it['title'],
      'source' => (string)$it['source_name'],
      'excerpt' => mb_substr((string)($it['excerpt'] ?? ''), 0, 300),
      'text' => $text
    ];
    $byId[(int)$it['id']] = $it;
  }

  $itemsJson = json_encode($bundle, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

  $input = <<<TXT
You are filtering news for one person. Score each item.

User profile:
{$prefs}

For each item return:
- relevance (0-100): match to user interests
- ragebait (0-100): outrage bait, dunking, tribal, sensational
- culture_war (0-100): partisan/identity framing
- novelty (0-100): new vs churn
- tone: neutral|analysis|opinion|outrage
- challenge_value (0-100): constructively challenges user's view
- perspective: neutral|aligned|adjacent|oppositional|mixed
- cluster_key: short topic ID (e.g. "ai/releases", "world/ukraine", "tech/layoffs")
- topics: array of topic tags
- should_read: true if worth reading (relevance>={$thresholdRelevance} AND ragebait<={$thresholdRagebait} AND culture_war<={$thresholdCultureWar}), OR major world news, OR high challenge_value with moderate rage/cw
- calm_reason: brief explanation (max 200 chars)

Items:
{$itemsJson}
TXT;

  try {
    $resp = openai_chat($input, $classSchema);
    $json = openai_extract_text($resp);
    
    if (!$json) {
      echo "ERROR: No response\n";
      continue;
    }

    $classified = json_decode($json, true);
    if (!is_array($classified) || !isset($classified['items'])) {
      echo "ERROR: Bad JSON\n";
      continue;
    }

    $batchAccepted = 0;
    $batchRejected = 0;

    foreach ($classified['items'] as $row) {
      $itemId = (int)$row['item_id'];
      if (!isset($byId[$itemId])) continue;

      $rel = (int)$row['relevance'];
      $rage = (int)$row['ragebait'];
      $cw = (int)$row['culture_war'];
      $challenge = (int)($row['challenge_value'] ?? 0);

      // Determine should_read using our thresholds
      $shouldRead = false;
      $reason = '';

      if ($rel >= $thresholdRelevance && $rage <= $thresholdRagebait && $cw <= $thresholdCultureWar) {
        $shouldRead = true;
        $reason = 'primary';
      } elseif ($rel >= 35 && ($rage <= 60 || $cw <= 40)) {
        $shouldRead = true;
        $reason = 'borderline';
      } elseif ($challenge >= $thresholdChallengeValue && $rage <= $thresholdChallengeRagebait && $cw <= $thresholdChallengeCultureWar) {
        $shouldRead = true;
        $reason = 'challenge';
      }

      if ($shouldRead) {
        $batchAccepted++;
        $totalAccepted++;
      } else {
        $batchRejected++;
        $totalRejected++;
      }

      $insScore->execute([
        ':item_id' => $itemId,
        ':relevance' => $rel,
        ':ragebait' => $rage,
        ':novelty' => (int)($row['novelty'] ?? 50),
        ':topics_json' => json_encode($row['topics'] ?? [], JSON_UNESCAPED_UNICODE),
        ':cluster_key' => (string)($row['cluster_key'] ?? 'other/misc'),
        ':calm_reason' => mb_substr((string)($row['calm_reason'] ?? ''), 0, 280),
        ':should_read' => $shouldRead ? 1 : 0,
        ':culture_war' => $cw,
        ':tone' => (string)($row['tone'] ?? 'neutral'),
        ':challenge_value' => $challenge,
        ':perspective' => (string)($row['perspective'] ?? 'neutral')
      ]);

      $totalProcessed++;
    }

    echo "{$batchAccepted} accepted, {$batchRejected} rejected\n";

  } catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
  }

  // Small delay between batches to be nice to the API
  if ($batchNum < count($batches) - 1) {
    usleep(500000); // 0.5 second
  }
}

echo "\n";
echo "=================================\n";
echo "Total processed: {$totalProcessed}\n";
echo "Total accepted: {$totalAccepted}\n";
echo "Total rejected: {$totalRejected}\n";
echo "=================================\n";
