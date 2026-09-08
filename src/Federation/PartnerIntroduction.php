<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

if (! defined('ABSPATH')) {
    exit;
}

/**
 * Outcome of an outbound partnership request (PartnerService::introduce):
 * what happened, and a plain-English detail — the failure reason or the
 * partner's answer — for the activity log and the operator's notice.
 * Translation happens at the surface that shows it.
 */
final class PartnerIntroduction
{
    public function __construct(
        public readonly IntroductionOutcome $outcome,
        public readonly string $detail,
    ) {
    }
}
