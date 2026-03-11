# Distributed Tracing Demo with Symfony & OpenTelemetry

A set of PHP/Symfony microservices instrumented with OpenTelemetry to demonstrate distributed tracing. Traces are exported via an OpenTelemetry Collector to Grafana Tempo.

## Architecture

```
Traffic Generator ──► Gateway ──► Order Service ──► Payment Service
                                       │                  │
                                       ▼                  ▼
                                  Notification Service ◄──┘

All services ──► OTel Collector ──► Grafana Tempo
```

| Service | Description |
|---|---|
| **Gateway** | Entry point. Validates checkout requests, forwards to Order Service. |
| **Order Service** | Creates orders, checks inventory, calls Payment and Notification services. |
| **Payment Service** | Fraud check, authorization, settlement. ~5% simulated failure rate. |
| **Notification Service** | Simulates email/SMS delivery. Leaf node in the trace tree. |
| **Traffic Generator** | Continuously sends randomized checkout requests to the Gateway. |

## What you'll see in Tempo

Each checkout request produces a distributed trace spanning all four services, including:

- **Auto-instrumented spans** from `opentelemetry-auto-symfony` (HTTP server/client)
- **Custom business spans**: `checkout.validate_input`, `order.create`, `inventory.check`, `payment.fraud_check`, `payment.authorize`, `payment.settle`, `notification.prepare`, `notification.deliver`
- **Rich attributes**: customer IDs, order IDs, payment methods, amounts, risk scores, card last4 digits
- **Span events**: `input.validated`, `order.received`, `inventory.reserved`, `fraud_check.completed`, `authorization.approved`, `settlement.completed`, etc.
- **Error traces** on ~5% of payments showing declined status

## Prerequisites

- Docker
- A Kubernetes cluster (e.g. minikube, kind, or a cloud cluster)
- `kubectl` configured for your cluster
- Grafana Tempo instance ready to receive OTLP traces

## Quick Start

### 1. Configure

All image references are managed in a single file: `k8s/kustomization.yaml`. Update the `images:` section with your GHCR registry path and tag:

```yaml
images:
  - name: tracing-demo-gateway
    newName: ghcr.io/YOUR-ORG/tracing-demo/tracing-demo-gateway
    newTag: latest
  # ... same for other services
```

Set your Tempo endpoint in `k8s/otel-collector.yaml` (the `TEMPO_ENDPOINT` env var, default is `tempo:4317`).

### 2. Build images locally (optional)

```bash
for svc in gateway order payment notification traffic-generator; do
  docker build -t tracing-demo-$svc:test services/$svc/
done
```

### 3. Deploy to Kubernetes

```bash
kubectl apply -k k8s/
```

### 4. Verify

```bash
kubectl -n tracing-demo get pods
```

Once all pods are running, the traffic generator will start sending requests and traces will appear in Tempo.

## CI/CD

Push to `main` and the GitHub Actions workflow (`.github/workflows/build.yaml`) will build all five images in parallel and push them to GHCR.

The workflow uses a matrix strategy and Docker layer caching via GitHub Actions cache for fast builds.

## Project Structure

```
services/
  gateway/              Symfony app — API entry point
  order/                Symfony app — order + inventory logic
  payment/              Symfony app — payment processing
  notification/         Symfony app — notification delivery
  traffic-generator/    Simple PHP script for load generation
k8s/
  kustomization.yaml    Kustomize entrypoint (namespace, images, resources)
  namespace.yaml        Kubernetes namespace
  otel-collector.yaml   OTel Collector (receives OTLP, exports to Tempo)
  gateway.yaml          Gateway Deployment + Service
  order-service.yaml    Order Deployment + Service
  payment-service.yaml  Payment Deployment + Service
  notification-service.yaml  Notification Deployment + Service
  traffic-generator.yaml     Traffic generator Deployment
.github/
  workflows/
    build.yaml          CI workflow — build & push to GHCR
```

## How It Works

Each Symfony service uses the `opentelemetry-auto-symfony` package which automatically:

1. Creates server spans for incoming HTTP requests
2. Creates client spans for outgoing `HttpClient` calls
3. Propagates W3C Trace Context (`traceparent` header) across service boundaries

On top of auto-instrumentation, each controller creates **manual spans** with business-specific attributes and events using the OpenTelemetry PHP API. The PHP `ext-opentelemetry` extension enables zero-code hooking, and the SDK is auto-configured from environment variables (`OTEL_SERVICE_NAME`, `OTEL_EXPORTER_OTLP_ENDPOINT`, etc.).

Traces are exported over HTTP/protobuf to the OpenTelemetry Collector (port 4318), which batches and forwards them to Grafana Tempo.
