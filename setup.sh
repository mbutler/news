#!/bin/bash

# setup.sh — Full setup for the personalized news feed
# Run: chmod +x setup.sh && ./setup.sh

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo "╔════════════════════════════════════════╗"
echo "║     Personal News Feed Setup           ║"
echo "╚════════════════════════════════════════╝"
echo ""

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Check for required tools
command -v php >/dev/null 2>&1 || { echo -e "${RED}Error: php is required but not installed.${NC}" >&2; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo -e "${RED}Error: mysql client is required but not installed.${NC}" >&2; exit 1; }

# Check for .env file with OpenAI key
if [ ! -f ".env" ]; then
  echo -e "${YELLOW}No .env file found.${NC}"
  echo "Creating .env template..."
  echo "OPENAI_API_KEY=sk-your-key-here" > .env
  echo -e "${YELLOW}Please edit .env and add your OpenAI API key, then re-run this script.${NC}"
  exit 1
fi

if grep -q "sk-your-key-here" .env; then
  echo -e "${RED}Please edit .env and replace 'sk-your-key-here' with your actual OpenAI API key.${NC}"
  exit 1
fi

echo -e "${GREEN}✓${NC} .env file found with API key"

# Database setup
echo ""
echo "─── Database Setup ───"
echo ""
read -p "MySQL username [mbutler]: " DB_USER
DB_USER=${DB_USER:-mbutler}

read -p "MySQL database name [news]: " DB_NAME
DB_NAME=${DB_NAME:-news}

read -p "Drop and recreate database? (y/N): " DROP_DB
if [[ "$DROP_DB" =~ ^[Yy]$ ]]; then
  echo "Dropping and recreating database..."
  mysql -u "$DB_USER" -p -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  echo -e "${GREEN}✓${NC} Database recreated"
fi

echo "Running schema..."
mysql -u "$DB_USER" -p "$DB_NAME" < schema.sql
echo -e "${GREEN}✓${NC} Schema applied"

# Seed data
echo ""
echo "─── Seeding Data ───"
echo ""

echo "Seeding RSS sources..."
php seed_sources.php

echo ""
echo "Seeding preferences..."
php seed_prefs.php

# Initial ingest
echo ""
echo "─── Initial Data Load ───"
echo ""
read -p "Run initial ingest now? (Y/n): " RUN_INGEST
if [[ ! "$RUN_INGEST" =~ ^[Nn]$ ]]; then
  echo "Fetching RSS feeds (this may take a minute)..."
  php cron_ingest.php
  echo -e "${GREEN}✓${NC} Ingest complete"
  
  read -p "Run classification now? This uses OpenAI API credits. (Y/n): " RUN_CLASSIFY
  if [[ ! "$RUN_CLASSIFY" =~ ^[Nn]$ ]]; then
    echo "Classifying articles (this may take a few minutes)..."
    php cron_classify_rewrite.php
    echo -e "${GREEN}✓${NC} Classification complete"
  fi
fi

# Cron setup
echo ""
echo "╔════════════════════════════════════════╗"
echo "║           Setup Complete!              ║"
echo "╚════════════════════════════════════════╝"
echo ""
echo "─── Cron Setup ───"
echo ""
echo "Add these to your crontab (crontab -e):"
echo ""
echo -e "${YELLOW}# Ingest new articles every 15 minutes${NC}"
echo "*/15 * * * * cd $SCRIPT_DIR && php cron_ingest.php >> /var/log/news_ingest.log 2>&1"
echo ""
echo -e "${YELLOW}# Classify and rewrite every hour${NC}"
echo "0 * * * * cd $SCRIPT_DIR && php cron_classify_rewrite.php >> /var/log/news_classify.log 2>&1"
echo ""
echo "─── Web Server ───"
echo ""
echo "Point your web server to: $SCRIPT_DIR/index.php"
echo ""
echo "For quick testing with PHP's built-in server:"
echo "  php -S localhost:8080"
echo ""
echo -e "${GREEN}Done! Visit your news feed to see it in action.${NC}"

