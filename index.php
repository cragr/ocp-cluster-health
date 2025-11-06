<?php
declare(strict_types=1);

/**
 * Executes a command with a timeout and returns stdout/stderr data.
 *
 * @param array<int, string> $command Command to execute, tokenized.
 * @param int $timeoutSeconds Maximum number of seconds to wait for the command.
 *
 * @return array{stdout: string, stderr: string, exitCode: int|null, timedOut: bool}
 */
function runCommand(array $command, int $timeoutSeconds = 15): array
{
    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open(
        $command,
        $descriptorSpec,
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );

    if (!is_resource($process)) {
        return [
            'stdout' => '',
            'stderr' => 'Failed to start process.',
            'exitCode' => null,
            'timedOut' => false,
        ];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $start = time();

    while (true) {
        $status = proc_get_status($process);
        if (!$status['running']) {
            break;
        }

        if ((time() - $start) >= $timeoutSeconds) {
            proc_terminate($process, 9);
            $timedOut = true;
            break;
        }

        $read = [$pipes[1], $pipes[2]];
        $write = [];
        $except = [];

        if (@stream_select($read, $write, $except, 0, 200000) === false) {
            break;
        }

        foreach ($read as $stream) {
            $buffer = stream_get_contents($stream);
            if ($stream === $pipes[1]) {
                $stdout .= $buffer;
            } elseif ($stream === $pipes[2]) {
                $stderr .= $buffer;
            }
        }
    }

    $stdout .= stream_get_contents($pipes[1]);
    $stderr .= stream_get_contents($pipes[2]);

    foreach ($pipes as $pipe) {
        fclose($pipe);
    }

    $exitCode = proc_close($process);

    return [
        'stdout' => $stdout,
        'stderr' => $stderr,
        'exitCode' => $exitCode,
        'timedOut' => $timedOut,
    ];
}

/**
 * Renders a preformatted block for a command output.
 *
 * @param string $title
 * @param array<int, string> $command
 */
function renderCommandSection(string $title, array $command): string
{
    $result = runCommand($command);

    if ($result['timedOut']) {
        $body = '<div class="error" role="alert">Command timed out after the allotted period.</div>';
    } elseif ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        $body = '<div class="error" role="alert">Command failed: ' . $message . '</div>';
    } else {
        $output = htmlspecialchars(trim($result['stdout']), ENT_QUOTES, 'UTF-8');
        $body = '<pre class="code-block">' . ($output !== '' ? $output : 'No data returned.') . '</pre>';
    }

    return '<section class="report-card">'
        . '<div class="section-header">'
        . '<h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>'
        . '</div>'
        . $body
        . '</section>';
}

/**
 * Fetch cluster version history and present it as a table.
 */
function renderClusterVersionHistory(): string
{
    $result = runCommand(['oc', 'get', 'clusterversion', 'version', '-o', 'json']);

    if ($result['timedOut']) {
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Cluster Version History</h2></div>'
            . '<div class="error" role="alert">Command timed out after the allotted period.</div>'
            . '</section>';
    }

    if ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Cluster Version History</h2></div>'
            . '<div class="error" role="alert">Unable to retrieve cluster version history: ' . $message . '</div>'
            . '</section>';
    }

    $json = json_decode($result['stdout'], true);
    if (!is_array($json) || !isset($json['status']['history']) || !is_array($json['status']['history'])) {
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Cluster Version History</h2></div>'
            . '<div class="error" role="alert">Unexpected response format from oc.</div>'
            . '</section>';
    }

    $rows = '';
    foreach ($json['status']['history'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $version = htmlspecialchars((string)($entry['version'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $stateRaw = (string)($entry['state'] ?? 'Unknown');
        $state = htmlspecialchars($stateRaw, ENT_QUOTES, 'UTF-8');
        $started = htmlspecialchars((string)($entry['startedTime'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $completed = htmlspecialchars((string)($entry['completionTime'] ?? '—'), ENT_QUOTES, 'UTF-8');

        $stateClass = 'status-badge status-neutral';
        if (stripos($stateRaw, 'completed') !== false) {
            $stateClass = 'status-badge status-success';
        } elseif (stripos($stateRaw, 'failing') !== false || stripos($stateRaw, 'failed') !== false) {
            $stateClass = 'status-badge status-danger';
        } elseif (stripos($stateRaw, 'partial') !== false || stripos($stateRaw, 'progress') !== false) {
            $stateClass = 'status-badge status-warning';
        }

        $rows .= '<tr>'
            . '<td>' . $version . '</td>'
            . '<td><span class="' . $stateClass . '">' . $state . '</span></td>'
            . '<td>' . $started . '</td>'
            . '<td>' . $completed . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="4">No history entries reported.</td></tr>';
    }

    return '<section class="report-card">'
        . '<div class="section-header"><h2>Cluster Version History</h2></div>'
        . '<div class="table-wrapper">'
        . '<table>'
        . '<thead><tr><th>Version</th><th>State</th><th>Started</th><th>Completed</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</div>'
        . '</section>';
}

/**
 * Render events labelled as critical from the cluster.
 */
function renderCriticalEvents(): string
{
    $result = runCommand(['oc', 'get', 'events', '--all-namespaces', '-o', 'json']);

    if ($result['timedOut']) {
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Critical Events</h2></div>'
            . '<div class="error" role="alert">Command timed out after the allotted period.</div>'
            . '</section>';
    }

    if ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Critical Events</h2></div>'
            . '<div class="error" role="alert">Unable to retrieve events: ' . $message . '</div>'
            . '</section>';
    }

    $json = json_decode($result['stdout'], true);
    if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
        return '<section class="report-card">'
            . '<div class="section-header"><h2>Critical Events</h2></div>'
            . '<div class="error" role="alert">Unexpected response format from oc.</div>'
            . '</section>';
    }

    $rows = '';
    foreach ($json['items'] as $event) {
        if (!is_array($event)) {
            continue;
        }

        $typeRaw = (string)($event['type'] ?? '');
        $reasonRaw = (string)($event['reason'] ?? '');

        $isCritical = stripos($typeRaw, 'critical') !== false || stripos($reasonRaw, 'critical') !== false;
        if (!$isCritical) {
            continue;
        }

        $namespace = htmlspecialchars((string)($event['metadata']['namespace'] ?? 'default'), ENT_QUOTES, 'UTF-8');
        $name = htmlspecialchars((string)($event['metadata']['name'] ?? ''), ENT_QUOTES, 'UTF-8');
        $type = htmlspecialchars($typeRaw !== '' ? $typeRaw : '—', ENT_QUOTES, 'UTF-8');
        $reason = htmlspecialchars($reasonRaw !== '' ? $reasonRaw : '—', ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars((string)($event['message'] ?? ''), ENT_QUOTES, 'UTF-8');
        $count = htmlspecialchars((string)($event['count'] ?? ''), ENT_QUOTES, 'UTF-8');
        $lastSeen = htmlspecialchars((string)($event['lastTimestamp'] ?? $event['eventTime'] ?? '—'), ENT_QUOTES, 'UTF-8');

        $typeClass = 'status-badge status-warning';
        if (stripos($typeRaw, 'error') !== false || stripos($typeRaw, 'critical') !== false) {
            $typeClass = 'status-badge status-danger';
        } elseif (stripos($typeRaw, 'normal') !== false) {
            $typeClass = 'status-badge status-neutral';
        }

        $rows .= '<tr>'
            . '<td>' . $namespace . '</td>'
            . '<td>' . $name . '</td>'
            . '<td><span class="' . $typeClass . '">' . $type . '</span></td>'
            . '<td><span class="status-badge status-accent">' . $reason . '</span></td>'
            . '<td>' . $message . '</td>'
            . '<td>' . $count . '</td>'
            . '<td>' . $lastSeen . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="7">No critical events found.</td></tr>';
    }

    return '<section class="report-card">'
        . '<div class="section-header"><h2>Critical Events</h2></div>'
        . '<div class="table-wrapper">'
        . '<table>'
        . '<thead><tr><th>Namespace</th><th>Name</th><th>Type</th><th>Reason</th><th>Message</th><th>Count</th><th>Last Seen</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</div>'
        . '</section>';
}

/**
 * Returns metadata and render callbacks for each report section.
 *
 * @return array<string, array{title: string, renderer: callable}>
 */
function getSectionDefinitions(): array
{
    return [
        'cluster-version' => [
            'title' => 'Cluster Version',
            'renderer' => function (): string {
                return renderCommandSection('Cluster Version', ['oc', 'get', 'clusterversion']);
            },
        ],
        'cluster-version-history' => [
            'title' => 'Cluster Version History',
            'renderer' => function (): string {
                return renderClusterVersionHistory();
            },
        ],
        'node-status' => [
            'title' => 'Node Status',
            'renderer' => function (): string {
                return renderCommandSection('Node Status', ['oc', 'get', 'nodes']);
            },
        ],
        'cluster-operator-status' => [
            'title' => 'Cluster Operator Status',
            'renderer' => function (): string {
                return renderCommandSection('Cluster Operator Status', ['oc', 'get', 'co']);
            },
        ],
        'node-resource-usage' => [
            'title' => 'Node Resource Usage',
            'renderer' => function (): string {
                return renderCommandSection('Node Resource Usage', ['oc', 'adm', 'top', 'nodes']);
            },
        ],
        'ingress-pod-status' => [
            'title' => 'Ingress Pod Status',
            'renderer' => function (): string {
                return renderCommandSection('Ingress Pod Status', ['oc', 'get', 'pods', '-n', 'openshift-ingress']);
            },
        ],
        'ingress-pod-resource-usage' => [
            'title' => 'Ingress Pod Resource Usage',
            'renderer' => function (): string {
                return renderCommandSection('Ingress Pod Resource Usage', ['oc', 'adm', 'top', 'pods', '-n', 'openshift-ingress']);
            },
        ],
        'monitoring-pod-status' => [
            'title' => 'Monitoring Pod Status',
            'renderer' => function (): string {
                return renderCommandSection('Monitoring Pod Status', ['oc', 'get', 'pods', '-n', 'openshift-monitoring']);
            },
        ],
        'critical-events' => [
            'title' => 'Critical Events',
            'renderer' => function (): string {
                return renderCriticalEvents();
            },
        ],
    ];
}

$sectionDefinitions = getSectionDefinitions();

if (isset($_GET['section'])) {
    $sectionKey = (string)$_GET['section'];

    if (!isset($sectionDefinitions[$sectionKey])) {
        http_response_code(404);
        header('Content-Type: application/json');
        echo json_encode([
            'ok' => false,
            'error' => 'Unknown section requested.',
        ]);
        exit;
    }

    $renderer = $sectionDefinitions[$sectionKey]['renderer'];
    $html = (string)call_user_func($renderer);

    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'html' => $html,
    ]);
    exit;
}

$sectionMeta = [];
foreach ($sectionDefinitions as $id => $definition) {
    $sectionMeta[] = [
        'id' => $id,
        'title' => $definition['title'],
    ];
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenShift Cluster Health Report</title>
    <style>
        :root {
            color-scheme: light dark;
            --osc-font-family: "Red Hat Text", "Segoe UI", system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            --osc-line-height: 1.55;
            --osc-color-body-bg: #f0f0f0; /* pf-v5-theme--color--bg-subtle */
            --osc-color-surface: #ffffff;
            --osc-color-panel-border: #d2d2d2;
            --osc-color-border-subtle: #e0e0e0;
            --osc-color-surface-subtle: #f7f7f7;
            --osc-color-text: #151515;
            --osc-color-text-subtle: #4f5255;
            --osc-color-muted: #6a6e73;
            --osc-color-header-top: #002f5d;
            --osc-color-header-bottom: #004b95;
            --osc-color-primary: #0066cc;
            --osc-color-primary-strong: #004080;
            --osc-color-progress-bg: #dce9f9;
            --osc-color-success: #3e8635;
            --osc-color-warning: #f0ab00;
            --osc-color-danger: #c9190b;
            --osc-color-accent: #009596;
            --osc-border-radius: 12px;
            --osc-border-radius-sm: 8px;
            line-height: var(--osc-line-height);
            font-family: var(--osc-font-family);
        }

        body {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(180deg, rgba(0, 47, 93, 0.05) 0%, rgba(0, 75, 149, 0.04) 22%, var(--osc-color-body-bg) 100%);
            color: var(--osc-color-text);
            font-family: var(--osc-font-family);
        }

        body.dark-mode {
            --osc-color-body-bg: #1f1f1f;
            --osc-color-surface: #151515;
            --osc-color-panel-border: #3c3f42;
            --osc-color-border-subtle: rgba(255, 255, 255, 0.08);
            --osc-color-surface-subtle: rgba(255, 255, 255, 0.05);
            --osc-color-text: #f5f5f5;
            --osc-color-text-subtle: #d2d2d2;
            --osc-color-muted: #b8bbbe;
            --osc-color-progress-bg: rgba(0, 102, 204, 0.22);
            --osc-color-header-top: #001930;
            --osc-color-header-bottom: #003d73;
        }

        header {
            padding: clamp(1.75rem, 4vw, 2.75rem) clamp(1.25rem, 5vw, 3rem);
            background: linear-gradient(130deg, var(--osc-color-header-top), var(--osc-color-header-bottom));
            color: #fff;
            border-bottom: 1px solid rgba(255, 255, 255, 0.16);
            box-shadow: 0 12px 24px rgba(0, 47, 93, 0.18);
        }

        header h1 {
            margin: 0;
            font-size: clamp(1.85rem, 3vw, 2.4rem);
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        .meta {
            margin: 0.5rem 0 0;
            font-size: 0.95rem;
            color: rgba(255, 255, 255, 0.82);
        }

        main {
            padding: clamp(1.25rem, 3vw, 2.5rem);
            max-width: 1160px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 1.1rem;
        }

        .report-card {
            position: relative;
            background: var(--osc-color-surface);
            border-radius: var(--osc-border-radius);
            padding: 1.25rem;
            border: 1px solid var(--osc-color-panel-border);
            box-shadow: 0 1px 2px rgba(3, 3, 3, 0.08);
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            overflow: hidden;
        }

        body.dark-mode .report-card {
            border-color: var(--osc-color-panel-border);
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.45);
        }

        .report-card::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            border-left: 4px solid var(--osc-color-primary);
            opacity: 0.9;
            z-index: 1;
        }

        .report-card > * {
            position: relative;
            z-index: 2;
        }

        .progress-card::before {
            border-left-color: var(--osc-color-accent);
        }

        .progress-card {
            background: linear-gradient(135deg, rgba(0, 102, 204, 0.08), rgba(0, 47, 93, 0.02)), var(--osc-color-surface);
            grid-column: 1 / -1;
        }

        .section-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            gap: 0.75rem;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--osc-color-text);
        }

        .progress {
            width: 100%;
            height: 0.65rem;
            background: var(--osc-color-progress-bg);
            border-radius: 999px;
            margin-bottom: 1.1rem;
            overflow: hidden;
        }

        #progress-text {
            margin: 0;
            color: var(--osc-color-text-subtle);
            font-weight: 500;
            letter-spacing: 0.01em;
        }

        .progress-bar {
            height: 100%;
            width: 0;
            background: linear-gradient(90deg, var(--osc-color-primary), var(--osc-color-primary-strong));
            border-radius: inherit;
            transition: width 0.4s ease;
        }

        .loading {
            position: relative;
        }

        .loading::after {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: linear-gradient(120deg, rgba(0, 102, 204, 0.08), rgba(0, 75, 149, 0.05));
            opacity: 0.6;
            pointer-events: none;
            z-index: 0;
        }

        .loading .loader {
            display: flex;
            align-items: center;
            gap: 1rem;
            color: var(--osc-color-muted);
            font-size: 0.95rem;
            position: relative;
            z-index: 1;
        }

        .loading .loader p {
            margin: 0;
            position: relative;
            z-index: 1;
        }

        .spinner {
            width: 1.5rem;
            height: 1.5rem;
            border: 3px solid rgba(0, 102, 204, 0.15);
            border-top-color: var(--osc-color-primary);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            position: relative;
            z-index: 1;
        }

        body.dark-mode .spinner {
            border-color: rgba(0, 102, 204, 0.35);
            border-top-color: var(--osc-color-primary);
        }

        .code-block {
            overflow-x: auto;
            background: var(--osc-color-surface-subtle);
            border-radius: var(--osc-border-radius-sm);
            padding: 1rem 1.25rem;
            font-size: 0.9rem;
            font-family: "Red Hat Mono", "SFMono-Regular", Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color: var(--osc-color-text);
            border: 1px solid var(--osc-color-border-subtle);
        }

        body.dark-mode .code-block {
            background: rgba(0, 0, 0, 0.35);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .error {
            background: rgba(201, 25, 11, 0.1);
            border-left: 4px solid var(--osc-color-danger);
            border-radius: var(--osc-border-radius-sm);
            padding: 0.85rem 1rem;
            color: #7d0c0c;
            font-weight: 500;
        }

        body.dark-mode .error {
            background: rgba(201, 25, 11, 0.2);
            color: #ffb3b8;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.9rem;
            color: var(--osc-color-text);
            border: 1px solid var(--osc-color-border-subtle);
            border-radius: var(--osc-border-radius-sm);
            overflow: hidden;
        }

        thead tr {
            background: linear-gradient(90deg, rgba(0, 102, 204, 0.16), rgba(0, 128, 128, 0.08));
            color: var(--osc-color-primary-strong);
        }

        th,
        td {
            padding: 0.55rem 0.85rem;
            border-bottom: 1px solid var(--osc-color-border-subtle);
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        tbody tr:nth-child(odd) {
            background: rgba(0, 0, 0, 0.02);
        }

        tbody tr:hover {
            background: rgba(0, 102, 204, 0.1);
        }

        body.dark-mode thead tr {
            background: rgba(0, 102, 204, 0.32);
            color: #e8f2ff;
        }

        body.dark-mode th,
        body.dark-mode td {
            border-bottom-color: rgba(255, 255, 255, 0.08);
        }

        body.dark-mode tbody tr:nth-child(odd) {
            background: rgba(255, 255, 255, 0.04);
        }

        body.dark-mode tbody tr:hover {
            background: rgba(0, 149, 150, 0.3);
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.1rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            text-transform: uppercase;
            background: rgba(0, 0, 0, 0.05);
            color: var(--osc-color-text-subtle);
        }

        .status-success {
            background: rgba(62, 134, 53, 0.16);
            color: var(--osc-color-success);
        }

        .status-warning {
            background: rgba(240, 171, 0, 0.16);
            color: var(--osc-color-warning);
        }

        .status-danger {
            background: rgba(201, 25, 11, 0.16);
            color: var(--osc-color-danger);
        }

        .status-accent {
            background: rgba(0, 149, 150, 0.18);
            color: var(--osc-color-accent);
        }

        .status-neutral {
            background: rgba(0, 102, 204, 0.14);
            color: var(--osc-color-primary);
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>
<body>
    <header>
        <h1>OpenShift Cluster Health Report</h1>
        <p class="meta">Report generated on <?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></p>
    </header>
    <main>
        <section class="report-card progress-card">
            <div class="section-header">
                <h2>Preparing Report</h2>
            </div>
            <div class="progress" aria-live="polite" aria-label="Report progress">
                <div class="progress-bar" id="progress-bar" style="width: 0%" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div>
            </div>
            <p id="progress-text">Starting data collection…</p>
        </section>

        <?php foreach ($sectionMeta as $meta): ?>
            <section id="section-<?php echo htmlspecialchars($meta['id'], ENT_QUOTES, 'UTF-8'); ?>" class="report-card loading">
                <div class="section-header">
                    <h2><?php echo htmlspecialchars($meta['title'], ENT_QUOTES, 'UTF-8'); ?></h2>
                </div>
                <div class="loader">
                    <div class="spinner" role="presentation"></div>
                    <p>Gathering data…</p>
                </div>
            </section>
        <?php endforeach; ?>
    </main>
    <script>
        (function () {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.body.classList.add('dark-mode');
            }
        })();

        document.addEventListener('DOMContentLoaded', function () {
            var sections = <?php echo json_encode($sectionMeta, JSON_UNESCAPED_SLASHES); ?>;
            var progressBar = document.getElementById('progress-bar');
            var progressText = document.getElementById('progress-text');
            var total = sections.length;
            var completed = 0;

            function updateProgress(message) {
                var safeCompleted = Math.min(completed, total);
                var percent = total === 0 ? 100 : Math.round((safeCompleted / total) * 100);
                if (progressBar) {
                    progressBar.style.width = percent + '%';
                    progressBar.setAttribute('aria-valuenow', String(percent));
                }
                if (progressText) {
                    progressText.textContent = message;
                }
            }

            function markSectionError(section, errorMessage) {
                var placeholder = document.getElementById('section-' + section.id);
                if (!placeholder) {
                    return;
                }

                placeholder.classList.remove('loading');
                placeholder.innerHTML = '';

                var header = document.createElement('div');
                header.className = 'section-header';
                var heading = document.createElement('h2');
                heading.textContent = section.title;
                header.appendChild(heading);
                placeholder.appendChild(header);

                var errorBox = document.createElement('div');
                errorBox.className = 'error';
                errorBox.setAttribute('role', 'alert');
                errorBox.textContent = errorMessage;
                placeholder.appendChild(errorBox);
            }

            function markSectionComplete(sectionId, html) {
                var placeholder = document.getElementById('section-' + sectionId);
                if (!placeholder) {
                    return;
                }

                placeholder.outerHTML = html;
            }

            (async function loadSections() {
                for (var i = 0; i < sections.length; i++) {
                    var section = sections[i];
                    updateProgress('Collecting ' + section.title + '…');

                    try {
                        var response = await fetch('?section=' + encodeURIComponent(section.id), {
                            headers: {
                                'Accept': 'application/json'
                            }
                        });

                        if (!response.ok) {
                            throw new Error('Request failed with status ' + response.status);
                        }

                        var payload = await response.json();
                        if (!payload.ok || typeof payload.html !== 'string') {
                            throw new Error(payload.error || 'Unexpected response');
                        }

                        markSectionComplete(section.id, payload.html);
                    } catch (error) {
                        markSectionError(section, error instanceof Error ? error.message : 'Unknown error');
                    }

                    completed += 1;
                    updateProgress(completed === total ? 'Report ready.' : 'Collected ' + section.title + '.');
                }

                if (total === 0) {
                    updateProgress('No sections to load.');
                }
            })();
        });
    </script>
</body>
</html>
