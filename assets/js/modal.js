/**
 * The Duplicates window (user's request, 2026-11-20: a modal; each copy's thumbnail beside where it is used, those places edited
 * by hand): opened by the button beside Mark duplicate files (window.wmuOpenDuplicates, scan.js). Its content from admin-ajax
 * wmu_dupes_list (WMU_Modal); its links open in a new tab, so the window stays open while a page is edited. While the usage
 * check runs in the background (scan.js wmuFindUsage) it says so, and shows the places once it is done; "Check where they are
 * used again" runs it afresh (after editing).
 * Closed by ✕, Esc or a click beside it.
 */
(function ($) {
    'use strict';
    const S = window.wmuMedia;
    if (!S) return;
    const L = S.l10n;
    let $modal = null, lastFocus = null;

    function build() {
        $modal = $('<div class="wmu-modal" role="dialog" aria-modal="true" aria-labelledby="wmu-modal-title" hidden>'
            + '<div class="wmu-modal__backdrop"></div><div class="wmu-modal__dialog">'
            + '<header class="wmu-modal__head"><h2 id="wmu-modal-title">' + _.escape(L.modalTitle) + '</h2>'
            + '<button type="button" class="button wmu-modal__refresh">' + _.escape(L.refresh) + '</button>'
            + '<button type="button" class="wmu-modal__close" aria-label="' + _.escape(L.close) + '"><span class="dashicons dashicons-no-alt"></span></button></header>'
            + '<p class="wmu-modal__help">' + _.escape(L.modalHelp) + '</p><p class="wmu-modal__status" aria-live="polite"></p>'
            + '<div class="wmu-modal__body"></div></div></div>').appendTo(document.body);
        $modal.on('click', '.wmu-modal__close, .wmu-modal__backdrop', close);
        $modal.on('click', '.wmu-modal__refresh', () => { if (S.canScan) window.wmuFindUsage(); });
        $modal.on('click', '.wmu-delete', function () { remove(this); });
        $modal.on('keydown', e => { if (e.key === 'Escape') { e.stopPropagation(); close(); } });
        $(document).on('wmu:usage-progress', (e, text) => { status(L.findingUsage + (text ? ' ' + text : '')); $modal.find('.wmu-modal__refresh').prop('disabled', true); })
            .on('wmu:usage', () => { status(''); load(); })
            .on('wmu:usage-failed', () => { status(L.failed); })
            .on('wmu:usage wmu:usage-failed', () => { $modal.find('.wmu-modal__refresh').prop('disabled', false); });
        if (!S.canScan) $modal.find('.wmu-modal__refresh').remove();
    }
    function status(text) { $modal.find('.wmu-modal__status').text(text).prop('hidden', !text); }

    function load() {
        const $body = $modal.find('.wmu-modal__body');
        if (!$body.children().length) $body.html('<p class="wmu-muted">' + _.escape(L.loading) + '</p>');
        $.post(S.ajax, {action:'wmu_dupes_list', nonce:S.nonce}).done(res => {
            if (!res || !res.success) { $body.html('<p>' + _.escape(L.failed) + '</p>'); return; }
            show(res.data.html);
        }).fail(() => { $body.html('<p>' + _.escape(L.failed) + '</p>'); });
    }

    // Delete permanently (under a copy's usage): confirmed (warned if it is used), then the groups again; the page reloads
    // when the window closes, so the library shows the file gone.
    let deleted = false;
    function remove(button) {
        if (!window.confirm(button.dataset.used === '1' ? L.confirmDeleteUsed : L.confirmDelete)) return;
        const $copy = $(button).closest('.wmu-copy').addClass('wmu-copy--busy');
        button.disabled = true;
        $.post(S.ajax, {action:'wmu_delete', nonce:S.nonce, id:button.dataset.id}).done(res => {
            if (!res || !res.success) { fail(); return; }
            deleted = true;
            show(res.data.html);
        }).fail(fail);
        function fail() { $copy.removeClass('wmu-copy--busy'); button.disabled = false; window.alert(L.deleteFailed); }
    }

    function show(html) {
        const $body = $modal.find('.wmu-modal__body').html(html);
        $body.find('a').attr({target:'_blank', rel:'noopener'});
    }

    function close() {
        $modal.prop('hidden', true);
        document.body.classList.remove('wmu-modal-open');
        if (deleted) { window.location.reload(); return; }
        if (lastFocus) lastFocus.focus();
    }

    window.wmuOpenDuplicates = function () {
        if (!$modal) build();
        lastFocus = document.activeElement;
        $modal.prop('hidden', false);
        document.body.classList.add('wmu-modal-open');
        status(S.canScan && !S.parts.dupes.usageFresh ? L.findingUsage : '');
        load();
        $modal.find('.wmu-modal__close').trigger('focus');
    };
})(jQuery);
