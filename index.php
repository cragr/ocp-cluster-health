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

            <!-- Cluster Status -->
            <div class="section loading" id="section-cluster-status" data-section="cluster-status">
                <h2>Cluster Status</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Node Status -->
            <div class="section loading" id="section-nodes" data-section="nodes">
                <h2>Node Status</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Node Utilization -->
            <div class="section loading" id="section-node-utilization" data-section="node-utilization">
                <h2>Node Utilization</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Cluster Operators -->
            <div class="section loading" id="section-cluster-operators" data-section="cluster-operators">
                <h2>Cluster Operators</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Monitoring Stack -->
            <div class="section loading" id="section-monitoring-stack" data-section="monitoring-stack">
                <h2>Monitoring Stack</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Cluster Version History -->
            <div class="section loading" id="section-version-history" data-section="version-history">
                <h2>Cluster Version History</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

            <!-- Critical Events -->
            <div class="section loading" id="section-critical-events" data-section="critical-events">
                <h2>Critical Events</h2>
                <div class="section-content">
                    <div class="alert alert-info">Loading...</div>
                </div>
            </div>

        </div>

        <!-- Footer -->
        <div class="footer">
            Powered by OpenShift CLI | Auto-refresh recommended
        </div>
    </div>

    <script>
        // Progressive section loading with progress bar
        document.addEventListener('DOMContentLoaded', function() {
            const progressBar = document.getElementById('progressBar');
            const sections = document.querySelectorAll('.section[data-section]');
            const totalSections = sections.length;
            let loadedSections = 0;

            // Initialize progress
            updateProgress(5);

            // Load sections sequentially
            async function loadAllSections() {
                updateProgress(10);

                for (let i = 0; i < sections.length; i++) {
                    const section = sections[i];
                    const sectionName = section.getAttribute('data-section');

                    try {
                        await loadSection(section, sectionName);
                        loadedSections++;
                        updateProgress(10 + (loadedSections / totalSections) * 85);
                    } catch (error) {
                        console.error(`Failed to load section ${sectionName}:`, error);
                        const contentDiv = section.querySelector('.section-content');
                        contentDiv.innerHTML = '<div class="alert alert-danger">Failed to load data</div>';
                        section.classList.remove('loading');
                        loadedSections++;
                        updateProgress(10 + (loadedSections / totalSections) * 85);
                    }

                    // Small delay between sections for visual effect
                    await new Promise(resolve => setTimeout(resolve, 100));
                }

                // Complete the progress bar
                updateProgress(100);
            }

            // Load individual section data
            async function loadSection(sectionElement, sectionName) {
                const response = await fetch(`data.php?section=${sectionName}`);
                const data = await response.json();

                const contentDiv = sectionElement.querySelector('.section-content');

                if (data.success) {
                    contentDiv.innerHTML = data.html;
                } else {
                    contentDiv.innerHTML = `<div class="alert alert-danger">${data.error || 'Failed to load'}</div>`;
                }

                sectionElement.classList.remove('loading');
            }

            // Update progress bar width
            function updateProgress(percentage) {
                progressBar.style.width = percentage + '%';

                if (percentage >= 100) {
                    setTimeout(() => {
                        progressBar.classList.add('complete');
                    }, 300);
                }
            }

            // Start loading sections
            loadAllSections();

            // Add staggered animation delays
            sections.forEach((section, index) => {
                section.style.animationDelay = `${index * 0.1}s`;
            });
        });

        // Auto-refresh functionality (optional - uncomment to enable)
        // setTimeout(() => {
        //     location.reload();
        // }, 300000); // Refresh every 5 minutes
    </script>
</body>
</html>
