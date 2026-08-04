<?php
/**
 * One-time deployment helper.
 * Triggers a git pull from the browser when cPanel terminal is not cooperating.
 *
 * Usage: https://your-domain.com/deploy.php?token=d2r-deploy-2024
 *
 * DELETE THIS FILE immediately after use.
 */

// Token protection
if (!isset($_GET['token']) || $_GET['token'] !== 'd2r-deploy-2024') {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

echo "=== D2R Deploy Script ===\n";
echo "Timestamp: " . date('Y-m-d H:i:s') . "\n";
echo "Document Root: " . __DIR__ . "\n\n";

// Run git pull
echo "--- Running: git pull origin hosting ---\n";
$pullOutput = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git pull origin hosting 2>&1');
echo $pullOutput . "\n";

// Show current commits
echo "--- Current commits (git log --oneline -5) ---\n";
$logOutput = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git log --oneline -5 2>&1');
echo $logOutput . "\n";

// Show git status
echo "--- Git status ---\n";
$statusOutput = shell_exec('cd ' . escapeshellarg(__DIR__) . ' && git status 2>&1');
echo $statusOutput . "\n";

echo "=== DONE - now delete this file ===\n";
