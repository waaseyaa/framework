<?php

declare(strict_types=1);

namespace Aurora\AI\Vector;

/**
 * Supported distance/similarity metrics for vector comparison.
 */
enum DistanceMetric: string
{
    case COSINE = 'cosine';
    case EUCLIDEAN = 'euclidean';
    case DOT_PRODUCT = 'dot_product';
}
