<?php

declare(strict_types=1);

namespace OpenYacht\Federation;

/**
 * Error codes of the federation error envelope, with their HTTP mappings
 * (API-9).
 *
 * // api-design.md §Errors
 */
enum ErrorCode: string
{
    case SignatureInvalid = 'SIGNATURE_INVALID';
    case TimestampOutOfRange = 'TIMESTAMP_OUT_OF_RANGE';
    case PartnerUnknown = 'PARTNER_UNKNOWN';
    case PartnerBlocked = 'PARTNER_BLOCKED';
    case PartnerProvisional = 'PARTNER_PROVISIONAL';
    case NotFound = 'NOT_FOUND';
    case Gone = 'GONE';
    case RateLimited = 'RATE_LIMITED';
    case ValidationError = 'VALIDATION_ERROR';
    case VersionUnsupported = 'VERSION_UNSUPPORTED';

    public function httpStatus(): int
    {
        return match ($this) {
            self::SignatureInvalid,
            self::TimestampOutOfRange,
            self::PartnerUnknown => 401,
            self::PartnerBlocked,
            self::PartnerProvisional => 403,
            self::NotFound => 404,
            self::Gone => 410,
            self::RateLimited => 429,
            self::ValidationError => 422,
            self::VersionUnsupported => 400,
        };
    }
}
