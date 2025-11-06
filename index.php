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
        $body = '<div class="error">Command timed out after the allotted period.</div>';
    } elseif ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        $body = '<div class="error">Command failed: ' . $message . '</div>';
    } else {
        $output = htmlspecialchars(trim($result['stdout']), ENT_QUOTES, 'UTF-8');
        $body = '<pre>' . ($output !== '' ? $output : 'No data returned.') . '</pre>';
    }

    return '<section><h2>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h2>' . $body . '</section>';
}

/**
 * Fetch cluster version history and present it as a table.
 */
function renderClusterVersionHistory(): string
{
    $result = runCommand(['oc', 'get', 'clusterversion', 'version', '-o', 'json']);

    if ($result['timedOut']) {
        return '<section><h2>Cluster Version History</h2><div class="error">Command timed out after the allotted period.</div></section>';
    }

    if ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        return '<section><h2>Cluster Version History</h2><div class="error">Unable to retrieve cluster version history: ' . $message . '</div></section>';
    }

    $json = json_decode($result['stdout'], true);
    if (!is_array($json) || !isset($json['status']['history']) || !is_array($json['status']['history'])) {
        return '<section><h2>Cluster Version History</h2><div class="error">Unexpected response format from oc.</div></section>';
    }

    $rows = '';
    foreach ($json['status']['history'] as $entry) {
        if (!is_array($entry)) {
            continue;
        }

        $version = htmlspecialchars((string)($entry['version'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $state = htmlspecialchars((string)($entry['state'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $started = htmlspecialchars((string)($entry['startedTime'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $completed = htmlspecialchars((string)($entry['completionTime'] ?? '—'), ENT_QUOTES, 'UTF-8');

        $rows .= '<tr>'
            . '<td>' . $version . '</td>'
            . '<td>' . $state . '</td>'
            . '<td>' . $started . '</td>'
            . '<td>' . $completed . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="4">No history entries reported.</td></tr>';
    }

    return '<section><h2>Cluster Version History</h2>'
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
        return '<section><h2>Critical Events</h2><div class="error">Command timed out after the allotted period.</div></section>';
    }

    if ($result['exitCode'] !== 0) {
        $message = htmlspecialchars(trim($result['stderr']) ?: 'Unknown error', ENT_QUOTES, 'UTF-8');
        return '<section><h2>Critical Events</h2><div class="error">Unable to retrieve events: ' . $message . '</div></section>';
    }

    $json = json_decode($result['stdout'], true);
    if (!is_array($json) || !isset($json['items']) || !is_array($json['items'])) {
        return '<section><h2>Critical Events</h2><div class="error">Unexpected response format from oc.</div></section>';
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

        $rows .= '<tr>'
            . '<td>' . $namespace . '</td>'
            . '<td>' . $name . '</td>'
            . '<td>' . $type . '</td>'
            . '<td>' . $reason . '</td>'
            . '<td>' . $message . '</td>'
            . '<td>' . $count . '</td>'
            . '<td>' . $lastSeen . '</td>'
            . '</tr>';
    }

    if ($rows === '') {
        $rows = '<tr><td colspan="7">No critical events found.</td></tr>';
    }

    return '<section><h2>Critical Events</h2>'
        . '<div class="table-wrapper">'
        . '<table>'
        . '<thead><tr><th>Namespace</th><th>Name</th><th>Type</th><th>Reason</th><th>Message</th><th>Count</th><th>Last Seen</th></tr></thead>'
        . '<tbody>' . $rows . '</tbody>'
        . '</table>'
        . '</div>'
        . '</section>';
}

$sections = [];
$sections[] = renderCommandSection('Cluster Version', ['oc', 'get', 'clusterversion']);
$sections[] = renderClusterVersionHistory();
$sections[] = renderCommandSection('Node Status', ['oc', 'get', 'nodes']);
$sections[] = renderCommandSection('Cluster Operator Status', ['oc', 'get', 'co']);
$sections[] = renderCommandSection('Node Resource Usage', ['oc', 'adm', 'top', 'nodes']);
$sections[] = renderCommandSection('Ingress Pod Status', ['oc', 'get', 'pods', '-n', 'openshift-ingress']);
$sections[] = renderCommandSection('Ingress Pod Resource Usage', ['oc', 'adm', 'top', 'pods', '-n', 'openshift-ingress']);
$sections[] = renderCommandSection('Monitoring Pod Status', ['oc', 'get', 'pods', '-n', 'openshift-monitoring']);
$sections[] = renderCriticalEvents();

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OpenShift Cluster Health Report</title>
    <style>
        :root {
            color-scheme: light dark;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.5;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: #f5f5f5;
            color: #222;
        }

        body.dark-mode {
            background-color: #111;
            color: #f5f5f5;
        }

        header {
            padding: 1.5rem 2rem;
            background: #007bba;
            color: #fff;
        }

        main {
            padding: 1.5rem 2rem 2rem;
        }

        section {
            margin-bottom: 2rem;
            background: #fff;
            border-radius: 0.5rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.1);
            padding: 1.5rem;
        }

        body.dark-mode section {
            background: #1c1c1c;
            box-shadow: 0 1px 4px rgba(255, 255, 255, 0.08);
        }

        h1 {
            margin: 0;
            font-size: 2rem;
        }

        h2 {
            margin-top: 0;
            font-size: 1.25rem;
        }

        pre {
            overflow-x: auto;
            background: rgba(0, 0, 0, 0.05);
            padding: 1rem;
            border-radius: 0.25rem;
            white-space: pre-wrap;
        }

        body.dark-mode pre {
            background: rgba(255, 255, 255, 0.08);
        }

        .meta {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            opacity: 0.85;
        }

        .error {
            padding: 1rem;
            border-radius: 0.25rem;
            background: #ffefef;
            color: #a80000;
        }

        body.dark-mode .error {
            background: rgba(168, 0, 0, 0.2);
            color: #ff8080;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        th,
        td {
            border: 1px solid rgba(0, 0, 0, 0.1);
            padding: 0.6rem;
            text-align: left;
        }

        body.dark-mode th,
        body.dark-mode td {
            border-color: rgba(255, 255, 255, 0.2);
        }

        th {
            background: rgba(0, 0, 0, 0.05);
            font-weight: 600;
        }

        body.dark-mode th {
            background: rgba(255, 255, 255, 0.08);
        }

        .table-wrapper {
            overflow-x: auto;
        }
    </style>
</head>
<body>
    <header>
        <h1>OpenShift Cluster Health Report</h1>
        <p class="meta">Report generated on <?php echo htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8'); ?></p>
    </header>
    <main>
        <?php echo implode('\n', $sections); ?>
    </main>
    <script>
        (function () {
            if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                document.body.classList.add('dark-mode');
            }
        })();
    </script>
</body>
</html>
