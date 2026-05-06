<?php

/**
 * Echoes inline JS that keeps the SMS-eligibility badge in sync with the
 * patient field on the calendar add/edit appointment form
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchConversations\Listener;

use OpenCoreEMR\Modules\SinchConversations\Bootstrap;
use OpenCoreEMR\Modules\SinchConversations\Render\EligibilityAlertRenderer;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Events\Appointments\AppointmentRenderEvent;

/**
 * Subscribes to AppointmentRenderEvent::RENDER_JAVASCRIPT. The companion
 * server-side listener (AppointmentSmsStatusListener) emits an initial
 * placeholder div on first render. This listener echoes inline JS that:
 *
 *   1. On DOMContentLoaded, fetches the alert markup for the current
 *      form_pid value (covers the new-with-preselect path even when the
 *      pid arrived via a route the server-side listener did not see).
 *   2. Subscribes to the openemr:appointment:patient:set custom event the
 *      core calendar already dispatches when staff swap patients via the
 *      popup picker, and re-fetches on each swap.
 *
 * AbortController guards against rapid swaps racing each other to write
 * stale results into the placeholder.
 *
 * Inline JS is acceptable on this page — the calendar already inlines
 * scripts via $eventDispatcher->dispatch(..., RENDER_JAVASCRIPT) and the
 * core file itself is full of inline <script> blocks.
 */
class AppointmentSmsStatusJsListener
{
    public const PATIENT_SET_EVENT = 'openemr:appointment:patient:set';

    public function onRenderJavascript(AppointmentRenderEvent $event): void
    {
        unset($event); // signature is fixed by the dispatcher; nothing in $event is needed here
        $webrootValue = OEGlobalsBag::getInstance()->get('webroot');
        $webroot = is_string($webrootValue) ? $webrootValue : '';
        $url = $webroot . '/interface/modules/custom_modules/' . Bootstrap::MODULE_NAME . '/public/eligibility.php';

        // Embed every dynamic value as JSON so any future webroot change
        // (or test that injects a quote) cannot break out of the JS string.
        $jsUrl = json_encode($url);
        $jsPlaceholderId = json_encode(EligibilityAlertRenderer::PLACEHOLDER_ID);
        $jsEventName = json_encode(self::PATIENT_SET_EVENT);
        $jsInputName = json_encode('form_pid');

        echo <<<JS
(function () {
    var URL = {$jsUrl};
    var PLACEHOLDER_ID = {$jsPlaceholderId};
    var EVENT_NAME = {$jsEventName};
    var INPUT_NAME = {$jsInputName};
    var abort = null;
    function refresh(pid) {
        var placeholder = document.getElementById(PLACEHOLDER_ID);
        if (!placeholder) { return; }
        if (!pid || !/^\d+\$/.test(String(pid))) { placeholder.innerHTML = ''; return; }
        if (abort) { abort.abort(); }
        abort = new AbortController();
        fetch(URL + '?pid=' + encodeURIComponent(pid), { signal: abort.signal, credentials: 'same-origin' })
            .then(function (r) { return r.text(); })
            .then(function (html) { placeholder.innerHTML = html; })
            .catch(function (e) { if (e && e.name !== 'AbortError') { console.error(e); } });
    }
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.querySelector('input[name="' + INPUT_NAME + '"]');
        if (input) { refresh(input.value); }
        document.addEventListener(EVENT_NAME, function (e) {
            var pid = (e && e.detail && e.detail.pid) || (input ? input.value : null);
            refresh(pid);
        });
    });
})();
JS;
        echo "\n";
    }
}
