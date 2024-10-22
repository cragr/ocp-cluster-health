<?php
// Report title and date/time
echo '<h1>OpenShift Cluster Health Report</h1>';
echo '<p>Report generated on: ' . date('Y-m-d H:i:s') . '</p>';
echo '<hr>';

// Check the version of OpenShift
echo '<h2>Check the version of OpenShift</h2>';
echo '<pre>' . shell_exec('oc get clusterversion') . '</pre>';

// Add the cluster version history as the second item
echo '<h2>Cluster Version History</h2>';

// Fetch cluster version information using the oc and jq command
$cluster_version_output = shell_exec('oc get clusterversion version -o json | jq -r \'.status.history[] | "\(.version),\(.state),\(.startedTime),\(.completionTime)"\'');

if ($cluster_version_output) {
    // Wrap output in <pre> to preserve formatting
    echo "<pre>";

    // Print the table header
    echo str_pad("VERSION", 12) . str_pad("STATE", 12) . str_pad("STARTED TIME", 30) . str_pad("COMPLETED TIME", 30) . "\n";
    echo str_repeat("-", 84) . "\n";

    // Split the output into rows and format the data into columns
    $rows = explode("\n", trim($cluster_version_output));
    foreach ($rows as $row) {
        if (!empty($row)) {
            $fields = explode(",", $row);
            echo str_pad($fields[0], 12); // Version
            echo str_pad($fields[1], 12); // State
            echo str_pad($fields[2], 30); // Started Time
            echo str_pad($fields[3], 30); // Completed Time
            echo "\n";
        }
    }

    echo "</pre>";
} else {
    echo "Failed to fetch cluster version information.\n";
}

echo '<h2>Check the status of OpenShift nodes</h2>';
echo '<pre>' . shell_exec('oc get nodes') . '</pre>';

echo '<h2>Check the status of OpenShift cluster operators (co)</h2>';
echo '<pre>' . shell_exec('oc get co') . '</pre>';

echo '<h2>Node Resource Usage</h2>';
echo '<pre>' . shell_exec('oc adm top nodes') . '</pre>';

echo '<h2>Ingress Pod Status</h2>';
echo '<pre>' . shell_exec('oc get pods -n openshift-ingress') . '</pre>';

echo '<h2>Ingress Pod Resource Usage</h2>';
echo '<pre>' . shell_exec('oc adm top pods -n openshift-ingress') . '</pre>';

echo '<h2>Monitoring Pod Status</h2>';
echo '<pre>' . shell_exec('oc get pods -n openshift-monitoring') . '</pre>';

// Fetch critical events
$critical_events_output = shell_exec("oc get events --all-namespaces | grep -E 'Critical'");

// Display the Critical Events section
echo '<h2>Critical Events</h2>';

// Check if shell_exec returned null or an empty string
if ($critical_events_output === null || trim($critical_events_output) === '') {
    // If no critical events are found or the command failed, display a message
    echo '<pre>No critical events found.</pre>';
} else {
    // If critical events are found, display the output
    echo '<pre>' . $critical_events_output . '</pre>';
}
?>
