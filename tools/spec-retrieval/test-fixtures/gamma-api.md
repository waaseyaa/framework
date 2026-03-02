# Gamma API

REST API gateway for external integrations.

## Endpoints

The API exposes CRUD operations for all registered entity types. Each entity type gets five endpoints automatically.

## Rate Limiting

Requests are throttled per API key. Default limit is 1000 requests per hour.

## File Reference

```
packages/gamma/
  src/
    GammaRouter.php
    GammaRateLimiter.php
```
