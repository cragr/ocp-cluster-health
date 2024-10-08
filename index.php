<?php
// Fetch environment variables
$api_token = getenv('API_TOKEN');
$api_url = getenv('API_URL');

// Perform oc login using the token and URL
if ($api_token && $api_url) {
    $login_command = "oc login --token=$api_token $api_url";
    $login_output = shell_exec($login_command);

    if (strpos($login_output, 'Logged into') !== false) {
        echo "<p>Successfully logged into OpenShift API</p>";
    } else {
        echo "<p>Failed to login to OpenShift API</p>";
        exit(); // Stop further execution if login fails
    }
} else {
    echo "<p>API_TOKEN and API_URL environment variables are not set.</p>";
    exit(); // Stop further execution if environment variables are missing
}

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
    // Start the HTML table for cluster version history
    echo "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse: collapse; width: 70%; text-align: left;'>";
    echo "<tr style='background-color: #f2f2f2;'><th>Version</th><th>State</th><th>Started Time</th><th>Completed Time</th></tr>";

    // Split the output into rows and populate the table
    $rows = explode("\n", trim($cluster_version_output));
    foreach ($rows as $row) {
        if (!empty($row)) {
            $fields = explode(",", $row);
            echo "<tr>";
            echo "<td>" . htmlspecialchars($fields[0]) . "</td>";
            echo "<td>" . htmlspecialchars($fields[1]) . "</td>";
            echo "<td>" . htmlspecialchars($fields[2]) . "</td>";
            echo "<td>" . htmlspecialchars($fields[3]) . "</td>";
            echo "</tr>";
        }
    }

    // Close the HTML table
    echo "</table>";
} else {
    echo "<p>Failed to fetch cluster version history.</p>";
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

echo '<h2>Critical Events</h2>';
echo '<pre>' . shell_exec("oc get events --all-namespaces | grep -E 'Critical'") . '</pre>';
?>
