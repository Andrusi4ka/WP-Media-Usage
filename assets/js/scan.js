/**
 * WP Media Usage's checkbox, Mark unused files (user's request, 2026-11-19: a checkbox as WP Banner's instead of the Check usage
 * button). Ticked: saved, the whole site checked at once (admin-ajax wmu_scan, a few seconds a request, its progress beside
 * it), then the page again with the marks. Unticked: saved, the page again without them. In the list view after the page's
 * title; in the grid grid.js puts it in the toolbar after Bulk select, through window.wmuSwitchBox.
 * Strings and state: window.wmuMedia (WMU_Assets).
 */
(function ($) {
    'use strict';
    const S = window.wmuMedia;
    if (!S) return;
    const L = S.l10n;

    // The check: its requests one after another, each answer saying where it is; done, the page again with the marks.
    function check(input, status) {
        const fail = () => { input.disabled = false; status.textContent = L.failed; };
        const step = start => $.post(S.ajax, {action:'wmu_scan', nonce:S.nonce, start:start ? 1 : 0}).done(res => {
            if (!res || !res.success) { fail(); return; }
            const d = res.data;
            if (d.done) { status.textContent = d.last; window.location.reload(); return; }
            status.textContent = L.checking + ' ' + (L.phases[d.phase] || d.phase) + (d.phase === 'resolve' ? ' (' + d.checked + ')' : '');
            step(false);
        }).fail(fail);
        input.disabled = true;
        status.textContent = L.checking;
        step(true);
    }

    // Ticked: saved, then checked. Unticked: saved, then the page without the marks.
    function turn(input, status) {
        const on = input.checked;
        input.disabled = true;
        $.post(S.ajax, {action:'wmu_switch', nonce:S.nonce, on:on ? 1 : 0}).done(res => {
            if (!res || !res.success) { input.disabled = false; input.checked = !on; return; }
            if (on) check(input, status); else window.location.reload();
        }).fail(() => { input.disabled = false; input.checked = !on; });
    }

    // The checkbox (for those who may tick it) and the last check (while ticked) in box.
    window.wmuSwitchBox = function (box) {
        box.innerHTML = (S.canSwitch ? '<label class="wmu-switch"><input type="checkbox"' + (S.on ? ' checked' : '')
            + '><span class="wmu-switch__track" aria-hidden="true"></span><span>' + _.escape(L.switch) + '</span></label>'
            // What it does, where it looks and what it cannot see: an "i" as WP Banner's (its text on a dark background).
            + '<span class="wmu-tip dashicons dashicons-info-outline" tabindex="0" role="note" aria-label="' + _.escape(L.switchTip) + '" data-tip="' + _.escape(L.switchTip) + '"></span>' : '')
            + '<span class="wmu-status"></span>';
        const input = box.querySelector('.wmu-switch input'), status = box.querySelector('.wmu-status');
        status.textContent = S.on ? S.last : '';
        if (!input) return;
        input.addEventListener('change', () => turn(input, status));
        // Ticked, but its check never finished (the page was left while it ran): finished now.
        if (S.on && !S.checked && S.canScan) check(input, status);
    };

    // The list (no grid): after the page's title.
    $(function () {
        if (document.getElementById('wp-media-grid')) return;
        const title = document.querySelector('.wrap .page-title-action') || document.querySelector('.wrap h1');
        if (!title || document.querySelector('.wmu-scan-box')) return;
        const box = document.createElement('span');
        box.className = 'wmu-scan-box';
        title.after(box);
        window.wmuSwitchBox(box);
    });
})(jQuery);
