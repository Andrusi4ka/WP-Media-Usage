/**
 * WP Media Usage's checkboxes: Mark unused files (user's request, 2026-11-19: a checkbox as WP Banner's instead of the Check
 * usage button) and Mark duplicate files (2026-11-20). Ticked: saved, that check run at once (admin-ajax wmu_scan with its part,
 * a few seconds a request, its progress beside it), then the page again with the marks. Unticked: saved, the page again
 * without them. In the list view after the page's title; in the grid grid.js puts them in the toolbar after Bulk select,
 * through window.wmuSwitchBox.
 * Strings and state: window.wmuMedia (WMU_Assets), each part's in its parts[part].
 */
(function ($) {
    'use strict';
    const S = window.wmuMedia;
    if (!S) return;
    const L = S.l10n;

    // A part's check run to its end: its requests one after another; progress(text) after each answer. A promise (the last check in words).
    function run(part, progress) {
        const P = S.parts[part], d = $.Deferred();
        const step = start => $.post(S.ajax, {action:'wmu_scan', nonce:S.nonce, part:part, start:start ? 1 : 0}).done(res => {
            if (!res || !res.success) { d.reject(); return; }
            const r = res.data;
            if (r.done) { d.resolve(r.last); return; }
            const counted = r.phase === 'resolve' || r.phase === 'hash';
            progress((P.phases[r.phase] || r.phase) + (counted ? ' (' + r.checked + ')' : ''));
            step(false);
        }).fail(() => d.reject());
        step(true);
        return d.promise();
    }

    // A part's check from its checkbox: progress beside it; done, the page again with the marks.
    function check(part, input, status) {
        input.disabled = true;
        status.textContent = L.checking;
        run(part, text => { status.textContent = L.checking + ' ' + text; })
            .done(last => { status.textContent = last; window.location.reload(); })
            .fail(() => { input.disabled = false; status.textContent = L.failed; });
    }

    /*
     * Where each duplicate is used: the usage check, in the background (no reload, its own checkbox left as it is), while Mark
     * duplicate files is ticked and the usage was not checked after the duplicates; again from the Duplicates window. Once at a
     * time; its progress as 'wmu:usage-progress' (text), done as 'wmu:usage' on the document (the window shows the places).
     */
    let usageRun = null;
    window.wmuFindUsage = function () {
        if (usageRun) return usageRun;
        $(document).trigger('wmu:usage-progress', ['']);
        usageRun = run('unused', text => { $(document).trigger('wmu:usage-progress', [text]); })
            .done(() => { S.parts.dupes.usageFresh = true; $(document).trigger('wmu:usage'); })
            .fail(() => { $(document).trigger('wmu:usage-failed'); })
            .always(() => { usageRun = null; });
        return usageRun;
    };

    // Ticked: saved, then checked. Unticked: saved, then the page without the marks.
    function turn(part, input, status) {
        const on = input.checked;
        input.disabled = true;
        $.post(S.ajax, {action:'wmu_switch', nonce:S.nonce, part:part, on:on ? 1 : 0}).done(res => {
            if (!res || !res.success) { input.disabled = false; input.checked = !on; return; }
            if (on) check(part, input, status); else window.location.reload();
        }).fail(() => { input.disabled = false; input.checked = !on; });
    }

    // One part: its checkbox (for those who may tick it), its "i" (what it does, as WP Banner's) and its last check (while ticked).
    function partHtml(P) {
        return '<span class="wmu-part">' + (S.canSwitch ? '<label class="wmu-switch"><input type="checkbox"' + (P.on ? ' checked' : '')
            + '><span class="wmu-switch__track" aria-hidden="true"></span><span>' + _.escape(P.label) + '</span></label>'
            + '<span class="wmu-tip dashicons dashicons-info-outline" tabindex="0" role="note" aria-label="' + _.escape(P.tip) + '" data-tip="' + _.escape(P.tip) + '"></span>' : '')
            + '<span class="wmu-status"></span>'
            // Duplicates: the button that opens their window (modal.js), while ticked and some were found.
            + (P === S.parts.dupes && P.on && P.groups ? '<button type="button" class="button wmu-show-dupes">' + _.escape(L.showDuplicates.replace('%d', P.groups)) + '</button>' : '')
            + '</span>';
    }

    // Beside Mark duplicate files: the background usage check's progress, then its last check again.
    function showUsage(status) {
        const P = S.parts.dupes;
        $(document).on('wmu:usage-progress', (e, text) => { status.textContent = L.findingUsage + (text ? ' ' + text : ''); })
            .on('wmu:usage wmu:usage-failed', () => { status.textContent = P.last; });
    }

    // The checkboxes in box. A ticked one whose check never finished (the page was left while it ran) finishes it now: the
    // first such one; the page then opens again and finishes the next.
    window.wmuSwitchBox = function (box) {
        const parts = Object.keys(S.parts);
        box.innerHTML = parts.map(part => partHtml(S.parts[part])).join('');
        let resumed = false;
        box.querySelectorAll('.wmu-part').forEach((el, i) => {
            const part = parts[i], P = S.parts[part];
            const input = el.querySelector('.wmu-switch input'), status = el.querySelector('.wmu-status');
            const show = el.querySelector('.wmu-show-dupes');
            status.textContent = P.on ? P.last : '';
            if (show) show.addEventListener('click', () => { if (window.wmuOpenDuplicates) window.wmuOpenDuplicates(); });
            if (part === 'dupes' && P.on) showUsage(status);
            if (!input) return;
            input.addEventListener('change', () => turn(part, input, status));
            if (!resumed && P.on && !P.checked && S.canScan) { resumed = true; check(part, input, status); }
        });
        // Nothing left unfinished: where the duplicates are used, in the background, if not checked since they were found.
        const D = S.parts.dupes;
        if (!resumed && D.on && D.checked && D.groups && !D.usageFresh && S.canScan) window.wmuFindUsage();
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
