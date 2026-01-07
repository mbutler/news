<?php

// seed_prefs.php
// Seeds the user preference profile that guides AI classification.
// Run once: php seed_prefs.php
//
// Edit the $profile below to match your interests and temperament.

require_once __DIR__ . '/lib.php';

$pdo = db();

// ============================================================================
// CUSTOMIZE THIS PROFILE TO YOUR TASTES
// ============================================================================
$profile = <<<'PROFILE'
Software engineer in Iowa City. I want a balanced news diet — current events, tech, and interesting stories. VARIETY IS KEY. Don't flood me with any single topic.

═══ PRIMARY GOAL ═══
Stay informed about what's happening in the world. Mix of:
- Current events and breaking news
- Tech/AI developments  
- Interesting, novel, or thought-provoking stories
- Some science and space (but not dominant)

═══ BALANCE REQUIREMENTS ═══
- NO SINGLE TOPIC should dominate the feed
- If there are 5 space stories and 1 politics story, prefer the politics story for variety
- Current events and world news are JUST AS IMPORTANT as tech
- A good feed has: some news, some tech, some interesting oddities, maybe one space story
- Err toward variety over depth in any single category

═══ INTERESTS (roughly equal weight) ═══

CURRENT EVENTS & WORLD NEWS
- Major news: politics, policy, world events, elections
- US news, international affairs
- What's actually happening — not opinion about what's happening
- Breaking news and developing stories

TECHNOLOGY  
- AI/ML developments (substance over hype)
- Tech industry news
- Programming, software engineering
- Interesting tech projects and launches

TRUSTED: Hacker News
- HN content is pre-filtered by like-minded people
- Give it a relevance boost

SPACE & SCIENCE (moderate — don't overweight)
- Major NASA missions and discoveries
- Significant science breakthroughs
- ONE OR TWO space stories is enough — don't flood

LOCAL (Iowa)
- Iowa City, Cedar Rapids, Iowa state news
- Local politics and policy

GAMING (occasional)
- Major industry news only
- HARD FILTER on culture war framing about games

═══ MODERATE INTEREST ═══

- National politics: policy substance, legislation, governance (NOT horse-race, polls, campaign drama)
- Economics: labor, housing, macro trends
- Major international events: wars, elections, disasters, diplomacy
- Investigative journalism, accountability reporting

═══ NOT INTERESTED ═══

- Celebrity/entertainment news
- Sports (unless major Iowa angle)
- Lifestyle, wellness, self-help
- Crypto speculation, NFTs, memecoins
- True crime
- Influencer drama
- Routine space news (another rocket launched, another satellite deployed — unless it's genuinely significant)
- Incremental science (minor studies, unless breakthrough)

═══ WHAT TO FILTER (high ragebait/culture_war scores) ═══

RAGEBAIT SIGNALS — filter these patterns:
- Headlines designed to provoke outrage: "X SLAMS Y", "outrage over...", "backlash after..."
- Dunking, mockery, tribal point-scoring
- Dehumanizing language about any group
- Catastrophizing: "everything is ruined", "this changes everything"
- Engagement bait: "you won't believe", "what happens next"
- Cherry-picked examples meant to make you angry at an outgroup
- Articles where the point is to feel superior to someone

CULTURE WAR SIGNALS — filter these patterns:
- Left vs right framing on non-political topics
- "Woke" or "anti-woke" framing
- Identity-based tribal packaging
- Articles that exist to confirm one tribe is bad
- Taking sides in social media pile-ons
- Moral panic framing
- When the topic is interesting but the framing is partisan warfare

ANXIETY TRIGGERS — filter:
- Doom-laden framing without actionable information
- "Democracy is dying" doom loops
- Sensationalized threats (unless genuinely urgent safety info)
- Designed to make me feel helpless or afraid

═══ WHAT I ACTUALLY WANT ═══

- Facts over framing
- "Here's what happened" over "here's why you should be angry"
- Analysis that helps me understand, not tells me what to think
- Complexity and nuance over tribal simplicity
- Solutions and what's being done, not just problems
- Calm, measured tone even on serious topics
- Okay to challenge my assumptions — IF it's thoughtful, well-sourced, and not tribal
- Original reporting over aggregated outrage

IMPORTANT — No source gets a free pass:
- Even AP, NPR, Reuters can be sensational or have implicit framing — judge each article on its own merits
- Filter liberal-leaning framing just as much as conservative-leaning framing
- No knee-jerk "Trump is evil" or "Democrats are destroying America" framing
- Politicians should be covered by their actions and policies, not treated as heroes or villains
- If an article assumes I share the author's political priors, that's a red flag
- Neutral means actually neutral, not "mainstream-approved"

When in doubt: would this article make a thoughtful, calm person more informed, or just more agitated? Could someone with different politics read this and find it fair?
PROFILE;

// ============================================================================

$defaultThresholds = json_encode([
  'relevance' => 45,
  'ragebait' => 65,
  'culture_war' => 45,
  'challenge_value' => 60,
  'challenge_ragebait' => 50,
  'challenge_culture_war' => 45,
]);

$stmt = $pdo->prepare("
  INSERT INTO prefs (id, profile_text, thresholds_json) VALUES (1, :profile, :thresholds)
  ON DUPLICATE KEY UPDATE profile_text = VALUES(profile_text)
");
$stmt->execute([':profile' => $profile, ':thresholds' => $defaultThresholds]);

echo "Preferences profile saved.\n";
echo "\nCurrent profile:\n";
echo str_repeat('-', 60) . "\n";
echo $profile . "\n";
echo str_repeat('-', 60) . "\n";

