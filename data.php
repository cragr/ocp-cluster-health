<?php
/**
 * Data API endpoint for progressive section loading
 * Returns JSON data for individual sections
 */

header('Content-Type: application/json');

// Helper function to parse and colorize status
function getStatusBadge($status) {
    $status = strtolower(trim($status));
    $statusMap = [
        'ready' => 'status-ready',
        'available' => 'status-available',
        'running' => 'status-running',
        'completed' => 'status-completed',
        'true' => 'status-true',
        'notready' => 'status-notready',
        'degraded' => 'status-degraded',
        'progressing' => 'status-progressing',
        'false' => 'status-false',
        'failed' => 'status-failed',
        'error' => 'status-error',
        'critical' => 'status-critical',
    ];

    $class = $statusMap[$status] ?? 'status-badge';
    return "<span class='status-badge $class'>" . htmlspecialchars($status) . "</span>";
}

// Helper function for cluster operator status badges
function getOperatorStatusBadge($value, $column) {
    $value = strtolower(trim($value));

    // Available: True = Green, False = Red
    if ($column === 'available') {
        $class = ($value === 'true') ? 'status-available' : 'status-failed';
    }
    // Progressing: False = Green, True = Yellow
    elseif ($column === 'progressing') {
        $class = ($value === 'false') ? 'status-ready' : 'status-progressing';
    }
    // Degraded: False = Green, True = Red
    elseif ($column === 'degraded') {
        $class = ($value === 'false') ? 'status-ready' : 'status-failed';
    }
    else {
        $class = 'status-badge';
    }

    return "<span class='status-badge $class'>" . htmlspecialchars($value) . "</span>";
}

// Helper function for event type badges
function getEventTypeBadge($type) {
    $type = trim($type);
    $typeLower = strtolower($type);

    if ($typeLower === 'warning') {
        $class = 'status-progressing'; // Yellow
    } elseif ($typeLower === 'critical') {
        $class = 'status-failed'; // Red
    } else {
        $class = 'status-badge';
    }

    return "<span class='status-badge $class'>" . htmlspecialchars($type) . "</span>";
}

// Helper function for pod status badges
function getPodStatusBadge($status) {
    $status = trim($status);
    $statusLower = strtolower($status);

    // Green: Running and Completed
    if ($statusLower === 'running' || $statusLower === 'completed') {
        $class = 'status-ready';
    }
    // Yellow: Pending
    elseif ($statusLower === 'pending') {
        $class = 'status-progressing';
    }
    // Red: Failed, CrashLoopBackOff, Error
    elseif ($statusLower === 'failed' || $statusLower === 'crashloopbackoff' || $statusLower === 'error') {
        $class = 'status-failed';
    }
    else {
        $class = 'status-badge';
    }

    return "<span class='status-badge $class'>" . htmlspecialchars($status) . "</span>";
}

// Helper function to create a table from CLI output
function createTable($output, $hasStatus = false) {
    if (empty(trim($output))) {
        return "<div class='alert alert-info'>No data available</div>";
    }

    $lines = explode("\n", trim($output));
    if (count($lines) < 2) {
        return "<pre class='raw-output'>" . htmlspecialchars($output) . "</pre>";
    }

    $header = array_shift($lines);
    $headers = preg_split('/\s{2,}/', trim($header));

    $table = "<table class='data-table'><thead><tr>";
    foreach ($headers as $h) {
        $table .= "<th>" . htmlspecialchars($h) . "</th>";
    }
    $table .= "</tr></thead><tbody>";

    foreach ($lines as $line) {
        if (empty(trim($line))) continue;

        $cells = preg_split('/\s{2,}/', trim($line));
        $table .= "<tr>";

        foreach ($cells as $index => $cell) {
            if ($hasStatus && $index === 1) {
                $table .= "<td>" . getStatusBadge($cell) . "</td>";
            } else {
                $table .= "<td>" . htmlspecialchars($cell) . "</td>";
            }
        }

        $table .= "</tr>";
    }

    $table .= "</tbody></table>";
    return $table;
}

// Get the requested section
$section = isset($_GET['section']) ? $_GET['section'] : '';

$response = [
    'success' => false,
    'section' => $section,
    'html' => '',
    'error' => null
];

try {
    switch ($section) {
        case 'cluster-status':
            $cv_output = shell_exec('oc get clusterversion');
            if (!empty(trim($cv_output))) {
                $lines = explode("\n", trim($cv_output));
                $header = array_shift($lines);

                $html = "<table class='data-table'>";
                $html .= "<thead><tr><th>Name</th><th>Version</th><th>Available</th><th>Progressing</th><th>Since</th><th>Status</th></tr></thead>";
                $html .= "<tbody>";

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    $parts = preg_split('/\s+/', trim($line), 6);
                    if (count($parts) >= 4) {
                        $html .= "<tr>";
                        $html .= "<td>" . htmlspecialchars($parts[0]) . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[1] ?? '') . "</td>";
                        $html .= "<td>" . getOperatorStatusBadge($parts[2] ?? '', 'available') . "</td>";
                        $html .= "<td>" . getOperatorStatusBadge($parts[3] ?? '', 'progressing') . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[4] ?? '') . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[5] ?? '') . "</td>";
                        $html .= "</tr>";
                    }
                }

                $html .= "</tbody></table>";
                $response['html'] = $html;
            } else {
                $response['html'] = "<div class='alert alert-info'>No cluster version data available</div>";
            }
            $response['success'] = true;
            break;

        case 'upgrade-history':
            $cluster_version_output = shell_exec('oc get clusterversion version -o json | jq -r \'.status.history[] | "\(.version),\(.state),\(.startedTime),\(.completionTime)"\'');

            if ($cluster_version_output) {
                $html = "<table class='data-table'>";
                $html .= "<thead><tr>";
                $html .= "<th>Version</th><th>State</th><th>Started Time</th><th>Completed Time</th>";
                $html .= "</tr></thead><tbody>";

                $rows = explode("\n", trim($cluster_version_output));
                foreach ($rows as $row) {
                    if (!empty($row)) {
                        $fields = explode(",", $row);
                        if (count($fields) >= 4) {
                            $html .= "<tr>";
                            $html .= "<td>" . htmlspecialchars($fields[0]) . "</td>";
                            $html .= "<td>" . getStatusBadge($fields[1]) . "</td>";
                            $html .= "<td>" . htmlspecialchars($fields[2]) . "</td>";
                            $html .= "<td>" . htmlspecialchars($fields[3]) . "</td>";
                            $html .= "</tr>";
                        }
                    }
                }

                $html .= "</tbody></table>";
                $response['html'] = $html;
            } else {
                $response['html'] = "<div class='alert alert-warning'>Failed to fetch cluster version information.</div>";
            }
            $response['success'] = true;
            break;

        case 'nodes':
            $output = shell_exec('oc get nodes');
            $response['html'] = createTable($output, true);
            $response['success'] = true;
            break;

        case 'cluster-operators':
            $co_output = shell_exec('oc get co');
            if (!empty(trim($co_output))) {
                $lines = explode("\n", trim($co_output));
                $header = array_shift($lines);

                $html = "<table class='data-table'>";
                $html .= "<thead><tr><th>Name</th><th>Version</th><th>Available</th><th>Progressing</th><th>Degraded</th><th>Since</th><th>Message</th></tr></thead>";
                $html .= "<tbody>";

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    $parts = preg_split('/\s+/', trim($line), 7);
                    if (count($parts) >= 5) {
                        $html .= "<tr>";
                        $html .= "<td>" . htmlspecialchars($parts[0]) . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[1] ?? '') . "</td>";
                        $html .= "<td>" . getOperatorStatusBadge($parts[2] ?? '', 'available') . "</td>";
                        $html .= "<td>" . getOperatorStatusBadge($parts[3] ?? '', 'progressing') . "</td>";
                        $html .= "<td>" . getOperatorStatusBadge($parts[4] ?? '', 'degraded') . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[5] ?? '') . "</td>";
                        $html .= "<td>" . htmlspecialchars($parts[6] ?? '') . "</td>";
                        $html .= "</tr>";
                    }
                }

                $html .= "</tbody></table>";
                $response['html'] = $html;
            } else {
                $response['html'] = "<div class='alert alert-info'>No cluster operator data available</div>";
            }
            $response['success'] = true;
            break;

        case 'node-utilization':
            $output = shell_exec('oc adm top nodes');
            $response['html'] = createTable($output);
            $response['success'] = true;
            break;

        case 'monitoring-stack':
            $output = shell_exec('oc get pods -n openshift-monitoring');
            if (!empty(trim($output))) {
                $lines = explode("\n", trim($output));
                $header = array_shift($lines);

                $html = "<table class='data-table'>";
                $html .= "<thead><tr><th>Name</th><th>Ready</th><th>Status</th><th>Restarts</th><th>Age</th></tr></thead>";
                $html .= "<tbody>";

                foreach ($lines as $line) {
                    if (empty(trim($line))) continue;

                    // Parse pod line - split by whitespace, limit to 5 parts
                    $parts = preg_split('/\s+/', trim($line), 5);

                    if (count($parts) >= 4) {
                        $html .= "<tr>";
                        $html .= "<td>" . htmlspecialchars($parts[0]) . "</td>"; // Name
                        $html .= "<td>" . htmlspecialchars($parts[1]) . "</td>"; // Ready
                        $html .= "<td>" . getPodStatusBadge($parts[2]) . "</td>"; // Status (color-coded)
                        $html .= "<td>" . htmlspecialchars($parts[3]) . "</td>"; // Restarts
                        $html .= "<td>" . htmlspecialchars($parts[4] ?? '') . "</td>"; // Age
                        $html .= "</tr>";
                    }
                }

                $html .= "</tbody></table>";
                $response['html'] = $html;
            } else {
                $response['html'] = "<div class='alert alert-info'>No monitoring stack data available</div>";
            }
            $response['success'] = true;
            break;

        case 'cluster-events':
            $cluster_events = shell_exec("oc get events --all-namespaces | grep -E 'Warning|Critical'");

            if ($cluster_events === null || trim($cluster_events) === '') {
                $response['html'] = "<div class='alert alert-success'>No warning or critical events found.</div>";
            } else {
                $lines = explode("\n", trim($cluster_events));

                // Get header from full output (without grep)
                $full_output = shell_exec("oc get events --all-namespaces | head -1");
                $header_line = trim($full_output);

                if (!empty($lines)) {
                    $html = "<table class='data-table'>";
                    $html .= "<thead><tr>";
                    $html .= "<th>Namespace</th><th>Last Seen</th><th>Type</th><th>Reason</th><th>Object</th><th>Message</th>";
                    $html .= "</tr></thead><tbody>";

                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;

                        // Parse event line - split by whitespace, but limit to 6 parts to keep message together
                        $parts = preg_split('/\s+/', trim($line), 6);

                        if (count($parts) >= 6) {
                            $html .= "<tr>";
                            $html .= "<td>" . htmlspecialchars($parts[0]) . "</td>"; // Namespace
                            $html .= "<td>" . htmlspecialchars($parts[1]) . "</td>"; // Last Seen
                            $html .= "<td>" . getEventTypeBadge($parts[2]) . "</td>"; // Type (color-coded)
                            $html .= "<td>" . htmlspecialchars($parts[3]) . "</td>"; // Reason
                            $html .= "<td>" . htmlspecialchars($parts[4]) . "</td>"; // Object
                            $html .= "<td>" . htmlspecialchars($parts[5]) . "</td>"; // Message
                            $html .= "</tr>";
                        }
                    }

                    $html .= "</tbody></table>";
                    $response['html'] = $html;
                } else {
                    $response['html'] = "<div class='alert alert-success'>No warning or critical events found.</div>";
                }
            }
            $response['success'] = true;
            break;

        default:
            $response['error'] = 'Invalid section requested';
            break;
    }
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>
