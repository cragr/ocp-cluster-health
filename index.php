<?php
/**
 * OpenShift Cluster Health Report Dashboard
 * A visually appealing status page for monitoring OpenShift cluster health
 */

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

// Helper function to parse resource usage and create meters
function createResourceMeter($value) {
    $percentage = intval($value);
    $class = $percentage < 60 ? 'low' : ($percentage < 80 ? 'medium' : 'high');

    return "
        <div class='resource-meter'>
            <div class='resource-meter-fill $class' style='width: {$percentage}%'>
                {$percentage}%
            </div>
        </div>
    ";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OpenShift Cluster Health Report</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <!-- Loading Progress Bar -->
    <div class="progress-bar" id="progressBar"></div>

    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>OpenShift Cluster Health Report</h1>
            <div class="timestamp">
                Report generated on: <?php echo date('Y-m-d H:i:s'); ?>
            </div>
        </div>

        <!-- Main Content -->
        <div class="content">

            <!-- OpenShift Version -->
            <div class="section">
                <h2>OpenShift Version</h2>
                <?php
                $version_output = shell_exec('oc get clusterversion');
                echo createTable($version_output, true);
                ?>
            </div>

            <!-- Cluster Version History -->
            <div class="section">
                <h2>Cluster Version History</h2>
                <?php
                $cluster_version_output = shell_exec('oc get clusterversion version -o json | jq -r \'.status.history[] | "\(.version),\(.state),\(.startedTime),\(.completionTime)"\'');

                if ($cluster_version_output) {
                    echo "<table class='data-table'>";
                    echo "<thead><tr>";
                    echo "<th>Version</th><th>State</th><th>Started Time</th><th>Completed Time</th>";
                    echo "</tr></thead><tbody>";

                    $rows = explode("\n", trim($cluster_version_output));
                    foreach ($rows as $row) {
                        if (!empty($row)) {
                            $fields = explode(",", $row);
                            if (count($fields) >= 4) {
                                echo "<tr>";
                                echo "<td>" . htmlspecialchars($fields[0]) . "</td>";
                                echo "<td>" . getStatusBadge($fields[1]) . "</td>";
                                echo "<td>" . htmlspecialchars($fields[2]) . "</td>";
                                echo "<td>" . htmlspecialchars($fields[3]) . "</td>";
                                echo "</tr>";
                            }
                        }
                    }

                    echo "</tbody></table>";
                } else {
                    echo "<div class='alert alert-warning'>Failed to fetch cluster version information.</div>";
                }
                ?>
            </div>

            <!-- Node Status -->
            <div class="section">
                <h2>Node Status</h2>
                <?php
                $nodes_output = shell_exec('oc get nodes');
                echo createTable($nodes_output, true);
                ?>
            </div>

            <!-- Cluster Operators Status -->
            <div class="section">
                <h2>Cluster Operators Status</h2>
                <?php
                $co_output = shell_exec('oc get co');
                if (!empty(trim($co_output))) {
                    $lines = explode("\n", trim($co_output));
                    $header = array_shift($lines);

                    echo "<table class='data-table'>";
                    echo "<thead><tr><th>Name</th><th>Version</th><th>Available</th><th>Progressing</th><th>Degraded</th><th>Since</th><th>Message</th></tr></thead>";
                    echo "<tbody>";

                    foreach ($lines as $line) {
                        if (empty(trim($line))) continue;

                        $parts = preg_split('/\s+/', trim($line), 7);
                        if (count($parts) >= 5) {
                            echo "<tr>";
                            echo "<td>" . htmlspecialchars($parts[0]) . "</td>";
                            echo "<td>" . htmlspecialchars($parts[1] ?? '') . "</td>";
                            echo "<td>" . getStatusBadge($parts[2] ?? '') . "</td>";
                            echo "<td>" . getStatusBadge($parts[3] ?? '') . "</td>";
                            echo "<td>" . getStatusBadge($parts[4] ?? '') . "</td>";
                            echo "<td>" . htmlspecialchars($parts[5] ?? '') . "</td>";
                            echo "<td>" . htmlspecialchars($parts[6] ?? '') . "</td>";
                            echo "</tr>";
                        }
                    }

                    echo "</tbody></table>";
                } else {
                    echo "<div class='alert alert-info'>No cluster operator data available</div>";
                }
                ?>
            </div>

            <!-- Node Resource Usage -->
            <div class="section">
                <h2>Node Resource Usage</h2>
                <?php
                $node_resources = shell_exec('oc adm top nodes');
                echo createTable($node_resources);
                ?>
            </div>

            <!-- Ingress Pod Status -->
            <div class="section">
                <h2>Ingress Pod Status</h2>
                <?php
                $ingress_pods = shell_exec('oc get pods -n openshift-ingress');
                echo createTable($ingress_pods, true);
                ?>
            </div>

            <!-- Ingress Pod Resource Usage -->
            <div class="section">
                <h2>Ingress Pod Resource Usage</h2>
                <?php
                $ingress_resources = shell_exec('oc adm top pods -n openshift-ingress');
                echo createTable($ingress_resources);
                ?>
            </div>

            <!-- Monitoring Pod Status -->
            <div class="section">
                <h2>Monitoring Pod Status</h2>
                <?php
                $monitoring_pods = shell_exec('oc get pods -n openshift-monitoring');
                echo createTable($monitoring_pods, true);
                ?>
            </div>

            <!-- Critical Events -->
            <div class="section">
                <h2>Critical Events</h2>
                <?php
                $critical_events = shell_exec("oc get events --all-namespaces | grep -E 'Critical'");

                if ($critical_events === null || trim($critical_events) === '') {
                    echo "<div class='alert alert-success'>✓ No critical events found.</div>";
                } else {
                    echo "<div class='alert alert-danger'>⚠ Critical events detected!</div>";
                    echo "<pre class='raw-output'>" . htmlspecialchars($critical_events) . "</pre>";
                }
                ?>
            </div>

        </div>

        <!-- Footer -->
        <div class="footer">
            Powered by OpenShift CLI | Auto-refresh recommended
        </div>
    </div>

    <script>
        // Progress bar management
        document.addEventListener('DOMContentLoaded', function() {
            const progressBar = document.getElementById('progressBar');
            const sections = document.querySelectorAll('.section');
            const totalSections = sections.length;
            let loadedSections = 0;

            // Initialize progress
            updateProgress(10);

            // Create an observer to track when sections come into view
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting && !entry.target.classList.contains('loaded')) {
                        entry.target.classList.add('loaded');
                        loadedSections++;
                        updateProgress(10 + (loadedSections / totalSections) * 85);
                    }
                });
            }, {
                threshold: 0.1
            });

            // Observe all sections
            sections.forEach((section, index) => {
                section.style.animationDelay = `${index * 0.1}s`;
                observer.observe(section);
            });

            // Update progress bar width
            function updateProgress(percentage) {
                progressBar.style.width = percentage + '%';

                if (percentage >= 95) {
                    setTimeout(() => {
                        progressBar.style.width = '100%';
                        setTimeout(() => {
                            progressBar.classList.add('complete');
                        }, 300);
                    }, 200);
                }
            }

            // Mark page as fully loaded
            window.addEventListener('load', function() {
                updateProgress(100);
            });
        });

        // Auto-refresh functionality (optional - uncomment to enable)
        // setTimeout(() => {
        //     location.reload();
        // }, 300000); // Refresh every 5 minutes
    </script>
</body>
</html>
