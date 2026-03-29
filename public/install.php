<?php

declare(strict_types=1);

// Auto-detect layout: src/ next to this file (flat) or one level up (nested/public)
$srcDir = is_dir(__DIR__ . '/src') ? __DIR__ . '/src' : __DIR__ . '/../src';

require $srcDir . '/bootstrap.php';
require $srcDir . '/Database.php';
require $srcDir . '/Auth.php';

$envPath = APP_ROOT . '/.env';
$dataDir = APP_ROOT . '/data';
$dbPath = $dataDir . '/marketing.sqlite';

$defaults = [
    'BUSINESS_NAME' => 'My Small Business',
    'BUSINESS_INDUSTRY' => 'Local services',
    'TIMEZONE' => 'America/New_York',
    'AI_PROVIDER' => 'openai',
    'AI_MODEL' => 'gpt-4.1-mini',
    'OPENAI_API_KEY' => '',
    'OPENAI_BASE_URL' => 'https://api.openai.com/v1',
    'ANTHROPIC_API_KEY' => '',
    'ANTHROPIC_MODEL' => 'claude-sonnet-4-20250514',
    'GEMINI_API_KEY' => '',
    'GEMINI_MODEL' => 'gemini-2.5-flash',
    'DEEPSEEK_API_KEY' => '',
    'DEEPSEEK_MODEL' => 'deepseek-chat',
    'GROQ_API_KEY' => '',
    'GROQ_MODEL' => 'llama-3.3-70b-versatile',
    'MISTRAL_API_KEY' => '',
    'MISTRAL_MODEL' => 'mistral-large-latest',
    'OPENROUTER_API_KEY' => '',
    'OPENROUTER_MODEL' => 'anthropic/claude-sonnet-4',
    'XAI_API_KEY' => '',
    'XAI_MODEL' => 'grok-3-fast',
    'TOGETHER_API_KEY' => '',
    'TOGETHER_MODEL' => 'meta-llama/Llama-3.3-70B-Instruct-Turbo',
    'APP_URL' => '',
    'CRON_KEY' => bin2hex(random_bytes(16)),
    'SMTP_HOST' => '',
    'SMTP_PORT' => '587',
    'SMTP_USER' => '',
    'SMTP_PASS' => '',
    'SMTP_FROM' => '',
    'SMTP_FROM_NAME' => '',
    'MAX_UPLOAD_MB' => '10',
];

$values = $defaults;
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Strip surrounding quotes (same logic as bootstrap.php env_value())
        if (strlen($value) >= 2 && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))) {
            $value = substr($value, 1, -1);
            $value = str_replace(['\\"', '\\\\'], ['"', '\\'], $value);
        }
        if (array_key_exists($key, $values)) {
            $values[$key] = $value;
        }
    }
}

$status = [];
$errors = [];
$success = false;

/**
 * Validate host system requirements for installation.
 *
 * @return array{checks: array<int, array{label: string, ok: bool, detail: string}>, has_errors: bool}
 */
function installer_system_checks(string $dataDir, string $envPath): array
{
    $checks = [];

    $requiredExtensions = ['pdo_sqlite', 'curl', 'mbstring'];
    foreach ($requiredExtensions as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = [
            'label' => "PHP extension: {$ext}",
            'ok' => $ok,
            'detail' => $ok ? 'Loaded' : 'Missing (required)',
        ];
    }

    $optionalExtensions = ['gd'];
    foreach ($optionalExtensions as $ext) {
        $ok = extension_loaded($ext);
        $checks[] = [
            'label' => "PHP extension: {$ext}",
            'ok' => true,
            'detail' => $ok ? 'Loaded (optional)' : 'Missing (optional: image thumbnails disabled)',
        ];
    }

    $appRootWritable = is_writable(APP_ROOT);
    $checks[] = [
        'label' => 'App root writable',
        'ok' => $appRootWritable,
        'detail' => $appRootWritable ? 'Writable' : 'Not writable (needed to create/update .env)',
    ];

    $dataDirReady = is_dir($dataDir) ? is_writable($dataDir) : is_writable(dirname($dataDir));
    $checks[] = [
        'label' => 'Data directory writable',
        'ok' => $dataDirReady,
        'detail' => $dataDirReady ? 'Writable' : 'Not writable (needed for SQLite and uploads)',
    ];

    $envExists = is_file($envPath);
    if ($envExists) {
        $checks[] = [
            'label' => '.env writable',
            'ok' => is_writable($envPath),
            'detail' => is_writable($envPath) ? 'Writable' : 'Not writable (cannot update configuration)',
        ];
    }

    $hasErrors = false;
    foreach ($checks as $check) {
        if (!$check['ok']) {
            $hasErrors = true;
            break;
        }
    }

    return ['checks' => $checks, 'has_errors' => $hasErrors];
}

$systemCheckResults = installer_system_checks($dataDir, $envPath);

// CSRF protection
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    $csrfToken = $_POST['_csrf'] ?? '';
    if (!isset($_SESSION['install_csrf']) || !hash_equals($_SESSION['install_csrf'], $csrfToken)) {
        $errors[] = 'Invalid form submission. Please refresh and try again.';
    }
    // Regenerate token for next request
    $_SESSION['install_csrf'] = bin2hex(random_bytes(32));

    $sensitiveKeys = [
        'OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'DEEPSEEK_API_KEY',
        'GROQ_API_KEY', 'MISTRAL_API_KEY', 'OPENROUTER_API_KEY', 'XAI_API_KEY',
        'TOGETHER_API_KEY', 'SMTP_PASS',
    ];

    foreach (array_keys($defaults) as $key) {
        if (isset($_POST[$key])) {
            $submittedValue = trim((string)$_POST[$key]);
            // Preserve existing secret values when installer shows masked values
            // or when secret fields are left blank during a config update.
            if (in_array($key, $sensitiveKeys, true) && ($submittedValue === '' || str_contains($submittedValue, '•'))) {
                continue;
            }
            $values[$key] = $submittedValue;
        }
    }

    foreach (['BUSINESS_NAME', 'BUSINESS_INDUSTRY', 'TIMEZONE', 'AI_PROVIDER'] as $required) {
        if ($values[$required] === '') {
            $errors[] = "{$required} is required.";
        }
    }

    if ($systemCheckResults['has_errors']) {
        $errors[] = 'System requirements are not met. Resolve failed checks before continuing.';
    }

    if (!in_array($values['AI_PROVIDER'], ['openai', 'anthropic', 'gemini', 'deepseek', 'groq', 'mistral', 'openrouter', 'xai', 'together'], true)) {
        $errors[] = 'AI_PROVIDER must be one of: openai, anthropic, gemini, deepseek, groq, mistral, openrouter, xai, together.';
    }

    // Validate admin user if provided
    $adminUser = trim($_POST['admin_username'] ?? '');
    $adminPass = $_POST['admin_password'] ?? '';

    if ($adminPass !== '') {
        $passwordStrong = strlen($adminPass) >= 10
            && preg_match('/[A-Z]/', $adminPass)
            && preg_match('/[a-z]/', $adminPass)
            && preg_match('/\d/', $adminPass)
            && preg_match('/[^a-zA-Z0-9]/', $adminPass);
        if (!$passwordStrong) {
            $errors[] = 'Admin password must be 10+ chars with uppercase, lowercase, number, and symbol.';
        }
    }

    if ($errors === []) {
        if (!is_dir($dataDir) && !mkdir($dataDir, 0750, true) && !is_dir($dataDir)) {
            $errors[] = 'Unable to create the data directory. Check filesystem permissions.';
        }

        if ($errors === []) {
            // Ensure CRON_KEY is set
            if ($values['CRON_KEY'] === '') {
                $values['CRON_KEY'] = bin2hex(random_bytes(16));
            }

            $envLines = [
                '# Generated by web installer (' . gmdate(DATE_ATOM) . ')',
            ];
            foreach ($values as $key => $value) {
                $safeValue = str_replace(["\r", "\n"], ' ', $value);
                // Quote values that contain special chars
                if (preg_match('/[=# "\'\\\\]/', $safeValue)) {
                    $safeValue = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $safeValue) . '"';
                }
                $envLines[] = sprintf('%s=%s', $key, $safeValue);
            }

            $written = file_put_contents($envPath, implode(PHP_EOL, $envLines) . PHP_EOL);
            if ($written === false) {
                $errors[] = 'Unable to write .env file. Check directory permissions.';
            } else {
                // Restrict .env to owner-only (contains API keys and credentials)
                @chmod($envPath, 0600);
                try {
                    $db = new Database($dbPath);
                    $status[] = 'Database initialized successfully.';

                    // Create admin user if provided
                    if ($adminUser !== '' && $adminPass !== '') {
                        $auth = new Auth($db->pdo());
                        if ($auth->userCount() === 0 || !empty($_POST['create_admin'])) {
                            $auth->createUser($adminUser, $adminPass, 'admin');
                            $status[] = "Admin user '{$adminUser}' created.";
                        } else {
                            $status[] = 'Admin user already exists. Skipped creation.';
                        }
                    }

                    $success = true;
                } catch (Throwable $exception) {
                    $errors[] = 'Database initialization failed: ' . $exception->getMessage();
                }
            }
        }
    }
}

// Check current state
$installed = is_file($envPath) && is_file($dbPath);
$hasUsers = false;
if ($installed) {
    try {
        $db = new Database($dbPath);
        $auth = new Auth($db->pdo());
        $hasUsers = $auth->userCount() > 0;
    } catch (Throwable) {
        // ignore
    }
}

function esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

// Mask sensitive values when displaying after installation
$displayValues = $values;
if ($installed) {
    $sensitiveKeys = ['OPENAI_API_KEY', 'ANTHROPIC_API_KEY', 'GEMINI_API_KEY', 'DEEPSEEK_API_KEY', 'GROQ_API_KEY', 'MISTRAL_API_KEY', 'OPENROUTER_API_KEY', 'XAI_API_KEY', 'TOGETHER_API_KEY', 'SMTP_PASS'];
    foreach ($sensitiveKeys as $sk) {
        if (!empty($displayValues[$sk])) {
            $displayValues[$sk] = substr($displayValues[$sk], 0, 6) . '••••••••';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width,initial-scale=1" />
  <title>Marketing Suite Installer</title>
  <style>
    * { box-sizing: border-box; }
    body { font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0d1117; margin: 0; color: #e6edf3; }
    .wrap { max-width: 820px; margin: 2rem auto; background: #151b23; border-radius: 14px; border: 1px solid #2f3742; padding: 1.5rem 1.8rem; }
    h1 { margin: 0 0 0.3rem; font-size: 1.5rem; color: #e6edf3; }
    h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; color: #9aa4b2; border-bottom: 1px solid #2f3742; padding-bottom: 0.4rem; }
    p { color: #9aa4b2; }
    .grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0.8rem; }
    @media (max-width: 600px) { .grid { grid-template-columns: 1fr; } }
    label { display: block; font-size: 0.85rem; font-weight: 600; margin: 0.5rem 0 0.2rem; color: #e6edf3; }
    input, select { width: 100%; box-sizing: border-box; padding: 0.6rem; border: 1px solid #384252; border-radius: 8px; background: #0e141d; color: #e6edf3; font-size: 0.9rem; }
    input:focus, select:focus { border-color: #4c8dff; outline: none; }
    .full { grid-column: 1 / -1; }
    .msg { padding: 0.7rem 0.9rem; border-radius: 9px; margin: 0.8rem 0; }
    .ok { background: #0d2818; color: #2da44e; border: 1px solid #1b3d29; }
    .err { background: #2d1215; color: #da3633; border: 1px solid #4a1d1f; }
    .warn { background: #2d2305; color: #d4a72c; border: 1px solid #4a3b0f; }
    .info { background: #0c1929; color: #4c8dff; border: 1px solid #1c3457; }
    button { margin-top: 1rem; background: #4c8dff; color: #fff; border: none; padding: 0.75rem 1.2rem; border-radius: 8px; cursor: pointer; font-weight: 600; font-size: 0.95rem; }
    button:hover { background: #6ba1ff; }
    code { background: #1c2333; padding: 0.15rem 0.4rem; border-radius: 4px; font-size: 0.85rem; }
    ul { margin: 0; padding-left: 1.2rem; }
    small { color: #6b7685; }
    a { color: #4c8dff; }
  </style>
</head>
<body>
<div class="wrap">
  <h1>Marketing Suite Installer</h1>
  <p>Configure your marketing platform. Creates <code>.env</code>, initializes the database, and sets up your admin account.</p>

  <?php if ($systemCheckResults['checks'] !== []): ?>
    <h2>System Checks</h2>
    <div class="msg <?= $systemCheckResults['has_errors'] ? 'err' : 'ok' ?>">
      <ul>
        <?php foreach ($systemCheckResults['checks'] as $check): ?>
          <li>
            <strong><?= esc($check['label']) ?>:</strong>
            <?= $check['ok'] ? '✅' : '❌' ?>
            <?= esc($check['detail']) ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <?php if ($installed && !$success): ?>
    <div class="msg warn">Existing installation detected. This form will update your configuration.</div>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="msg err">
      <strong>Errors:</strong>
      <ul><?php foreach ($errors as $e): ?><li><?= esc($e) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($success): ?>
    <div class="msg ok">
      <strong>Installation complete!</strong>
      <ul><?php foreach ($status as $s): ?><li><?= esc($s) ?></li><?php endforeach; ?></ul>
      <?php $appBase = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/') ?: '/'; ?>
      <p style="margin-top:0.6rem">Open <a href="<?= esc($appBase) ?>">the app</a> to get started. For security, remove or restrict <code>install.php</code> after setup.</p>
    </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_csrf" value="<?= esc($_SESSION['install_csrf'] ?? '') ?>" />
    <h2>Business</h2>
    <div class="grid">
      <div>
        <label for="BUSINESS_NAME">Business name</label>
        <input id="BUSINESS_NAME" name="BUSINESS_NAME" value="<?= esc($values['BUSINESS_NAME']) ?>" required />
      </div>
      <div>
        <label for="BUSINESS_INDUSTRY">Industry</label>
        <input id="BUSINESS_INDUSTRY" name="BUSINESS_INDUSTRY" value="<?= esc($values['BUSINESS_INDUSTRY']) ?>" required />
      </div>
      <div>
        <label for="TIMEZONE">Timezone</label>
        <input id="TIMEZONE" name="TIMEZONE" value="<?= esc($values['TIMEZONE']) ?>" required />
      </div>
      <div>
        <label for="APP_URL">App URL <small>(e.g. https://marketing.example.com)</small></label>
        <input id="APP_URL" name="APP_URL" value="<?= esc($values['APP_URL']) ?>" placeholder="https://..." />
      </div>
    </div>

    <h2>Admin Account</h2>
    <div class="grid">
      <div>
        <label for="admin_username">Username</label>
        <input id="admin_username" name="admin_username" value="admin" <?= $hasUsers ? '' : 'required' ?> />
      </div>
      <div>
        <label for="admin_password">Password</label>
        <input id="admin_password" name="admin_password" type="password" autocomplete="new-password" minlength="10" <?= $hasUsers ? '' : 'required' ?> />
      </div>
    </div>
    <?php if ($hasUsers): ?>
      <div class="msg info">Admin user already exists. Leave fields empty to keep existing user, or fill in to create a new one.
        <label style="margin-top:0.3rem"><input type="checkbox" name="create_admin" value="1" /> Create additional admin user</label>
      </div>
    <?php endif; ?>

    <h2>AI Provider</h2>
    <div class="grid">
      <div>
        <label for="AI_PROVIDER">Primary AI provider</label>
        <select id="AI_PROVIDER" name="AI_PROVIDER">
          <?php foreach (['openai', 'anthropic', 'gemini', 'deepseek', 'groq', 'mistral', 'openrouter', 'xai', 'together'] as $p): ?>
            <?php $labels = ['openai'=>'OpenAI','anthropic'=>'Anthropic','gemini'=>'Google Gemini','deepseek'=>'DeepSeek','groq'=>'Groq','mistral'=>'Mistral','openrouter'=>'OpenRouter','xai'=>'xAI (Grok)','together'=>'Together AI']; ?>
            <option value="<?= esc($p) ?>" <?= $values['AI_PROVIDER'] === $p ? 'selected' : '' ?>><?= esc($labels[$p] ?? ucfirst($p)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label for="AI_MODEL">OpenAI model</label>
        <input id="AI_MODEL" name="AI_MODEL" value="<?= esc($values['AI_MODEL']) ?>" />
      </div>
      <div class="full">
        <label for="OPENAI_API_KEY">OpenAI API key</label>
        <input id="OPENAI_API_KEY" name="OPENAI_API_KEY" value="<?= esc($displayValues['OPENAI_API_KEY']) ?>" placeholder="sk-..." />
      </div>
      <div>
        <label for="OPENAI_BASE_URL">OpenAI base URL</label>
        <input id="OPENAI_BASE_URL" name="OPENAI_BASE_URL" value="<?= esc($values['OPENAI_BASE_URL']) ?>" />
      </div>
      <div>
        <label for="ANTHROPIC_MODEL">Anthropic model</label>
        <input id="ANTHROPIC_MODEL" name="ANTHROPIC_MODEL" value="<?= esc($values['ANTHROPIC_MODEL']) ?>" />
      </div>
      <div class="full">
        <label for="ANTHROPIC_API_KEY">Anthropic API key</label>
        <input id="ANTHROPIC_API_KEY" name="ANTHROPIC_API_KEY" value="<?= esc($displayValues['ANTHROPIC_API_KEY']) ?>" placeholder="sk-ant-..." />
      </div>
      <div>
        <label for="GEMINI_MODEL">Gemini model</label>
        <input id="GEMINI_MODEL" name="GEMINI_MODEL" value="<?= esc($values['GEMINI_MODEL']) ?>" />
      </div>
      <div>
        <label for="GEMINI_API_KEY">Gemini API key</label>
        <input id="GEMINI_API_KEY" name="GEMINI_API_KEY" value="<?= esc($displayValues['GEMINI_API_KEY']) ?>" />
      </div>
    </div>

    <h2>Additional AI Providers <small>(optional)</small></h2>
    <div class="grid">
      <div>
        <label for="DEEPSEEK_API_KEY">DeepSeek API key</label>
        <input id="DEEPSEEK_API_KEY" name="DEEPSEEK_API_KEY" value="<?= esc($displayValues['DEEPSEEK_API_KEY']) ?>" placeholder="sk-..." />
      </div>
      <div>
        <label for="DEEPSEEK_MODEL">DeepSeek model</label>
        <input id="DEEPSEEK_MODEL" name="DEEPSEEK_MODEL" value="<?= esc($values['DEEPSEEK_MODEL']) ?>" />
      </div>
      <div>
        <label for="GROQ_API_KEY">Groq API key</label>
        <input id="GROQ_API_KEY" name="GROQ_API_KEY" value="<?= esc($displayValues['GROQ_API_KEY']) ?>" placeholder="gsk_..." />
      </div>
      <div>
        <label for="GROQ_MODEL">Groq model</label>
        <input id="GROQ_MODEL" name="GROQ_MODEL" value="<?= esc($values['GROQ_MODEL']) ?>" />
      </div>
      <div>
        <label for="MISTRAL_API_KEY">Mistral API key</label>
        <input id="MISTRAL_API_KEY" name="MISTRAL_API_KEY" value="<?= esc($displayValues['MISTRAL_API_KEY']) ?>" />
      </div>
      <div>
        <label for="MISTRAL_MODEL">Mistral model</label>
        <input id="MISTRAL_MODEL" name="MISTRAL_MODEL" value="<?= esc($values['MISTRAL_MODEL']) ?>" />
      </div>
      <div>
        <label for="OPENROUTER_API_KEY">OpenRouter API key</label>
        <input id="OPENROUTER_API_KEY" name="OPENROUTER_API_KEY" value="<?= esc($displayValues['OPENROUTER_API_KEY']) ?>" placeholder="sk-or-..." />
      </div>
      <div>
        <label for="OPENROUTER_MODEL">OpenRouter model</label>
        <input id="OPENROUTER_MODEL" name="OPENROUTER_MODEL" value="<?= esc($values['OPENROUTER_MODEL']) ?>" />
      </div>
      <div>
        <label for="XAI_API_KEY">xAI (Grok) API key</label>
        <input id="XAI_API_KEY" name="XAI_API_KEY" value="<?= esc($displayValues['XAI_API_KEY']) ?>" />
      </div>
      <div>
        <label for="XAI_MODEL">xAI model</label>
        <input id="XAI_MODEL" name="XAI_MODEL" value="<?= esc($values['XAI_MODEL']) ?>" />
      </div>
      <div>
        <label for="TOGETHER_API_KEY">Together AI API key</label>
        <input id="TOGETHER_API_KEY" name="TOGETHER_API_KEY" value="<?= esc($displayValues['TOGETHER_API_KEY']) ?>" />
      </div>
      <div>
        <label for="TOGETHER_MODEL">Together AI model</label>
        <input id="TOGETHER_MODEL" name="TOGETHER_MODEL" value="<?= esc($values['TOGETHER_MODEL']) ?>" />
      </div>
    </div>

    <h2>Email (SMTP)</h2>
    <div class="grid">
      <div>
        <label for="SMTP_HOST">SMTP host</label>
        <input id="SMTP_HOST" name="SMTP_HOST" value="<?= esc($values['SMTP_HOST']) ?>" placeholder="smtp.gmail.com" />
      </div>
      <div>
        <label for="SMTP_PORT">SMTP port</label>
        <input id="SMTP_PORT" name="SMTP_PORT" type="number" value="<?= esc($values['SMTP_PORT']) ?>" />
      </div>
      <div>
        <label for="SMTP_USER">SMTP username</label>
        <input id="SMTP_USER" name="SMTP_USER" value="<?= esc($values['SMTP_USER']) ?>" />
      </div>
      <div>
        <label for="SMTP_PASS">SMTP password</label>
        <input id="SMTP_PASS" name="SMTP_PASS" type="password" value="<?= esc($values['SMTP_PASS']) ?>" />
      </div>
      <div>
        <label for="SMTP_FROM">From email</label>
        <input id="SMTP_FROM" name="SMTP_FROM" type="email" value="<?= esc($values['SMTP_FROM']) ?>" placeholder="hello@example.com" />
      </div>
      <div>
        <label for="SMTP_FROM_NAME">From name</label>
        <input id="SMTP_FROM_NAME" name="SMTP_FROM_NAME" value="<?= esc($values['SMTP_FROM_NAME']) ?>" />
      </div>
    </div>

    <h2>Advanced</h2>
    <div class="grid">
      <div>
        <label for="CRON_KEY">Cron key <small>(for automated scheduling)</small></label>
        <input id="CRON_KEY" name="CRON_KEY" value="<?= esc($values['CRON_KEY']) ?>" />
        <small>URL: <code>yourdomain.com/cron.php?key=<?= esc($values['CRON_KEY']) ?></code></small>
      </div>
      <div>
        <label for="MAX_UPLOAD_MB">Max upload size (MB)</label>
        <input id="MAX_UPLOAD_MB" name="MAX_UPLOAD_MB" type="number" value="<?= esc($values['MAX_UPLOAD_MB']) ?>" min="1" max="100" />
      </div>
    </div>

    <button type="submit">Install / Update</button>
  </form>
</div>
</body>
</html>
