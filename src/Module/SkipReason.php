<?php

/**
 * Stable reason codes for why a message or reminder was not delivered
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations;

/**
 * Why a patient was not (or could not be) sent a message.
 *
 * Values are written into log context (`reason` field) and are part of the
 * observability contract — keep them stable across releases. Used by both
 * the appointment-reminder cron (skip decisions) and the send-path
 * eligibility gate (block decisions).
 */
enum SkipReason: string
{
    case MissingPhone = 'missing_phone';
    case UnparseablePhone = 'unparseable_phone';
    case HipaaDisallowsSms = 'hipaa_disallows_sms';
    case NoActiveConsent = 'no_active_consent';
}
