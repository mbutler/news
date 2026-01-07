<?php

// lib.php
// Shared helpers: DB, URL normalization, hashing, RSS fetch, OpenAI Responses API

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
  
    // Load from environment or .env file
    $env = load_env();
    
    $host = $env['DB_HOST'] ?? '127.0.0.1';
    $name = $env['DB_NAME'] ?? 'news';
    $user = $env['DB_USER'] ?? '';
    $pass = $env['DB_PASS'] ?? '';
    $charset = 'utf8mb4';
    
    if ($user === '') {
      throw new Exception("DB_USER not set. Create a .env file with DB_USER, DB_PASS, DB_HOST, DB_NAME");
    }
  
    $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
    $pdo = new PDO($dsn, $user, $pass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false
    ]);
  
    return $pdo;
  }

function load_env(): array {
  static $env = null;
  if ($env !== null) return $env;
  
  $env = [];
  
  // Check environment variables first
  foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'OPENAI_API_KEY'] as $key) {
    $val = getenv($key);
    if ($val !== false && $val !== '') {
      $env[$key] = $val;
    }
  }
  
  // Load from .env file
  $envFile = __DIR__ . '/.env';
  if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#') continue;
      if (!str_contains($line, '=')) continue;
      [$key, $val] = explode('=', $line, 2);
      $key = trim($key);
      $val = trim($val, "\"' \t");
      if (!isset($env[$key])) {
        $env[$key] = $val;
      }
    }
  }
  
  return $env;
}
  

function now_utc(): string {
  return gmdate('Y-m-d H:i:s');
}

function canonicalize_url(string $url): string {
  $url = trim($url);
  if ($url === '') return $url;

  // Remove common tracking params
  $parts = parse_url($url);
  if (!$parts || !isset($parts['scheme']) || !isset($parts['host'])) return $url;

  $scheme = strtolower($parts['scheme']);
  $host = strtolower($parts['host']);
  $path = $parts['path'] ?? '';
  $query = $parts['query'] ?? '';

  $cleanQuery = '';
  if ($query !== '') {
    parse_str($query, $params);

    $dropPrefixes = ['utm_', 'mc_', 'mkt_', 'ga_', 'fbclid', 'gclid'];
    foreach ($params as $k => $v) {
      $lk = strtolower($k);

      $drop = false;
      foreach ($dropPrefixes as $p) {
        if ($p === 'fbclid' || $p === 'gclid') {
          if ($lk === $p) $drop = true;
        } else {
          if (str_starts_with($lk, $p)) $drop = true;
        }
      }

      if ($drop) unset($params[$k]);
    }

    if (count($params) > 0) {
      ksort($params);
      $cleanQuery = http_build_query($params);
    }
  }

  $port = isset($parts['port']) ? ':' . $parts['port'] : '';
  $frag = ''; // drop fragments

  $out = "{$scheme}://{$host}{$port}{$path}";
  if ($cleanQuery !== '') $out .= '?' . $cleanQuery;
  $out .= $frag;

  return $out;
}

function md5_bin16(string $s): string {
  return md5($s, true); // 16-byte binary
}

function fetch_url(string $url, int $timeoutSeconds = 20): array {
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5,
    CURLOPT_TIMEOUT => $timeoutSeconds,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_USERAGENT => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    CURLOPT_HTTPHEADER => [
      'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8'
    ]
  ]);

  $body = curl_exec($ch);
  $err = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $ct = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
  curl_close($ch);

  return [
    'ok' => $body !== false && $code >= 200 && $code < 400,
    'code' => $code,
    'content_type' => $ct ?: '',
    'body' => $body !== false ? $body : '',
    'error' => $err
  ];
}

function parse_rss_items(string $xml): array {
  $items = [];

  libxml_use_internal_errors(true);
  $feed = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
  if (!$feed) return $items;

  // RSS 2.0: channel->item
  if (isset($feed->channel->item)) {
    foreach ($feed->channel->item as $it) {
      $items[] = [
        'title' => trim((string) $it->title),
        'link' => trim((string) $it->link),
        'pubDate' => trim((string) $it->pubDate),
        'description' => trim((string) $it->description)
      ];
    }
    return $items;
  }

  // Atom: entry
  if (isset($feed->entry)) {
    foreach ($feed->entry as $e) {
      $link = '';
      if (isset($e->link)) {
        foreach ($e->link as $ln) {
          $attrs = $ln->attributes();
          if (isset($attrs['href'])) {
            $rel = (string)($attrs['rel'] ?? '');
            if ($rel === '' || $rel === 'alternate') {
              $link = (string)$attrs['href'];
              break;
            }
          }
        }
      }

      $published = (string)($e->published ?? $e->updated ?? '');
      $summary = (string)($e->summary ?? $e->content ?? '');

      $items[] = [
        'title' => trim((string) $e->title),
        'link' => trim($link),
        'pubDate' => trim($published),
        'description' => trim($summary)
      ];
    }
  }

  return $items;
}

function html_to_text_best_effort(string $html): string {
  if ($html === '') return '';

  // Remove script/style/noscript
  $html = preg_replace('~<script\b[^>]*>.*?</script>~is', ' ', $html);
  $html = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html);
  $html = preg_replace('~<noscript\b[^>]*>.*?</noscript>~is', ' ', $html);

  // Prefer <article> if present
  $article = '';
  if (preg_match('~<article\b[^>]*>(.*?)</article>~is', $html, $m)) {
    $article = $m[1];
  }

  $target = $article !== '' ? $article : $html;

  // Strip tags
  $text = strip_tags($target);

  // Decode entities
  $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

  // Collapse whitespace
  $text = preg_replace('~\s+~u', ' ', $text);
  $text = trim($text);

  // If we used <article> and got something tiny, fallback to full page text
  if ($article !== '' && mb_strlen($text) < 600) {
    $text2 = strip_tags($html);
    $text2 = html_entity_decode($text2, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text2 = preg_replace('~\s+~u', ' ', $text2);
    $text2 = trim($text2);

    if (mb_strlen($text2) > mb_strlen($text)) $text = $text2;
  }

  // Limit to something reasonable to store and process
  if (mb_strlen($text) > 200000) $text = mb_substr($text, 0, 200000);

  return $text;
}

function looks_paywalled(string $text, int $httpCode): bool {
  if ($httpCode === 401 || $httpCode === 402 || $httpCode === 403) return true;

  $t = mb_strtolower($text);

  $signals = [
    'subscribe to continue',
    'subscribe to read',
    'to continue reading',
    'sign in to continue',
    'log in to continue',
    'create an account to continue',
    'become a subscriber',
    'this content is for subscribers',
    'subscribe now to read',
    'already a subscriber'
  ];

  foreach ($signals as $s) {
    if (str_contains($t, $s)) return true;
  }

  // If extracted body is extremely small, treat as effectively inaccessible
  if (mb_strlen($text) < 400) return true;

  return false;
}

function get_openai_api_key(): string {
  $env = load_env();
  if (isset($env['OPENAI_API_KEY']) && $env['OPENAI_API_KEY'] !== '') {
    return $env['OPENAI_API_KEY'];
  }
  throw new Exception("Missing OPENAI_API_KEY in .env file");
}

function openai_chat(string $prompt, ?array $jsonSchema = null): array {
  $apiKey = get_openai_api_key();

  $payload = [
    "model" => "gpt-4o-mini",
    "messages" => [
      ["role" => "user", "content" => $prompt]
    ]
  ];

  if ($jsonSchema !== null) {
    $payload["response_format"] = [
      "type" => "json_schema",
      "json_schema" => [
        "name" => $jsonSchema["name"],
        "strict" => true,
        "schema" => $jsonSchema["schema"]
      ]
    ];
  }

  $ch = curl_init("https://api.openai.com/v1/chat/completions");
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer " . $apiKey,
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 90
  ]);

  $raw = curl_exec($ch);
  if ($raw === false) throw new Exception("cURL error: " . curl_error($ch));

  $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  $data = json_decode($raw, true);
  if (!is_array($data)) throw new Exception("Bad JSON from OpenAI: " . substr($raw, 0, 200));

  if ($status >= 400) {
    $msg = $data["error"]["message"] ?? "OpenAI error";
    throw new Exception("OpenAI HTTP {$status}: {$msg}");
  }

  return $data;
}

function openai_extract_text(array $response): ?string {
  // Extract text from Chat Completions response
  if (isset($response['choices'][0]['message']['content'])) {
    return $response['choices'][0]['message']['content'];
  }
  return null;
}

function get_prefs_profile(): string {
  $pdo = db();
  $row = $pdo->query("SELECT profile_text FROM prefs WHERE id = 1")->fetch();
  return $row ? (string)$row['profile_text'] : '';
}

function get_thresholds(): array {
  $defaults = [
    'relevance' => 45,
    'ragebait' => 65,
    'culture_war' => 45,
    'challenge_value' => 60,
    'challenge_ragebait' => 50,
    'challenge_culture_war' => 45,
  ];
  
  $pdo = db();
  $row = $pdo->query("SELECT thresholds_json FROM prefs WHERE id = 1")->fetch();
  
  if ($row && $row['thresholds_json']) {
    $stored = json_decode($row['thresholds_json'], true);
    if (is_array($stored)) {
      return array_merge($defaults, $stored);
    }
  }
  
  return $defaults;
}
