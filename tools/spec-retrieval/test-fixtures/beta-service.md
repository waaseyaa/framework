# Beta Service

## Overview

Beta Service handles message queuing and background job processing.

## Queue System

Messages are placed into named queues. Workers consume messages and execute the associated job handlers.

### Queue Configuration

Queues are defined in YAML configuration files with priority levels and retry policies.

## Authentication

Beta Service authenticates workers using API tokens validated against the central auth registry.

## File Reference

```
packages/beta/
  src/
    BetaQueue.php
    BetaWorker.php
```
