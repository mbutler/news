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
echo "─── Cron Setup ───"
echo ""

CRON_INGEST="*/15 * * * * cd $SCRIPT_DIR && php cron_ingest.php >> /var/log/news_ingest.log 2>&1"
CRON_CLASSIFY="0 * * * * cd $SCRIPT_DIR && php cron_classify_rewrite.php >> /var/log/news_classify.log 2>&1"

# Check if cron jobs already exist
EXISTING_CRON=$(crontab -l 2>/dev/null || echo "")

if echo "$EXISTING_CRON" | grep -q "cron_ingest.php"; then
  echo -e "${GREEN}✓${NC} Cron jobs already installed"
else
  read -p "Install cron jobs automatically? (Y/n): " INSTALL_CRON
  if [[ ! "$INSTALL_CRON" =~ ^[Nn]$ ]]; then
    # Add cron jobs
    (echo "$EXISTING_CRON"; echo ""; echo "# News feed - ingest every 15 min"; echo "$CRON_INGEST"; echo "# News feed - classify every hour"; echo "$CRON_CLASSIFY") | crontab -
    echo -e "${GREEN}✓${NC} Cron jobs installed"
    echo ""
    echo "Installed:"
    echo "  - Ingest every 15 minutes"
    echo "  - Classify every hour"
  else
    echo ""
    echo "To install manually, run: crontab -e"
    echo "Then add:"
    echo -e "${YELLOW}$CRON_INGEST${NC}"
    echo -e "${YELLOW}$CRON_CLASSIFY${NC}"
  fi
fi

echo ""
echo "╔════════════════════════════════════════╗"
echo "║           Setup Complete!              ║"
echo "╚════════════════════════════════════════╝"
echo ""
echo "─── Web Server ───"
echo ""
echo "Point your web server to: $SCRIPT_DIR/index.php"
echo ""
echo "For quick testing with PHP's built-in server:"
echo "  php -S localhost:8080"
echo ""
echo -e "${GREEN}Done! Visit your news feed to see it in action.${NC}"

