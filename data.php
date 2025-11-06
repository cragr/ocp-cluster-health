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
        case 'version':
            $output = shell_exec('oc get clusterversion');
            $response['html'] = createTable($output, true);
            $response['success'] = true;
            break;

        case 'version-history':
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

        case 'operators':
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
                        $html .= "<td>" . getStatusBadge($parts[2] ?? '') . "</td>";
                        $html .= "<td>" . getStatusBadge($parts[3] ?? '') . "</td>";
                        $html .= "<td>" . getStatusBadge($parts[4] ?? '') . "</td>";
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

        case 'node-resources':
            $output = shell_exec('oc adm top nodes');
            $response['html'] = createTable($output);
            $response['success'] = true;
            break;

        case 'ingress-pods':
            $output = shell_exec('oc get pods -n openshift-ingress');
            $response['html'] = createTable($output, true);
            $response['success'] = true;
            break;

        case 'ingress-resources':
            $output = shell_exec('oc adm top pods -n openshift-ingress');
            $response['html'] = createTable($output);
            $response['success'] = true;
            break;

        case 'monitoring-pods':
            $output = shell_exec('oc get pods -n openshift-monitoring');
            $response['html'] = createTable($output, true);
            $response['success'] = true;
            break;

        case 'critical-events':
            $critical_events = shell_exec("oc get events --all-namespaces | grep -E 'Critical'");

            if ($critical_events === null || trim($critical_events) === '') {
                $response['html'] = "<div class='alert alert-success'>No critical events found.</div>";
            } else {
                $response['html'] = "<div class='alert alert-danger'>Critical events detected</div>";
                $response['html'] .= "<pre class='raw-output'>" . htmlspecialchars($critical_events) . "</pre>";
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
