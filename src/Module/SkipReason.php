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
 * Values are written into log context (`reason` field) and consumed by
 * dashboards/alerts. Treat them as stable by default; only change a value
 * when its meaning genuinely changes (in which case keeping the old name
 * would mislead readers and downstream consumers more than the rename
 * does). Used by both the appointment-reminder cron (skip decisions) and
 * the send-path eligibility gate (block decisions).
 */
enum SkipReason: string
{
    case MissingPhone = 'missing_phone';
    case UnparseablePhone = 'unparseable_phone';
    case HipaaDisallowsSms = 'hipaa_disallows_sms';
    case ModuleOptOut = 'module_opt_out';
    case CarrierBlocked = 'carrier_blocked';
}
