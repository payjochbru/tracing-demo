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
| **Payment Service** | Fraud check, authorization, settlement. Simulated failure scenarios. |
| **Notification Service** | Simulates email/SMS delivery. Leaf node in the trace tree. |
| **Traffic Generator** | Continuously sends randomized checkout requests to the Gateway. |

## Reading a Trace -- A Walkthrough

Open a trace in Grafana Tempo and follow the waterfall from top to bottom. Each horizontal bar is a **span** -- a single unit of work. Together they form a **trace** that shows the full lifecycle of a request across all services.

### 1. Gateway receives the request

The top-level span is created automatically by OpenTelemetry's Symfony auto-instrumentation. It captures the incoming `POST /checkout` request.

| Span | What it shows |
|---|---|
| `checkout.validate_input` | Manual span. Validates the cart contents. Look at its **attributes** panel to see `customer.id`, `cart.total`, `cart.items_count`, and `cart.currency`. |
| `gateway.geo_ip_lookup` | External HTTP call to a geo-IP API (httpbin.org). This is a real network call -- the span duration reflects actual internet latency, not a simulation. |
| `gateway.idempotency_check` | Checks whether this request was already processed. Shows `idempotency.key` in attributes. |

After validation, the gateway makes an HTTP call to the order service. The auto-instrumentation creates a **client span** (`POST`) and injects a `traceparent` header into the outgoing request. This is how the trace crosses the service boundary.

### 2. Order Service processes the order

The order service picks up the `traceparent` header and continues the same trace. All spans below are children of the gateway's outgoing HTTP call.

| Span | What it shows |
|---|---|
| `db.query INSERT orders` | Simulated database call. Check the **attributes** for `db.system` (postgresql), `db.statement` (the SQL), and `db.operation` (INSERT). These follow [OpenTelemetry semantic conventions](https://opentelemetry.io/docs/specs/semconv/database/). Occasionally (~5%) this is a **slow query** -- you'll see a `db.slow_query` event with the duration. |
| `inventory.check` | Parent span for the full inventory check. |
| `inventory.check_sku` | One child span per item. Look at the `cache.hit` attribute (true/false). On a cache miss, you'll see a nested `db.query SELECT stock` span -- showing the extra database round-trip. ~8% chance of **out-of-stock** errors on certain SKUs, visible as a red error span with a recorded exception. |
| `shipping.calculate_cost` | External HTTP call to a shipping cost API (httpbin.org). Attributes show `shipping.total_weight_kg`, `shipping.cost`, and `shipping.priority`. Express orders cost more. |

### 3. Payment Service handles the payment

The order service calls the payment service, again propagating the trace context.

| Span | What it shows |
|---|---|
| `payment.compliance_check` | External HTTP call to a sanctions/PEP screening API (httpbin.org). Attributes: `compliance.provider`, `compliance.result`. Events show which sanctions lists were checked. |
| `payment.fx_rate_lookup` | External HTTP call for exchange rates (httpbin.org). Attributes show `fx.base_currency` and rate values. |
| `payment.fraud_check` | Risk scoring. The `fraud.risk_score` attribute shows the calculated score. High scores (>60) trigger an `fraud.additional_checks_triggered` event. Scores above 90 result in a hard block -- look for a red error span with a `recordException` showing the reason. |
| `payment.3ds_verification` | Only appears for card payments (Visa/Mastercard) over EUR 250. Simulates 3D Secure authentication. ~2% timeout errors. |
| `psp.authorize (pay.nl)` | The actual payment authorization call to the PSP. The PSP name varies by payment method (pay.nl, mollie, or adyen). Attributes include `payment.method`, `payment.amount`, `payment.card_last4`, and `psp.name`. On decline, you'll see `payment.decline_code` with reasons like `insufficient_funds`, `card_expired`, or `suspected_fraud`. |
| `payment.settle` | Final settlement after successful authorization. |
| `db.query INSERT payments` | Persists the payment record. |

### 4. Notification Service delivers the message

Called by both the order service (confirmation) and the payment service (failure alerts, fraud alerts).

| Span | What it shows |
|---|---|
| `notification.render_template` | Template rendering. The `template.name` attribute shows which template (e.g. `emails/order_confirmed.html.twig`). Events show render time and output size. |
| `db.query INSERT notifications` | Persists the notification record. |
| `notification.deliver (sendgrid)` | Delivery attempt via the primary provider. The provider name is in the span name. ~8% chance of failure -- look for red error spans with `recordException` showing connection errors or timeouts. |
| `notification.deliver (ses)` | Only appears when the primary provider failed. This is the **fallback** -- look at the `notification.is_retry` and `notification.retry_reason` attributes. ~2% chance this also fails, resulting in a full delivery failure (502). |

## Key OpenTelemetry Concepts Demonstrated

### Spans and Traces

A **trace** is the full journey of a request. It is made up of **spans** -- each span represents one operation (an HTTP request, a database query, a business logic step). Spans have a parent-child relationship that creates the waterfall view.

### Context Propagation

When one service calls another over HTTP, the `traceparent` header carries the trace ID and parent span ID. The receiving service uses this to attach its spans to the same trace. This is how a single trace can span four separate Kubernetes pods.

### Auto-Instrumentation vs Manual Instrumentation

- **Auto-instrumented spans** are created automatically by `opentelemetry-auto-symfony`. These are the `POST` and `GET` spans for HTTP calls. They require zero code changes.
- **Manual spans** are created explicitly in the controller code using `$tracer->spanBuilder('name')->startSpan()`. These carry business meaning like `payment.fraud_check` or `inventory.check`.

Both types appear in the same trace, side by side.

### Span Attributes

Key-value pairs attached to a span. Click any span in Tempo to see its attributes. Examples:

- `db.system=postgresql`, `db.statement=INSERT INTO orders ...` -- database details
- `payment.method=ideal`, `psp.name=mollie` -- payment context
- `fraud.risk_score=73`, `compliance.result=clear` -- business data
- `cache.hit=true` -- infrastructure data

### Span Events

Timestamped log entries within a span. They mark specific moments, like `fraud_check.passed`, `authorization.approved`, or `db.slow_query`. Look for the "Events" section when you click a span in Tempo.

### Span Status and Errors

Spans can have a status of OK or ERROR. Error spans appear in red in the waterfall. When an error occurs, `recordException` captures the exception class, message, and stack trace directly on the span. In this demo, errors include:

- **Rate limiting** (gateway, 429) -- `Rate limit exceeded for customer ...`
- **Empty cart** (gateway, 400) -- `Cannot checkout with an empty cart`
- **Out of stock** (order service, 409) -- `Insufficient stock for SKU MK-300`
- **Fraud block** (payment, 403) -- `Transaction blocked: risk score 94 exceeds threshold 90`
- **3DS timeout** (payment, 504) -- `3D Secure verification timed out after 30s`
- **PSP timeout** (payment, 504) -- `Connection to pay.nl-api.example.com timed out`
- **Payment declined** (payment, 422) -- various decline codes
- **Provider failure** (notification, 502) -- `Fallback provider ses also failed`

### Span Kind

Some spans have a **kind** that describes their role:

- `SERVER` -- handling an incoming request (auto-instrumented)
- `CLIENT` -- making an outgoing call (database queries, external APIs)
- `INTERNAL` -- internal processing (default for manual spans)

### External API Calls

Three services make real HTTP calls to httpbin.org, simulating third-party API integrations. These show actual network latency in the trace and demonstrate that context propagation works even to external services. The spans for these calls are visible as `httpbin.org GET` or `httpbin.org POST` in the waterfall.

## Error Scenarios in the Traffic

The traffic generator sends a mix of request types to produce varied traces:

| Scenario | Weight | What to look for |
|---|---|---|
| Normal checkout | 60% | Happy path through all four services |
| High-value checkout | 10% | Large orders that trigger `checkout.high_value_order` events and 3DS verification |
| Express checkout | 10% | `order.priority=express`, triggers both email and SMS notifications |
| Order lookup | 15% | `GET /orders/{id}` -- shorter traces with just gateway + order service, ~10% return 404 |
| Empty cart | 5% | Validation error at the gateway -- very short trace with error span |
| Burst traffic | 20% | 1-2 rapid follow-up requests after the main one |

## Prometheus Metrics

Every service exposes a `/metrics` endpoint in Prometheus text format. A Prometheus instance configured to scrape pods with the standard `prometheus.io/*` annotations will pick these up automatically.

### Per-Service Application Metrics

Each service tracks custom business metrics using `promphp/prometheus_client_php` with APCu shared memory storage.

**Gateway**

| Metric | Type | Labels | Description |
|---|---|---|---|
| `gateway_http_requests_total` | counter | method, endpoint, status | Total HTTP requests handled |
| `gateway_http_request_duration_seconds` | histogram | method, endpoint | Request latency distribution |
| `gateway_checkout_total` | counter | priority, region | Checkout attempts |
| `gateway_rate_limited_total` | counter | region | Rate-limited requests |
| `gateway_cart_value_euros` | gauge | region | Last seen cart value |

**Order Service**

| Metric | Type | Labels | Description |
|---|---|---|---|
| `order_http_requests_total` | counter | method, endpoint, status | Total HTTP requests handled |
| `order_http_request_duration_seconds` | histogram | method, endpoint | Request latency distribution |
| `order_orders_created_total` | counter | priority, region | Successfully created orders |
| `order_order_value_euros` | histogram | | Order value distribution |
| `order_inventory_cache_lookups_total` | counter | result | Cache hit/miss ratio for inventory checks |
| `order_inventory_out_of_stock_total` | counter | sku | Out-of-stock events by SKU |
| `order_db_slow_queries_total` | counter | operation | Slow database queries |

**Payment Service**

| Metric | Type | Labels | Description |
|---|---|---|---|
| `payment_http_requests_total` | counter | method, endpoint, status | Total HTTP requests handled |
| `payment_http_request_duration_seconds` | histogram | method, endpoint | Request latency distribution |
| `payment_successful_total` | counter | method, psp | Successful payments by method and PSP |
| `payment_declined_total` | counter | method, psp, reason | Declined payments with decline reason |
| `payment_fraud_blocks_total` | counter | | Transactions blocked by fraud detection |
| `payment_fraud_risk_score` | histogram | | Fraud risk score distribution |
| `payment_amount_euros` | histogram | method, currency | Payment amount distribution |
| `payment_psp_latency_seconds` | histogram | psp | PSP authorization latency per provider |

**Notification Service**

| Metric | Type | Labels | Description |
|---|---|---|---|
| `notification_http_requests_total` | counter | method, endpoint, status | Total HTTP requests handled |
| `notification_http_request_duration_seconds` | histogram | method, endpoint | Request latency distribution |
| `notification_sent_total` | counter | type, channel, provider | Successfully sent notifications |
| `notification_provider_errors_total` | counter | channel, provider | Provider delivery failures |
| `notification_provider_failovers_total` | counter | channel | Failover events from primary to fallback provider |
| `notification_total_failures` | counter | channel | Complete delivery failures (both providers down) |
| `notification_delivery_duration_seconds` | histogram | channel, provider | Delivery latency per channel and provider |

### OTel Collector Span Metrics (RED)

The OpenTelemetry Collector uses the `spanmetrics` connector to automatically derive Rate, Error, and Duration (RED) metrics from every trace span. These are exposed on `otel-collector:9090/metrics` in Prometheus format.

Generated metrics include:

- `calls_total{service_name, span_name, status_code}` -- request rate per span
- `duration_milliseconds_bucket{service_name, span_name}` -- latency histograms per span

These require zero code changes -- they are computed from trace data. This is a powerful way to demonstrate how traces and metrics connect: every span you see in Tempo also has corresponding rate/error/duration metrics available in Prometheus.

### Scrape Configuration

All pods carry standard Prometheus annotations:

```yaml
annotations:
  prometheus.io/scrape: "true"
  prometheus.io/port: "8080"    # 9090 for the OTel Collector
  prometheus.io/path: "/metrics"
```

If your Prometheus uses the Kubernetes SD config with `relabel_configs` that honour these annotations, scraping works out of the box.

### Example PromQL Queries

```promql
# Checkout request rate (per second, 5m window)
rate(gateway_http_requests_total{endpoint="/checkout"}[5m])

# Payment success rate
sum(rate(payment_successful_total[5m])) / sum(rate(payment_http_requests_total{endpoint="/payments"}[5m]))

# P99 latency per service
histogram_quantile(0.99, sum(rate(gateway_http_request_duration_seconds_bucket[5m])) by (le))

# Inventory cache hit ratio
sum(rate(order_inventory_cache_lookups_total{result="hit"}[5m])) / sum(rate(order_inventory_cache_lookups_total[5m]))

# PSP latency comparison (P95)
histogram_quantile(0.95, sum(rate(payment_psp_latency_seconds_bucket[5m])) by (le, psp))

# Notification provider failover rate
rate(notification_provider_failovers_total[5m])

# Span-derived: request rate per service (from OTel Collector)
sum(rate(calls_total[5m])) by (service_name)
```

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
