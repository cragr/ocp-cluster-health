# OCP Cluster Health Check

This repository contains the `index.php` file which performs a health check on an OpenShift cluster while also being hosted on the same OpenShift cluster.

The PHP application in this repository checks various aspects of the cluster’s health, including:
- Cluster version history
- Node status
- Critical events
- Resource usage (pods, nodes, etc.)

The `index.php` file is deployed using an S2I (Source-to-Image) container image.

## S2I Container Image

The S2I container image for this application is hosted at [quay.io/repository/cragr/php82-oc-cli](https://quay.io/repository/cragr/php82-oc-cli).

This image is based on PHP 8.2 and includes the OpenShift CLI (`oc`) for interacting with the OpenShift cluster from within the container.

## How the Image Is Built

The `php82-oc-cli` image is built using the `Dockerfile.rhel9` in the [8.2 folder](https://github.com/sclorg/s2i-php-container/tree/master/8.2) of the [s2i-php-container repository](https://github.com/sclorg/s2i-php-container). This Dockerfile provides the base PHP environment and tools needed to run PHP applications within an OpenShift cluster.

### Dockerfile Used

The `Dockerfile.rhel9` is the source used to build the container image. It includes:
- PHP 8.2
- OpenShift CLI (`oc`) for interacting with OpenShift clusters
- Custom configurations to allow OpenShift interactions from within the container.

## Deployment Instructions

When deploying the application to OpenShift, make sure to set the following environment variables in the deployment configuration:

- **API_TOKEN**: The OpenShift API token to authenticate the application with the cluster.
- **API_URL**: The URL of the OpenShift API server (e.g., `https://api.example.com:6443`).
- **PHP_CLEAR_ENV=OFF**: Ensure that PHP retains access to environment variables required for the OpenShift CLI.

### Example Environment Variable Configuration in OpenShift

When deploying the application, add the following environment variables to your `DeploymentConfig` or `Deployment`:

```yaml
env:
  - name: API_TOKEN
    value: "<your-api-token>"
  - name: API_URL
    value: "<your-api-url>"
  - name: PHP_CLEAR_ENV
    value: "OFF"
```

These environment variables are required for the `index.php` file to correctly authenticate and communicate with the OpenShift cluster.

How It Works
------------

The `index.php` script performs the following tasks:

-   **Authenticates to the OpenShift API** using the token and API URL provided through the environment variables.
-   **Executes `oc` commands** to gather cluster health information, such as:
    -   Cluster version
    -   Node status
    -   Critical events
    -   Resource usage (pods and nodes)
-   **Displays the health check results** in a simple web interface hosted on the OpenShift cluster.

Accessing the Application
-------------------------

Once the deployment is complete, the application will be available at the route created in your OpenShift cluster. Access the application via the route's URL to view the OpenShift cluster health report.
