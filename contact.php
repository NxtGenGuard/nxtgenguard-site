<?php
/**
 * NxtGenGuard Contact + Intelligent Intake Page
 * ------------------------------------------------------------
 * Drop this file at the root of your PHP-enabled site as contact.php.
 * It reads URL selections from service pages, displays a polished intake
 * form, provides an optional AI-powered intake assistant, and sends both
 * the NxtGenGuard lead email and the customer confirmation email.
 *
 * Recommended email provider: Resend API, Postmark API, or SendGrid API.
 * Plain PHP mail() is not recommended for DigitalOcean hosting.
 */

declare(strict_types=1);

session_start();

// -----------------------------
// Basic configuration helpers
// -----------------------------

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === '') {
        return $default;
    }
    return $value;
}

function env_bool(string $key, bool $default = false): bool
{
    $value = env_value($key);
    if ($value === null) {
        return $default;
    }
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

function clean_text(mixed $value, int $maxLength = 1200, bool $preserveLines = false): string
{
    if (is_array($value)) {
        $value = implode(', ', array_map(static fn($v) => is_scalar($v) ? (string) $v : '', $value));
    }
    $value = is_scalar($value) ? (string) $value : '';
    $value = str_replace(["\0", "\x0B"], '', $value);
    $value = trim($value);
    if (!$preserveLines) {
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
    } else {
        $value = preg_replace("/\r\n|\r/u", "\n", $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\n{4,}/u', "\n\n\n", $value) ?? $value;
    }
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_ip(): string
{
    $headers = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($headers as $header) {
        $value = $_SERVER[$header] ?? '';
        if ($value) {
            $first = trim(explode(',', $value)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }
    }
    return '0.0.0.0';
}

function ensure_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token(string $token): bool
{
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ----------------------------------------
// Fields carried from all service pages
// ----------------------------------------

$selectionLabels = [
    'service' => 'Service',
    'path' => 'Selected path / service direction',
    'plan' => 'Starting point / plan',
    'budget' => 'Budget or estimate range',
    'demo' => 'Demo or prototype option',
    'demo_fee' => 'Demo fee due today',
    'demo_timeline' => 'Demo delivery timeline',
    'demo_credit' => 'Demo credit policy',
    'addons' => 'Optional add-ons',
    'addon_budgets' => 'Add-on budget notes',
    'review' => 'Review option',
    'review_fee' => 'Review fee',
    'review_timeline' => 'Review timeline',
    'review_credit' => 'Review credit policy',
    'concern' => 'IT/security concern',
    'urgency' => 'Urgency',
    'urgency_fee' => 'Priority or after-hours fee',
    'engagement' => 'Engagement / review depth',
    'first_step_fee' => 'First-step fee',
    'support' => 'Support needs',
    'support_budgets' => 'Support budget notes',
    'support_area' => 'Support area',
    'support_style' => 'Support style',
    'budget_note' => 'Budget note',
    'source_page' => 'Source page',
    'ref' => 'Reference',
    'utm_source' => 'UTM source',
    'utm_medium' => 'UTM medium',
    'utm_campaign' => 'UTM campaign',
];

function incoming_selection_value(string $key): string
{
    $source = $_POST[$key] ?? $_GET[$key] ?? '';
    return clean_text($source, 900, false);
}

function gather_selections(array $selectionLabels): array
{
    $selected = [];
    foreach ($selectionLabels as $key => $label) {
        $value = incoming_selection_value($key);
        if ($value !== '') {
            $selected[$key] = $value;
        }
    }
    if (empty($selected['source_page']) && !empty($_SERVER['HTTP_REFERER'])) {
        $selected['source_page'] = clean_text($_SERVER['HTTP_REFERER'], 900);
    }
    return $selected;
}

function selected_service(array $selected): string
{
    return $selected['service'] ?? 'General NxtGenGuard Request';
}

function service_slug(string $service): string
{
    $s = strtolower($service);
    if (str_contains($s, 'website') || str_contains($s, 'web platform')) return 'websites';
    if (str_contains($s, 'dashboard') || str_contains($s, 'platform')) return 'dashboards';
    if (str_contains($s, 'business system')) return 'systems';
    if (str_contains($s, 'automation') || str_contains($s, 'cloud')) return 'automation';
    if (str_contains($s, 'security') || str_contains($s, 'consulting')) return 'security';
    if (str_contains($s, 'maintenance') || str_contains($s, 'support')) return 'support';
    return 'general';
}

function service_guidance(string $service): array
{
    $slug = service_slug($service);
    return match ($slug) {
        'websites' => [
            'title' => 'Website request details',
            'prompt' => 'Tell us what the website needs to do, what platform you use now if any, page count, ecommerce needs, timeline, and whether you want training or ongoing support.',
            'quick' => ['Website path', 'Pages needed', 'Current platform', 'Ecommerce', 'Training/support'],
        ],
        'dashboards' => [
            'title' => 'Dashboard / platform details',
            'prompt' => 'Tell us the KPIs, user roles, data sources, surveys, forms, charts, tables, admin screens, and whether you want a paid demo before the full build.',
            'quick' => ['KPIs', 'Users/roles', 'Data sources', 'Survey hub', 'Demo option'],
        ],
        'systems' => [
            'title' => 'Business system details',
            'prompt' => 'Tell us the workflow you want organized, who uses it, what is currently manual, what needs tracking, and what should happen after a request is submitted.',
            'quick' => ['Workflow', 'Users', 'Current tools', 'Tracking needs', 'Handoffs'],
        ],
        'automation' => [
            'title' => 'Automation / cloud details',
            'prompt' => 'Tell us the repeated task, trigger, tools to connect, API/cloud requirements, alerts, backup needs, and what should happen automatically.',
            'quick' => ['Trigger', 'Tools/APIs', 'Cloud host', 'Alerts', 'Reports'],
        ],
        'security' => [
            'title' => 'IT / security details',
            'prompt' => 'Tell us what needs attention, who owns or manages the system, how urgent it is, and what you want reviewed first. Do not submit passwords, private keys, or sensitive records.',
            'quick' => ['Concern', 'Ownership', 'Urgency', 'Systems', 'Safe next step'],
        ],
        'support' => [
            'title' => 'Maintenance / support details',
            'prompt' => 'Tell us what needs support: website/system updates, computer/workstation help, data recovery triage, network/Wi-Fi, cameras/LPR, on-site help, remote help, urgency, and hardware details.',
            'quick' => ['Support area', 'Remote/on-site', 'Urgency', 'Hardware', 'Data/camera notes'],
        ],
        default => [
            'title' => 'Request details',
            'prompt' => 'Tell us what you want built, fixed, connected, secured, or improved. Include your timeline, budget range, and what outcome would make this successful.',
            'quick' => ['Goal', 'Timeline', 'Budget', 'Current setup', 'Next step'],
        ],
    };
}

// ----------------------------------------
// Smart assistant endpoint
// ----------------------------------------

function api_post_json(string $url, array $headers, array $payload, int $timeoutSeconds = 20): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'status' => 0, 'body' => 'cURL is not enabled on this server.'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => $timeoutSeconds,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'status' => $status, 'body' => $error ?: 'cURL request failed.'];
    }

    $decoded = json_decode((string) $raw, true);
    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $decoded ?: (string) $raw,
    ];
}

function openai_assistant_reply(string $message, array $context): ?string
{
    $apiKey = env_value('OPENAI_API_KEY');
    if (!$apiKey) {
        return null;
    }

    $model = env_value('OPENAI_MODEL', 'gpt-4.1-mini');
    $system = "You are the NxtGenGuard intake assistant on the contact page. Be concise, professional, and customer-facing. Your job is to help the visitor explain their request clearly before submitting the form. Ask at most two questions. Never ask for passwords, private keys, seed phrases, full payment details, or sensitive records. Do not promise exact pricing or guaranteed security/data recovery results. If the request is urgent, advise submitting the form and avoiding unsafe actions.";
    $contextText = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    $payload = [
        'model' => $model,
        'input' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => "Current request context: {$contextText}\n\nVisitor message: {$message}"],
        ],
        'max_output_tokens' => 300,
    ];

    $result = api_post_json(
        'https://api.openai.com/v1/responses',
        [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        $payload,
        18
    );

    if (!$result['ok'] || !is_array($result['body'])) {
        return null;
    }

    $body = $result['body'];
    if (!empty($body['output_text']) && is_string($body['output_text'])) {
        return clean_text($body['output_text'], 1200, true);
    }

    $chunks = [];
    foreach (($body['output'] ?? []) as $item) {
        foreach (($item['content'] ?? []) as $content) {
            if (isset($content['text']) && is_string($content['text'])) {
                $chunks[] = $content['text'];
            }
        }
    }

    $text = trim(implode("\n", $chunks));
    return $text !== '' ? clean_text($text, 1200, true) : null;
}

function local_assistant_reply(string $message, array $context): string
{
    $service = clean_text($context['service'] ?? '', 200);
    $slug = service_slug($service . ' ' . $message);
    $lower = strtolower($message);

    if (preg_match('/(password\s*[:=]|private key|seed phrase|api[_\s-]?key\s*[:=]|secret\s*[:=])/i', $message)) {
        return "Please remove any passwords, private keys, API keys, seed phrases, or sensitive records before submitting. We can confirm a safer way to review private technical information after your request is received.";
    }

    if ($slug === 'security' || preg_match('/(hacked|breach|compromised|ransomware|suspicious|locked out|phishing)/i', $lower)) {
        return "For IT or security help, start with what feels wrong, which system/account/device is involved, who owns it, and how urgent it is. Do not send passwords or private records here. If something may be compromised, avoid making risky changes until the request is reviewed.";
    }

    if (preg_match('/(data recovery|recover|deleted|drive|ssd|hard drive|clicking|not recognized|usb|memory card)/i', $lower)) {
        return "For data recovery triage, include the device type, what happened, whether it still powers on, any clicking/noise, and what files matter most. Stop using the device if the data is important because continued use can reduce recovery chances.";
    }

    return match ($slug) {
        'websites' => "For a website request, include the platform you use now, pages needed, whether ecommerce is involved, who will edit the site, and whether you want training or monthly care after launch.",
        'dashboards' => "For a dashboard or portal request, include the KPIs, dashboards/screens needed, users/roles, data sources, surveys/forms, and whether you want a paid demo before the full build.",
        'systems' => "For a business system, describe the current manual workflow, who submits requests, who manages them, what needs tracking, and what status or notifications should happen.",
        'automation' => "For automation/cloud work, list the tools involved, the trigger, the action you want automated, any cloud/API requirements, and what should happen when the automation fails.",
        'support' => "For maintenance/support, include whether it is remote or on-site, what device/system/network/camera/data issue is involved, urgency, and whether hardware or third-party accounts may be needed.",
        default => "Tell us the goal, what exists today, what needs to change, your timeline, and any budget or paid first-step option already selected. We will use that to recommend the safest next step.",
    };
}

if (($_GET['ajax'] ?? '') === 'assistant') {
    $raw = file_get_contents('php://input') ?: '{}';
    $payload = json_decode($raw, true) ?: [];
    $message = clean_text($payload['message'] ?? '', 1600, true);
    $context = is_array($payload['context'] ?? null) ? $payload['context'] : [];

    if ($message === '') {
        json_response(['reply' => 'Send a short description of what you need help with, and I will help organize it for the request form.']);
    }

    $reply = openai_assistant_reply($message, $context) ?: local_assistant_reply($message, $context);
    json_response([
        'reply' => $reply,
        'suggestions' => [
            'Add timeline and urgency',
            'Add budget or selected option',
            'Add current tools/systems',
        ],
    ]);
}

// ----------------------------------------
// Lead scoring and email building
// ----------------------------------------

function generate_request_id(): string
{
    return 'NGG-' . gmdate('ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
}

function lead_score(array $posted, array $selected): array
{
    $score = 0;
    $service = selected_service($selected);
    $message = $posted['message'] ?? '';
    $timeline = strtolower($posted['timeline'] ?? '');
    $budget = strtolower(($selected['budget'] ?? '') . ' ' . ($posted['budget_context'] ?? '') . ' ' . ($selected['demo_fee'] ?? '') . ' ' . ($selected['review_fee'] ?? '') . ' ' . ($selected['first_step_fee'] ?? ''));
    $urgency = strtolower(($selected['urgency'] ?? '') . ' ' . $timeline);

    if ($service !== 'General NxtGenGuard Request') $score += 18;
    if (!empty($posted['phone'])) $score += 10;
    if (!empty($posted['business'])) $score += 8;
    if (strlen($message) > 140) $score += 12;
    if (preg_match('/(asap|urgent|after-hours|this week|today)/i', $urgency)) $score += 18;
    if (preg_match('/(\$|1000|2500|5000|7500|10000|15000|25000|35000|monthly|retainer)/i', $budget)) $score += 20;
    if (!empty($selected['demo']) || !empty($selected['review']) || !empty($selected['first_step_fee'])) $score += 12;
    if (!empty($selected['addons']) || !empty($selected['support'])) $score += 8;
    if (preg_match('/(security|breach|data recovery|camera|lpr|dashboard|portal|ecommerce|api|automation)/i', $service . ' ' . $message)) $score += 8;

    $score = min(100, $score);
    $label = $score >= 75 ? 'Priority qualified request' : ($score >= 50 ? 'Discovery-ready request' : 'Early exploration request');
    return ['score' => $score, 'label' => $label];
}

function risk_notes(array $posted, array $selected): array
{
    $text = strtolower(implode(' ', array_merge($posted, $selected)));
    $notes = [];

    if (preg_match('/(data recovery|deleted data|clicking|not recognized|hard drive|ssd|usb|memory card)/i', $text)) {
        $notes[] = 'Data recovery/data retrieval triage: remind the customer to stop using the device if the data is important.';
    }
    if (preg_match('/(hacked|breach|compromised|ransomware|phishing|suspicious|locked out)/i', $text)) {
        $notes[] = 'Potential urgent security concern: confirm authorization and safe next step before asking for system details.';
    }
    if (preg_match('/(camera|lpr|license plate|nvr|dvr|access point|network|wifi|wi-fi|workstation)/i', $text)) {
        $notes[] = 'Field/device scope may include hardware, travel, cabling, mounts, storage, licensing, or third-party services.';
    }
    if (!empty($selected['demo_fee']) || !empty($selected['review_fee']) || !empty($selected['first_step_fee'])) {
        $notes[] = 'Paid first step selected: confirm scope and send invoice/payment link before work starts.';
    }
    if (!empty($selected['urgency_fee'])) {
        $notes[] = 'Urgency fee selected: clarify that this is a scheduling/priority fee, not the full service price.';
    }

    return $notes;
}

function html_table(array $rows): string
{
    $html = '<table role="presentation" cellspacing="0" cellpadding="0" style="width:100%;border-collapse:collapse">';
    foreach ($rows as $label => $value) {
        if ((string) $value === '') continue;
        $html .= '<tr>';
        $html .= '<td style="padding:9px 0;border-bottom:1px solid #e6f1fb;color:#5f7288;width:34%;font-weight:700">' . e($label) . '</td>';
        $html .= '<td style="padding:9px 0;border-bottom:1px solid #e6f1fb;color:#071827;font-weight:700">' . nl2br(e($value)) . '</td>';
        $html .= '</tr>';
    }
    $html .= '</table>';
    return $html;
}

function text_rows(array $rows): string
{
    $out = [];
    foreach ($rows as $label => $value) {
        if ((string) $value !== '') {
            $out[] = $label . ': ' . $value;
        }
    }
    return implode("\n", $out);
}

function build_email_bodies(string $requestId, array $posted, array $selected, array $selectionLabels): array
{
    $score = lead_score($posted, $selected);
    $notes = risk_notes($posted, $selected);
    $service = selected_service($selected);

    $customerRows = [
        'Request ID' => $requestId,
        'Name' => $posted['name'] ?? '',
        'Business' => $posted['business'] ?? '',
        'Email' => $posted['email'] ?? '',
        'Phone' => $posted['phone'] ?? '',
        'Preferred contact' => $posted['preferred_contact'] ?? '',
        'Best time' => $posted['best_time'] ?? '',
        'Timeline' => $posted['timeline'] ?? '',
        'Current website/system link' => $posted['current_link'] ?? '',
        'Budget note' => $posted['budget_context'] ?? '',
    ];

    $selectedRows = [];
    foreach ($selected as $key => $value) {
        $selectedRows[$selectionLabels[$key] ?? $key] = $value;
    }

    $plain = "New NxtGenGuard request\n\n";
    $plain .= text_rows($customerRows) . "\n\n";
    $plain .= "Selected options\n" . text_rows($selectedRows) . "\n\n";
    $plain .= "Lead score: {$score['score']}/100 ({$score['label']})\n";
    if ($notes) {
        $plain .= "\nInternal notes\n- " . implode("\n- ", $notes) . "\n";
    }
    $plain .= "\nMessage\n" . ($posted['message'] ?? '') . "\n";

    $html = '<div style="font-family:Inter,Arial,sans-serif;background:#f8fcff;padding:24px;color:#071827">';
    $html .= '<div style="max-width:720px;margin:0 auto;background:#ffffff;border:1px solid #dceefc;border-radius:24px;overflow:hidden">';
    $html .= '<div style="padding:26px 28px;background:linear-gradient(135deg,#0e7fe4,#25b6ef,#14b8a6);color:#fff">';
    $html .= '<h1 style="margin:0;font-size:26px;letter-spacing:-.04em">New NxtGenGuard request</h1>';
    $html .= '<p style="margin:8px 0 0;opacity:.92">' . e($service) . ' · ' . e($requestId) . '</p>';
    $html .= '</div><div style="padding:26px 28px">';
    $html .= '<p style="margin:0 0 18px;color:#23445e"><strong>Lead score:</strong> ' . e((string) $score['score']) . '/100 · ' . e($score['label']) . '</p>';
    $html .= '<h2 style="margin:0 0 10px;font-size:18px">Customer</h2>' . html_table($customerRows);
    $html .= '<h2 style="margin:26px 0 10px;font-size:18px">Selected request options</h2>' . html_table($selectedRows);
    if ($notes) {
        $html .= '<h2 style="margin:26px 0 10px;font-size:18px">Internal notes</h2><ul style="padding-left:20px;color:#23445e;line-height:1.6">';
        foreach ($notes as $note) $html .= '<li>' . e($note) . '</li>';
        $html .= '</ul>';
    }
    $html .= '<h2 style="margin:26px 0 10px;font-size:18px">Message</h2>';
    $html .= '<div style="white-space:pre-wrap;background:#f4fbff;border:1px solid #dceefc;border-radius:18px;padding:16px;line-height:1.6;color:#071827">' . e($posted['message'] ?? '') . '</div>';
    $html .= '<p style="margin:22px 0 0;color:#5f7288;font-size:13px">Submitted from IP ' . e(request_ip()) . ' · ' . e($_SERVER['HTTP_USER_AGENT'] ?? '') . '</p>';
    $html .= '</div></div></div>';

    $confirmPlain = "Hi " . ($posted['name'] ?? 'there') . ",\n\nWe received your NxtGenGuard request.\n\nRequest ID: {$requestId}\nService: {$service}\n\nWe will review your selected options, timeline, and message before confirming the safest next step. No payment was collected on the contact page. If a paid demo, review, urgency fee, or support item is needed, we will confirm the scope first and send an invoice/payment link before work starts.\n\nPlease do not reply with passwords, private keys, seed phrases, or sensitive records. We will confirm a safer way to review private technical information if it is needed.\n\nNxtGenGuard";

    $confirmHtml = '<div style="font-family:Inter,Arial,sans-serif;background:#f8fcff;padding:24px;color:#071827">';
    $confirmHtml .= '<div style="max-width:680px;margin:0 auto;background:#fff;border:1px solid #dceefc;border-radius:24px;overflow:hidden">';
    $confirmHtml .= '<div style="padding:26px 28px;background:linear-gradient(135deg,#0e7fe4,#25b6ef,#14b8a6);color:#fff"><h1 style="margin:0;font-size:26px;letter-spacing:-.04em">Request received</h1><p style="margin:8px 0 0;opacity:.92">' . e($requestId) . '</p></div>';
    $confirmHtml .= '<div style="padding:26px 28px;line-height:1.7;color:#23445e">';
    $confirmHtml .= '<p>Hi ' . e($posted['name'] ?? 'there') . ',</p><p>We received your NxtGenGuard request for <strong>' . e($service) . '</strong>. We will review your selected options, timeline, and message before confirming the safest next step.</p>';
    $confirmHtml .= '<p>No payment was collected on the contact page. If a paid demo, review, urgency fee, or support item is needed, we will confirm the scope first and send an invoice/payment link before work starts.</p>';
    $confirmHtml .= '<p style="padding:14px 16px;border-radius:16px;background:#f4fbff;border:1px solid #dceefc"><strong>Security reminder:</strong> Please do not send passwords, private keys, seed phrases, full payment details, or sensitive records by email.</p>';
    $confirmHtml .= '<p>NxtGenGuard</p></div></div></div>';

    return [
        'plain' => $plain,
        'html' => $html,
        'confirm_plain' => $confirmPlain,
        'confirm_html' => $confirmHtml,
        'score' => $score,
    ];
}

function send_email(string|array $to, string $subject, string $html, string $text, ?string $replyTo = null): array
{
    $fromEmail = env_value('CONTACT_FROM_EMAIL', 'requests@nxtgenguard.com');
    $fromName = env_value('CONTACT_FROM_NAME', 'NxtGenGuard');
    $provider = 'none';

    if ($apiKey = env_value('RESEND_API_KEY')) {
        $provider = 'resend';
        $payload = [
            'from' => $fromName . ' <' . $fromEmail . '>',
            'to' => is_array($to) ? array_values($to) : [$to],
            'subject' => $subject,
            'html' => $html,
            'text' => $text,
        ];
        if ($replyTo) $payload['reply_to'] = $replyTo;
        $result = api_post_json('https://api.resend.com/emails', [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], $payload, 20);
        return ['ok' => $result['ok'], 'provider' => $provider, 'detail' => $result['body'], 'status' => $result['status']];
    }

    if ($token = env_value('POSTMARK_SERVER_TOKEN')) {
        $provider = 'postmark';
        $payload = [
            'From' => $fromName . ' <' . $fromEmail . '>',
            'To' => is_array($to) ? implode(',', $to) : $to,
            'Subject' => $subject,
            'HtmlBody' => $html,
            'TextBody' => $text,
            'MessageStream' => env_value('POSTMARK_MESSAGE_STREAM', 'outbound'),
        ];
        if ($replyTo) $payload['ReplyTo'] = $replyTo;
        $result = api_post_json('https://api.postmarkapp.com/email', [
            'X-Postmark-Server-Token: ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $payload, 20);
        return ['ok' => $result['ok'], 'provider' => $provider, 'detail' => $result['body'], 'status' => $result['status']];
    }

    if ($apiKey = env_value('SENDGRID_API_KEY')) {
        $provider = 'sendgrid';
        $toEmails = is_array($to) ? $to : [$to];
        $payload = [
            'personalizations' => [[
                'to' => array_map(static fn($email) => ['email' => $email], $toEmails),
            ]],
            'from' => ['email' => $fromEmail, 'name' => $fromName],
            'subject' => $subject,
            'content' => [
                ['type' => 'text/plain', 'value' => $text],
                ['type' => 'text/html', 'value' => $html],
            ],
        ];
        if ($replyTo) $payload['reply_to'] = ['email' => $replyTo];
        $result = api_post_json('https://api.sendgrid.com/v3/mail/send', [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ], $payload, 20);
        return ['ok' => $result['ok'], 'provider' => $provider, 'detail' => $result['body'], 'status' => $result['status']];
    }

    if (env_bool('CONTACT_DEBUG_MODE', false)) {
        return ['ok' => true, 'provider' => 'debug', 'detail' => 'CONTACT_DEBUG_MODE=true; email not actually sent.', 'status' => 200];
    }

    return ['ok' => false, 'provider' => $provider, 'detail' => 'No email provider configured. Set RESEND_API_KEY, POSTMARK_SERVER_TOKEN, or SENDGRID_API_KEY.', 'status' => 0];
}

function rate_limit_check(): ?string
{
    $key = sha1(request_ip() . '|' . ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $file = sys_get_temp_dir() . '/nxtgenguard-contact-' . $key . '.json';
    $now = time();
    $window = 3600;
    $limit = (int) env_value('CONTACT_RATE_LIMIT_PER_HOUR', '6');
    $hits = [];

    if (is_file($file)) {
        $hits = json_decode((string) file_get_contents($file), true) ?: [];
        $hits = array_values(array_filter($hits, static fn($t) => is_int($t) && $t > $now - $window));
    }

    if (count($hits) >= $limit) {
        return 'Too many requests were submitted from this connection. Please wait and try again later.';
    }

    $hits[] = $now;
    @file_put_contents($file, json_encode($hits));
    return null;
}

function detect_sensitive_submission(string $message): bool
{
    return (bool) preg_match('/(BEGIN\s+(RSA|DSA|EC|OPENSSH|PGP)?\s*PRIVATE\s+KEY|password\s*[:=]|seed phrase\s*[:=]|api[_\s-]?key\s*[:=]|secret[_\s-]?key\s*[:=]|ssh-rsa\s+[A-Za-z0-9+\/]{80,})/i', $message);
}

$selected = gather_selections($selectionLabels);
$service = selected_service($selected);
$guide = service_guidance($service);
$csrf = ensure_csrf_token();
$errors = [];
$success = false;
$receipt = $_SESSION['last_receipt'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['ajax'] ?? '') === '') {
    $selected = gather_selections($selectionLabels);
    $service = selected_service($selected);
    $guide = service_guidance($service);

    // Honeypot field: real visitors never see this.
    if (!empty($_POST['website_url'] ?? '')) {
        http_response_code(204);
        exit;
    }

    $started = (int) ($_POST['form_started'] ?? 0);
    if ($started > 0 && time() - $started < 2) {
        $errors[] = 'Please take a moment to review the form before submitting.';
    }

    if (!verify_csrf_token(clean_text($_POST['csrf_token'] ?? '', 100))) {
        $errors[] = 'The form session expired. Please refresh the page and try again.';
    }

    if ($rateError = rate_limit_check()) {
        $errors[] = $rateError;
    }

    $posted = [
        'name' => clean_text($_POST['name'] ?? '', 140),
        'business' => clean_text($_POST['business'] ?? '', 160),
        'email' => clean_text($_POST['email'] ?? '', 180),
        'phone' => clean_text($_POST['phone'] ?? '', 80),
        'preferred_contact' => clean_text($_POST['preferred_contact'] ?? '', 80),
        'best_time' => clean_text($_POST['best_time'] ?? '', 120),
        'timeline' => clean_text($_POST['timeline'] ?? '', 120),
        'current_link' => clean_text($_POST['current_link'] ?? '', 500),
        'budget_context' => clean_text($_POST['budget_context'] ?? '', 300),
        'message' => clean_text($_POST['message'] ?? '', 5000, true),
    ];

    if ($posted['name'] === '') $errors[] = 'Please enter your name.';
    if (!filter_var($posted['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
    if ($posted['message'] === '' || strlen($posted['message']) < 20) $errors[] = 'Please add a few details about what you need help with.';
    if (empty($_POST['consent'])) $errors[] = 'Please confirm the safe contact and no-payment notice.';
    if (detect_sensitive_submission($posted['message'])) $errors[] = 'Please remove passwords, private keys, API keys, seed phrases, or sensitive records before submitting.';

    if (!$errors) {
        $requestId = generate_request_id();
        $bodies = build_email_bodies($requestId, $posted, $selected, $selectionLabels);
        $to = env_value('CONTACT_TO_EMAIL', 'hello@nxtgenguard.com');
        $internalSubject = '[NxtGenGuard] ' . selected_service($selected) . ' request from ' . $posted['name'];
        $replyTo = $posted['email'];

        $internalSend = send_email($to, $internalSubject, $bodies['html'], $bodies['plain'], $replyTo);

        $customerSend = ['ok' => true, 'provider' => 'skipped'];
        if (env_bool('SEND_CUSTOMER_CONFIRMATION', true)) {
            $customerSend = send_email($posted['email'], 'We received your NxtGenGuard request · ' . $requestId, $bodies['confirm_html'], $bodies['confirm_plain'], $to);
        }

        if ($internalSend['ok']) {
            $_SESSION['last_receipt'] = [
                'id' => $requestId,
                'service' => selected_service($selected),
                'score' => $bodies['score']['label'],
                'provider' => $internalSend['provider'],
                'time' => gmdate('Y-m-d H:i:s') . ' UTC',
                'customer_confirmation' => $customerSend['ok'] ? 'sent' : 'not sent',
            ];
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: contact.php?sent=1');
            exit;
        }

        $errors[] = 'The form was valid, but the email provider is not configured or did not accept the message. Provider: ' . e((string) $internalSend['provider']) . '. Check your DigitalOcean environment variables and email provider domain setup.';
        if (env_bool('SHOW_EMAIL_DEBUG', false)) {
            $errors[] = 'Email debug: ' . clean_text(json_encode($internalSend['detail'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 900, true);
        }
    }
}

// Values for sticky form after validation errors.
function form_value(string $key): string
{
    return clean_text($_POST[$key] ?? '', $key === 'message' ? 5000 : 500, $key === 'message');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>NxtGenGuard | Contact</title>
  <meta name="description" content="Start a NxtGenGuard request for websites, dashboards, business systems, automation, cloud, IT/security consulting, maintenance, support, data recovery triage, cameras, networks, and workstation help." />
  <style>
    :root {
      --bg: #f8fcff;
      --bg-2: #edf8ff;
      --panel: rgba(255, 255, 255, 0.82);
      --panel-2: rgba(255, 255, 255, 0.95);
      --line: rgba(18, 72, 116, 0.13);
      --line-strong: rgba(29, 142, 223, 0.28);
      --text: #071827;
      --muted: #5f7288;
      --blue: #1689e8;
      --blue-2: #67cdfb;
      --teal: #14b8a6;
      --accent: #ffb86b;
      --danger: #ca3f3f;
      --success: #0f8f76;
      --radius: 28px;
      --shadow: 0 24px 70px rgba(20, 75, 120, 0.16);
      --soft-shadow: 0 16px 40px rgba(33, 91, 139, 0.11);
      --max: 1240px;
      --nav-h: 84px;
      --ease: cubic-bezier(0.22, 1, 0.36, 1);
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: Inter, Arial, Helvetica, sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at 8% 8%, rgba(103, 205, 251, 0.34), transparent 28%),
        radial-gradient(circle at 92% 12%, rgba(255, 184, 107, 0.20), transparent 24%),
        radial-gradient(circle at 48% 96%, rgba(20, 184, 166, 0.16), transparent 30%),
        linear-gradient(180deg, #fbfdff 0%, #edf8ff 48%, #ffffff 100%);
      overflow-x: hidden;
    }
    body.nav-open { overflow: hidden; }
    a { color: inherit; text-decoration: none; }
    img { display: block; max-width: 100%; }
    .container { width: min(var(--max), calc(100% - 32px)); margin: 0 auto; }
    .btn, button.btn {
      display: inline-flex; align-items: center; justify-content: center;
      min-height: 54px; padding: 0 22px; border-radius: 15px; font-weight: 850;
      border: 1px solid transparent; cursor: pointer;
      transition: transform .28s var(--ease), box-shadow .28s var(--ease), border-color .28s var(--ease), background .28s var(--ease), opacity .28s var(--ease);
      font-family: inherit; font-size: 0.98rem;
    }
    .btn:hover { transform: translateY(-2px); }
    .btn-primary { color:#fff; background:linear-gradient(135deg,#0e7fe4 0%,#25b6ef 58%,#14b8a6 100%); border-color:rgba(17,142,216,.35); box-shadow:0 18px 38px rgba(22,137,232,.23); }
    .btn-secondary { background:rgba(255,255,255,.72); border-color:rgba(18,72,116,.13); color:#12324b; box-shadow:0 12px 30px rgba(33,91,139,.08); }
    .btn-small { min-height: 40px; padding: 0 13px; border-radius: 999px; font-size: .82rem; }
    .btn:disabled { opacity:.55; cursor:not-allowed; transform:none; }

    header { position: sticky; top:0; z-index:100; border-bottom:1px solid rgba(18,72,116,.10); background:rgba(255,255,255,.78); backdrop-filter:blur(20px); box-shadow:0 12px 36px rgba(18,72,116,.06); }
    .nav-wrap { min-height:var(--nav-h); display:flex; align-items:center; justify-content:space-between; gap:22px; }
    .brand { display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
    .brand img { width:auto; height:62px; max-width:178px; object-fit:contain; border-radius:16px; }
    .desktop-nav { display:flex; align-items:center; gap:28px; }
    .desktop-nav a, .nav-drop-toggle { color:#193c58; font-weight:780; background:none; border:none; padding:0; font-size:.97rem; cursor:pointer; }
    .desktop-nav a:hover, .desktop-nav a.active, .nav-drop-toggle:hover, .nav-drop-toggle.active { color:#0a80df; }
    .nav-drop { position:relative; padding-bottom:14px; margin-bottom:-14px; }
    .nav-drop::after { content:""; position:absolute; left:0; right:0; top:100%; height:16px; }
    .nav-drop-menu { position:absolute; top:calc(100% + 8px); left:0; min-width:300px; padding:12px; border-radius:20px; border:1px solid rgba(18,72,116,.12); background:rgba(255,255,255,.96); box-shadow:0 24px 60px rgba(18,72,116,.18); opacity:0; visibility:hidden; transform:translateY(10px); pointer-events:none; transition:opacity .2s ease, transform .2s ease, visibility .2s ease; }
    .nav-drop-menu::before { content:""; position:absolute; left:0; right:0; top:-14px; height:14px; }
    .nav-drop:hover .nav-drop-menu, .nav-drop:focus-within .nav-drop-menu { opacity:1; visibility:visible; transform:translateY(0); pointer-events:auto; }
    .nav-drop-menu a { display:block; padding:12px 14px; border-radius:12px; }
    .nav-drop-menu a:hover { background:rgba(22,137,232,.08); }
    .menu-toggle { display:none; width:48px; height:48px; border-radius:14px; border:1px solid rgba(18,72,116,.14); background:rgba(255,255,255,.78); color:#12324b; font-size:1.15rem; box-shadow:0 10px 28px rgba(33,91,139,.08); }
    .mobile-nav { display:none; padding:0 0 18px; }
    .mobile-nav.open { display:block; }
    .mobile-panel { border:1px solid rgba(18,72,116,.13); border-radius:24px; background:rgba(255,255,255,.95); padding:14px; box-shadow:var(--shadow); }
    .mobile-panel a, .mobile-panel summary { display:block; padding:14px 12px; border-radius:12px; font-weight:780; list-style:none; cursor:pointer; color:#193c58; }
    .mobile-panel a:hover, .mobile-panel summary:hover, .mobile-panel details[open] summary { background:rgba(22,137,232,.08); color:#0a80df; }
    .mobile-submenu { padding:4px 0 0 10px; }
    .mobile-submenu a { color:var(--muted); font-weight:650; }
    .mobile-panel summary::-webkit-details-marker { display:none; }

    .contact-hero { position:relative; overflow:hidden; isolation:isolate; padding:80px 0 34px; border-bottom:1px solid rgba(18,72,116,.10); }
    .contact-hero::before { content:""; position:absolute; inset:0; z-index:-2; background: radial-gradient(circle at 78% 20%, rgba(103,205,251,.30), transparent 30%), radial-gradient(circle at 18% 88%, rgba(255,184,107,.14), transparent 28%), linear-gradient(180deg, rgba(250,253,255,.96), rgba(238,249,255,.88)); }
    .contact-hero::after { content:""; position:absolute; inset:0; z-index:-1; background-image: linear-gradient(rgba(18,72,116,.055) 1px, transparent 1px), linear-gradient(90deg, rgba(18,72,116,.055) 1px, transparent 1px); background-size:42px 42px; mask-image:linear-gradient(180deg, black 0%, transparent 88%); opacity:.55; pointer-events:none; }
    .hero-grid { display:grid; grid-template-columns: 1.04fr .86fr; gap:34px; align-items:center; }
    .hero-copy { display:grid; gap:22px; }
    .back-link { color:#0a80df; font-weight:850; width:fit-content; }
    .hero-copy h1 { margin:0; max-width:850px; font-size:clamp(2.7rem, 7vw, 6rem); line-height:.9; letter-spacing:-.078em; color:var(--text); }
    .hero-copy p { margin:0; max-width:760px; color:var(--muted); font-size:clamp(1rem,1.4vw,1.14rem); line-height:1.82; }
    .hero-actions { display:flex; flex-wrap:wrap; gap:12px; }
    .packet-card { position:relative; overflow:hidden; border-radius:32px; border:1px solid rgba(18,72,116,.13); background:rgba(255,255,255,.78); box-shadow:var(--shadow); padding:26px; }
    .packet-card::before { content:""; position:absolute; inset:0; background: radial-gradient(circle at top right, rgba(103,205,251,.22), transparent 34%), radial-gradient(circle at bottom left, rgba(20,184,166,.12), transparent 26%); pointer-events:none; }
    .packet-card > * { position:relative; }
    .packet-top { display:flex; align-items:flex-start; justify-content:space-between; gap:16px; margin-bottom:18px; }
    .packet-top h2 { margin:0; font-size:1.4rem; line-height:1; letter-spacing:-.045em; }
    .status-pill { display:inline-flex; align-items:center; gap:8px; min-height:36px; padding:0 12px; border-radius:999px; border:1px solid rgba(20,184,166,.18); color:#086b5b; background:rgba(255,255,255,.68); font-weight:900; font-size:.8rem; white-space:nowrap; }
    .status-pill::before { content:""; width:8px; height:8px; border-radius:50%; background:var(--teal); box-shadow:0 0 16px rgba(20,184,166,.58); }
    .packet-list { display:grid; gap:10px; margin:0; padding:0; list-style:none; }
    .packet-list li { display:grid; grid-template-columns: 150px 1fr; gap:12px; align-items:start; padding:12px; border-radius:16px; border:1px solid rgba(18,72,116,.10); background:rgba(255,255,255,.62); }
    .packet-list strong { color:#193c58; font-size:.82rem; }
    .packet-list span { color:#071827; font-weight:800; line-height:1.45; overflow-wrap:anywhere; }
    .empty-packet { color:var(--muted); line-height:1.7; margin:0; }
    .chips { display:flex; flex-wrap:wrap; gap:8px; margin-top:18px; }
    .chip { display:inline-flex; min-height:34px; align-items:center; padding:0 11px; border-radius:999px; border:1px solid rgba(22,137,232,.14); background:rgba(255,255,255,.72); color:#193c58; font-size:.78rem; font-weight:850; }

    .contact-section { padding:64px 0 88px; }
    .contact-layout { display:grid; grid-template-columns: minmax(0, 1.06fr) minmax(340px, .72fr); gap:24px; align-items:start; }
    .panel { border-radius:32px; border:1px solid rgba(18,72,116,.12); background:rgba(255,255,255,.84); box-shadow:var(--soft-shadow); padding:28px; backdrop-filter:blur(16px); }
    .panel h2 { margin:0 0 12px; font-size:clamp(1.6rem, 3vw, 2.45rem); line-height:1; letter-spacing:-.055em; }
    .panel p { color:var(--muted); line-height:1.74; }
    .notice { border-radius:20px; border:1px solid rgba(18,72,116,.11); background:rgba(244,251,255,.78); padding:16px 18px; color:#23445e; line-height:1.65; }
    .notice strong { color:#071827; }
    .notice.danger { border-color:rgba(202,63,63,.25); background:rgba(255,245,245,.88); color:#6e2626; }
    .notice.success { border-color:rgba(20,184,166,.25); background:rgba(236,255,251,.88); color:#075f52; }
    .form-grid { display:grid; grid-template-columns:1fr 1fr; gap:16px; margin-top:22px; }
    .field { display:grid; gap:8px; }
    .field.full { grid-column:1 / -1; }
    label { color:#193c58; font-weight:900; font-size:.88rem; }
    input, select, textarea { width:100%; border-radius:16px; border:1px solid rgba(18,72,116,.13); background:rgba(255,255,255,.82); padding:14px 15px; color:var(--text); font:inherit; outline:none; box-shadow:0 10px 24px rgba(33,91,139,.05); transition:border-color .2s ease, box-shadow .2s ease, background .2s ease; }
    input:focus, select:focus, textarea:focus { border-color:rgba(22,137,232,.42); box-shadow:0 0 0 4px rgba(103,205,251,.18); background:#fff; }
    textarea { min-height:190px; resize:vertical; line-height:1.6; }
    .helper { margin:0; color:#6b7c8e; font-size:.86rem; line-height:1.5; }
    .hidden-hp { position:absolute !important; left:-10000px !important; width:1px !important; height:1px !important; overflow:hidden !important; }
    .checkbox-line { display:flex; align-items:flex-start; gap:10px; padding:14px 0 0; color:#23445e; line-height:1.55; font-size:.94rem; }
    .checkbox-line input { width:auto; margin-top:4px; box-shadow:none; }
    .submit-row { display:flex; flex-wrap:wrap; gap:12px; align-items:center; margin-top:22px; }
    .submit-row .helper { max-width:560px; }

    .assistant-card { position:sticky; top:calc(var(--nav-h) + 20px); display:grid; gap:16px; }
    .assistant-shell { overflow:hidden; border-radius:28px; border:1px solid rgba(18,72,116,.12); background:rgba(255,255,255,.9); box-shadow:var(--soft-shadow); }
    .assistant-head { padding:20px 20px 16px; border-bottom:1px solid rgba(18,72,116,.10); background:radial-gradient(circle at top right, rgba(103,205,251,.20), transparent 30%), rgba(255,255,255,.78); }
    .assistant-head h3 { margin:0; font-size:1.25rem; letter-spacing:-.035em; }
    .assistant-head p { margin:7px 0 0; color:var(--muted); line-height:1.55; font-size:.92rem; }
    .chat-log { height:330px; overflow:auto; padding:18px; display:grid; gap:12px; align-content:start; }
    .msg { max-width:92%; padding:12px 14px; border-radius:18px; line-height:1.55; font-size:.92rem; }
    .msg.bot { justify-self:start; background:#f1f9ff; border:1px solid rgba(22,137,232,.12); color:#1d3b53; border-bottom-left-radius:6px; }
    .msg.user { justify-self:end; background:linear-gradient(135deg,#0e7fe4,#14b8a6); color:#fff; border-bottom-right-radius:6px; }
    .chat-controls { padding:14px; border-top:1px solid rgba(18,72,116,.10); display:grid; gap:10px; }
    .chat-controls textarea { min-height:82px; border-radius:18px; }
    .quick-replies { display:flex; flex-wrap:wrap; gap:8px; }
    .quick-replies button { border:1px solid rgba(22,137,232,.14); background:rgba(255,255,255,.72); color:#193c58; border-radius:999px; min-height:34px; padding:0 11px; font-weight:850; cursor:pointer; }
    .smart-summary { display:grid; gap:10px; margin-top:16px; }
    .smart-summary div { padding:12px 14px; border-radius:16px; border:1px solid rgba(18,72,116,.10); background:rgba(255,255,255,.68); }
    .smart-summary strong { display:block; color:#193c58; font-size:.82rem; margin-bottom:4px; }
    .smart-summary span { color:#071827; font-weight:800; overflow-wrap:anywhere; }

    .site-footer { position:relative; padding:0 0 48px; overflow:hidden; }
    .site-footer::before { content:""; position:absolute; left:0; right:0; bottom:0; height:280px; background:radial-gradient(circle at 18% 20%, rgba(103,205,251,.20), transparent 28%), radial-gradient(circle at 84% 72%, rgba(255,184,107,.15), transparent 30%); pointer-events:none; opacity:.86; }
    .footer-shell { position:relative; overflow:hidden; border-radius:34px; border:1px solid rgba(18,72,116,.13); background:radial-gradient(circle at top left, rgba(103,205,251,.18), transparent 30%), radial-gradient(circle at top right, rgba(255,184,107,.12), transparent 24%), linear-gradient(180deg, rgba(255,255,255,.92), rgba(247,252,255,.94)); box-shadow:0 26px 70px rgba(20,75,120,.13); padding:34px; isolation:isolate; }
    .footer-shell::before { content:""; position:absolute; inset:0; background-image:linear-gradient(rgba(18,72,116,.055) 1px, transparent 1px), linear-gradient(90deg, rgba(18,72,116,.055) 1px, transparent 1px); background-size:38px 38px; opacity:.26; mask-image:radial-gradient(circle at top center, black 0%, transparent 76%); pointer-events:none; z-index:-1; }
    .footer-grid { display:grid; grid-template-columns:1.35fr .75fr 1fr; gap:26px; align-items:start; }
    .footer-brand { display:inline-flex; align-items:center; gap:12px; font-weight:900; letter-spacing:-.035em; font-size:1.08rem; color:#071827; }
    .footer-brand img { width:auto; height:48px; max-width:150px; object-fit:contain; border-radius:12px; }
    .footer-brand-block p { margin:16px 0 0; max-width:410px; color:var(--muted); line-height:1.78; font-size:.96rem; }
    .footer-badges { display:flex; flex-wrap:wrap; gap:9px; margin-top:18px; }
    .footer-badges span { display:inline-flex; align-items:center; min-height:36px; padding:0 12px; border-radius:999px; border:1px solid rgba(18,72,116,.12); background:rgba(255,255,255,.72); color:#193c58; font-weight:820; font-size:.78rem; }
    .footer-col h4 { margin:0 0 14px; font-size:.78rem; letter-spacing:.18em; text-transform:uppercase; color:rgba(25,60,88,.62); }
    .footer-links-list { display:grid; gap:10px; }
    .footer-links-list a { width:fit-content; color:#193c58; opacity:.86; font-weight:760; line-height:1.44; transition:opacity .22s ease, transform .22s ease, color .22s ease; }
    .footer-links-list a:hover { opacity:1; color:#0a80df; transform:translateX(3px); }
    .footer-bottom { width:100%; margin-top:34px; padding-top:20px; border-top:1px solid rgba(18,72,116,.12); display:flex; align-items:center; justify-content:center; gap:14px; flex-wrap:wrap; color:rgba(25,60,88,.70); font-size:.92rem; text-align:center; }
    .footer-bottom span { display:block; width:100%; }
    .footer-bottom strong { color:#071827; }

    @media (max-width:1080px) { .hero-grid, .contact-layout { grid-template-columns:1fr; } .assistant-card { position:relative; top:auto; } .footer-grid { grid-template-columns:1fr 1fr; } }
    @media (max-width:860px) { .desktop-nav { display:none; } .menu-toggle { display:inline-flex; align-items:center; justify-content:center; } }
    @media (max-width:680px) { .container { width:min(var(--max), calc(100% - 22px)); } .contact-hero { padding:58px 0 30px; } .packet-card, .panel, .assistant-shell, .footer-shell { border-radius:24px; } .panel, .packet-card, .footer-shell { padding:22px 18px; } .form-grid { grid-template-columns:1fr; } .packet-list li { grid-template-columns:1fr; gap:4px; } .btn, .submit-row .btn, .hero-actions .btn { width:100%; } .footer-grid { grid-template-columns:1fr; gap:24px; } .footer-links-list a { width:100%; } .chat-log { height:300px; } }
  </style>
</head>
<body>
  <div class="site ready" id="siteRoot">
    <header>
      <div class="container nav-wrap">
        <a class="brand" href="index.html" aria-label="NxtGenGuard home">
          <img src="assets/images/logo/logo-dark.jpg" alt="NxtGenGuard logo" />
        </a>
        <nav class="desktop-nav" aria-label="Primary">
          <a href="index.html">Home</a>
          <div class="nav-drop">
            <a class="nav-drop-toggle" href="services.html">Services ▾</a>
            <div class="nav-drop-menu">
              <a href="services.html">All Services</a>
              <a href="enterprise-websites.html">Websites &amp; Web Platforms</a>
              <a href="platforms-dashboards.html">Platforms &amp; Dashboards</a>
              <a href="business-systems.html">Business Systems</a>
              <a href="automation-cloud.html">Automation &amp; Cloud</a>
              <a href="it-security-consulting.html">IT &amp; Security Consulting</a>
              <a href="maintenance-support.html">Maintenance &amp; Support</a>
            </div>
          </div>
          <a href="work.html">Work</a>
          <a href="about.html">About</a>
          <a class="active" href="contact.php">Contact</a>
        </nav>
        <button class="menu-toggle" type="button" aria-label="Open menu" onclick="toggleMenu()">☰</button>
      </div>
      <div class="container mobile-nav" id="mobileNav">
        <div class="mobile-panel">
          <a href="index.html" onclick="closeMenu()">Home</a>
          <details>
            <summary>Services</summary>
            <div class="mobile-submenu">
              <a href="services.html" onclick="closeMenu()">All Services</a>
              <a href="enterprise-websites.html" onclick="closeMenu()">Websites &amp; Web Platforms</a>
              <a href="platforms-dashboards.html" onclick="closeMenu()">Platforms &amp; Dashboards</a>
              <a href="business-systems.html" onclick="closeMenu()">Business Systems</a>
              <a href="automation-cloud.html" onclick="closeMenu()">Automation &amp; Cloud</a>
              <a href="it-security-consulting.html" onclick="closeMenu()">IT &amp; Security Consulting</a>
              <a href="maintenance-support.html" onclick="closeMenu()">Maintenance &amp; Support</a>
            </div>
          </details>
          <a href="work.html" onclick="closeMenu()">Work</a>
          <a href="about.html" onclick="closeMenu()">About</a>
          <a href="contact.php" onclick="closeMenu()">Contact</a>
        </div>
      </div>
    </header>

    <main>
      <section class="contact-hero" aria-labelledby="page-title">
        <div class="container hero-grid">
          <div class="hero-copy">
            <a class="back-link" href="services.html">← Back to Services</a>
            <h1 id="page-title">Start your NxtGenGuard request</h1>
            <p>Tell us what you want built, fixed, connected, secured, recovered, or supported. Your selected service options are carried into this form so we can respond with the right next step.</p>
            <div class="hero-actions">
              <a class="btn btn-primary" href="#contact-form">Complete request</a>
              <a class="btn btn-secondary" href="#intake-assistant">Use intake assistant</a>
            </div>
          </div>

          <aside class="packet-card" aria-label="Selected request packet">
            <div class="packet-top">
              <div>
                <h2>Request packet</h2>
                <p class="empty-packet" style="margin-top:8px">Selections from the service page appear here before you submit.</p>
              </div>
              <span class="status-pill">No checkout</span>
            </div>
            <?php if (!empty($selected)): ?>
              <ul class="packet-list" id="heroPacketList">
                <?php foreach ($selected as $key => $value): ?>
                  <li><strong><?= e($selectionLabels[$key] ?? $key) ?></strong><span><?= e($value) ?></span></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="empty-packet" id="heroPacketEmpty">No service options were selected yet. You can still submit a general request.</p>
            <?php endif; ?>
            <div class="chips" aria-label="Request types">
              <span class="chip">Websites</span><span class="chip">Dashboards</span><span class="chip">Automation</span><span class="chip">Security</span><span class="chip">Maintenance</span><span class="chip">Data recovery triage</span><span class="chip">Cameras/LPR</span><span class="chip">Workstations</span>
            </div>
          </aside>
        </div>
      </section>

      <section class="contact-section" aria-label="Contact form and assistant">
        <div class="container contact-layout">
          <section class="panel" id="contact-form">
            <?php if (($_GET['sent'] ?? '') === '1' && is_array($receipt)): ?>
              <div class="notice success" role="status">
                <strong>Request received.</strong><br />
                Your request ID is <?= e($receipt['id'] ?? '') ?>. We received the <?= e($receipt['service'] ?? 'NxtGenGuard') ?> request and will review the details before confirming next steps. No payment was collected on this page.
              </div>
            <?php endif; ?>

            <?php if (!empty($errors)): ?>
              <div class="notice danger" role="alert">
                <strong>Please review the form.</strong>
                <ul style="margin:10px 0 0;padding-left:20px">
                  <?php foreach ($errors as $error): ?>
                    <li><?= e($error) ?></li>
                  <?php endforeach; ?>
                </ul>
              </div>
            <?php endif; ?>

            <h2><?= e($guide['title']) ?></h2>
            <p><?= e($guide['prompt']) ?></p>
            <div class="notice">
              <strong>No payment is collected on this page.</strong> If a paid demo, review, urgency fee, support visit, or first-step option is selected, we confirm the scope with you first and send an invoice/payment link before work begins.
            </div>

            <form method="post" action="contact.php" novalidate>
              <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>" />
              <input type="hidden" name="form_started" value="<?= e((string) time()) ?>" />
              <div class="hidden-hp" aria-hidden="true">
                <label>Leave this field empty <input type="text" name="website_url" tabindex="-1" autocomplete="off" /></label>
              </div>
              <?php foreach ($selectionLabels as $key => $label): ?>
                <input type="hidden" name="<?= e($key) ?>" value="<?= e($selected[$key] ?? '') ?>" data-selection-label="<?= e($label) ?>" />
              <?php endforeach; ?>

              <div class="form-grid">
                <div class="field">
                  <label for="name">Your name *</label>
                  <input id="name" name="name" autocomplete="name" required value="<?= e(form_value('name')) ?>" />
                </div>
                <div class="field">
                  <label for="business">Business / organization</label>
                  <input id="business" name="business" autocomplete="organization" value="<?= e(form_value('business')) ?>" />
                </div>
                <div class="field">
                  <label for="email">Email *</label>
                  <input id="email" name="email" type="email" autocomplete="email" required value="<?= e(form_value('email')) ?>" />
                </div>
                <div class="field">
                  <label for="phone">Phone</label>
                  <input id="phone" name="phone" autocomplete="tel" value="<?= e(form_value('phone')) ?>" />
                </div>
                <div class="field">
                  <label for="preferred_contact">Preferred contact</label>
                  <select id="preferred_contact" name="preferred_contact">
                    <?php $pc = form_value('preferred_contact'); ?>
                    <?php foreach (['Email', 'Phone call', 'Text first', 'Zoom / video call'] as $option): ?>
                      <option value="<?= e($option) ?>" <?= $pc === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="field">
                  <label for="best_time">Best time to reach you</label>
                  <input id="best_time" name="best_time" placeholder="Example: weekdays after 3 PM" value="<?= e(form_value('best_time')) ?>" />
                </div>
                <div class="field">
                  <label for="timeline">Timeline</label>
                  <?php $tl = form_value('timeline'); ?>
                  <select id="timeline" name="timeline">
                    <?php foreach (['Not sure yet', 'ASAP / urgent', 'This week', 'This month', 'Next 1–3 months', 'Planning ahead'] as $option): ?>
                      <option value="<?= e($option) ?>" <?= $tl === $option ? 'selected' : '' ?>><?= e($option) ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="field">
                  <label for="current_link">Current website / system link</label>
                  <input id="current_link" name="current_link" type="url" placeholder="https://" value="<?= e(form_value('current_link')) ?>" />
                </div>
                <div class="field full">
                  <label for="budget_context">Budget, paid first step, or billing note</label>
                  <input id="budget_context" name="budget_context" placeholder="Example: selected $1,500 demo, $10k–$15k build range, urgent support, monthly care, etc." value="<?= e(form_value('budget_context')) ?>" />
                </div>
                <div class="field full">
                  <label for="message">What do you need help with? *</label>
                  <textarea id="message" name="message" required placeholder="<?= e($guide['prompt']) ?>"><?= e(form_value('message')) ?></textarea>
                  <p class="helper">Do not submit passwords, private keys, seed phrases, full payment details, or sensitive records. We will confirm a safer way to review private information if needed.</p>
                </div>
              </div>

              <label class="checkbox-line">
                <input type="checkbox" name="consent" value="1" <?= !empty($_POST['consent']) ? 'checked' : '' ?> required />
                <span>I understand this form starts a request only. No payment is collected here, and I will not submit passwords, private keys, seed phrases, full payment details, or sensitive records through this form.</span>
              </label>

              <div class="submit-row">
                <button class="btn btn-primary" type="submit">Send request to NxtGenGuard</button>
                <p class="helper">After submission, NxtGenGuard reviews your request packet and follows up with the safest next step, proposal, invoice/payment link, or support path when applicable.</p>
              </div>
            </form>
          </section>

          <aside class="assistant-card" id="intake-assistant" aria-label="NxtGenGuard intake assistant">
            <div class="assistant-shell">
              <div class="assistant-head">
                <h3>NxtGenGuard Intake Assistant</h3>
                <p>Use this helper to organize your request before you submit. It can help you describe your scope, urgency, selected options, and next-step needs.</p>
              </div>
              <div class="chat-log" id="chatLog" aria-live="polite">
                <div class="msg bot">Hi — describe what you need help with, and I’ll help turn it into a clearer request for the form.</div>
              </div>
              <div class="chat-controls">
                <div class="quick-replies">
                  <?php foreach ($guide['quick'] as $q): ?>
                    <button type="button" onclick="quickAsk('<?= e($q) ?>')"><?= e($q) ?></button>
                  <?php endforeach; ?>
                </div>
                <textarea id="assistantInput" placeholder="Ask the assistant how to explain your request..."></textarea>
                <button class="btn btn-secondary" type="button" onclick="sendAssistantMessage()">Ask assistant</button>
                <button class="btn btn-primary" type="button" onclick="addAssistantToForm()">Add assistant notes to form</button>
              </div>
            </div>

            <div class="panel" style="border-radius:28px">
              <h2 style="font-size:1.35rem">Live request summary</h2>
              <p class="helper">This updates while you type so you can see what will be included in your request email.</p>
              <div class="smart-summary" id="smartSummary">
                <div><strong>Selected service</strong><span><?= e($service) ?></span></div>
                <div><strong>Timeline</strong><span id="sumTimeline">Not selected yet</span></div>
                <div><strong>Budget / fee note</strong><span id="sumBudget"><?= e($selected['budget'] ?? $selected['demo_fee'] ?? $selected['review_fee'] ?? 'Not selected yet') ?></span></div>
                <div><strong>Message strength</strong><span id="sumStrength">Add details to improve the request.</span></div>
              </div>
            </div>
          </aside>
        </div>
      </section>
    </main>

    <footer class="site-footer" aria-label="NxtGenGuard footer">
      <div class="container">
        <div class="footer-shell">
          <div class="footer-grid">
            <div class="footer-brand-block">
              <a class="footer-brand" href="index.html" aria-label="NxtGenGuard home">
                <img src="assets/images/logo/logo-dark.jpg" alt="NxtGenGuard logo" />
                <span>NxtGenGuard</span>
              </a>
              <p>Websites, platforms, dashboards, systems, automation, cloud, field support, data recovery triage, and IT/security guidance built with clean structure and a guarded mindset.</p>
              <div class="footer-badges" aria-label="NxtGenGuard highlights">
                <span>Established 2017</span>
                <span>Request-based service</span>
                <span>Secure by design</span>
              </div>
            </div>

            <nav class="footer-col" aria-label="Footer navigation">
              <h4>Navigate</h4>
              <div class="footer-links-list">
                <a href="index.html">Home</a>
                <a href="services.html">Services</a>
                <a href="work.html">Work</a>
                <a href="about.html">About</a>
                <a href="contact.php">Contact</a>
              </div>
            </nav>

            <nav class="footer-col" aria-label="Footer service links">
              <h4>Services</h4>
              <div class="footer-links-list">
                <a href="enterprise-websites.html">Websites &amp; Web Platforms</a>
                <a href="platforms-dashboards.html">Platforms &amp; Dashboards</a>
                <a href="business-systems.html">Business Systems</a>
                <a href="automation-cloud.html">Automation &amp; Cloud</a>
                <a href="it-security-consulting.html">IT &amp; Security Consulting</a>
                <a href="maintenance-support.html">Maintenance &amp; Support</a>
              </div>
            </nav>
          </div>
          <div class="footer-bottom">
            <span><strong>© NxtGenGuard</strong> · Established 2017 · All rights reserved.</span>
          </div>
        </div>
      </div>
    </footer>
  </div>

  <script>
    const mobileNav = document.getElementById('mobileNav');
    function toggleMenu() { mobileNav.classList.toggle('open'); document.body.classList.toggle('nav-open'); }
    function closeMenu() { mobileNav.classList.remove('open'); document.body.classList.remove('nav-open'); }

    const selected = <?= json_encode($selected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    const selectionLabels = <?= json_encode($selectionLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    let lastAssistantReply = '';

    const messageField = document.getElementById('message');
    const timelineField = document.getElementById('timeline');
    const budgetField = document.getElementById('budget_context');
    const sumTimeline = document.getElementById('sumTimeline');
    const sumBudget = document.getElementById('sumBudget');
    const sumStrength = document.getElementById('sumStrength');
    const assistantInput = document.getElementById('assistantInput');
    const chatLog = document.getElementById('chatLog');

    function updateSummary() {
      const msg = (messageField.value || '').trim();
      const timeline = timelineField.value || 'Not selected yet';
      const budget = (budgetField.value || selected.budget || selected.demo_fee || selected.review_fee || selected.first_step_fee || selected.urgency_fee || '').trim();
      sumTimeline.textContent = timeline;
      sumBudget.textContent = budget || 'Not selected yet';
      let score = 0;
      if (msg.length > 80) score += 1;
      if (msg.length > 220) score += 1;
      if (budget) score += 1;
      if (timeline && timeline !== 'Not sure yet') score += 1;
      if (document.getElementById('phone').value.trim()) score += 1;
      const labels = ['Add details to improve the request.', 'Basic details started.', 'Good request detail.', 'Strong request detail.', 'Very clear request packet.', 'Ready to submit.'];
      sumStrength.textContent = labels[Math.min(score, labels.length - 1)];
    }

    ['input', 'change'].forEach(evt => {
      messageField.addEventListener(evt, updateSummary);
      timelineField.addEventListener(evt, updateSummary);
      budgetField.addEventListener(evt, updateSummary);
      document.getElementById('phone').addEventListener(evt, updateSummary);
    });
    updateSummary();

    function appendMessage(kind, text) {
      const div = document.createElement('div');
      div.className = 'msg ' + kind;
      div.textContent = text;
      chatLog.appendChild(div);
      chatLog.scrollTop = chatLog.scrollHeight;
    }

    function quickAsk(text) {
      assistantInput.value = text + ': ';
      assistantInput.focus();
    }

    async function sendAssistantMessage() {
      const message = assistantInput.value.trim();
      if (!message) return;
      appendMessage('user', message);
      assistantInput.value = '';
      appendMessage('bot', 'Organizing that now...');
      const thinking = chatLog.lastElementChild;
      try {
        const context = Object.assign({}, selected, {
          timeline: timelineField.value,
          budget_context: budgetField.value,
          current_message: messageField.value
        });
        const res = await fetch('contact.php?ajax=assistant', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message, context })
        });
        const data = await res.json();
        lastAssistantReply = data.reply || 'Add your goal, timeline, budget range, current setup, and desired next step to the form.';
        thinking.textContent = lastAssistantReply;
      } catch (err) {
        lastAssistantReply = 'Add your goal, current setup, timeline, budget or selected option, and what would make this request successful.';
        thinking.textContent = lastAssistantReply;
      }
    }

    function addAssistantToForm() {
      if (!lastAssistantReply) {
        lastAssistantReply = 'Goal:\nCurrent setup:\nTimeline:\nBudget or selected option:\nImportant details:';
      }
      const current = messageField.value.trim();
      const addition = 'Assistant notes:\n' + lastAssistantReply;
      messageField.value = current ? current + '\n\n' + addition : addition;
      messageField.focus();
      updateSummary();
    }

    assistantInput.addEventListener('keydown', function(event) {
      if ((event.metaKey || event.ctrlKey) && event.key === 'Enter') {
        sendAssistantMessage();
      }
    });
  </script>
</body>
</html>
