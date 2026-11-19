/**
 * WP Media Usage in the Media Library (upload.php): the Check usage button by the page's title (both views), and in the grid
 * an "Unused" mark on each thumbnail, the filters Unused and Ignored, and Ignore selected / Stop ignoring selected beside
 * Bulk select's delete button. The data: each attachment's `wmu` (WMU_Media::for_js()), the strings: window.wmuMedia.
 */
(function ($) {
    'use strict';
    const S = window.wmuMedia;
    if (!S) return;
    const L = S.l10n;

    /* Check usage: a button after the page's title, the last check beside it; runs the scan a few seconds a request until done. */
    function scanButton() {
        const title = document.querySelector('.wrap .page-title-action') || document.querySelector('.wrap h1');
        if (!title || document.querySelector('.wmu-scan')) return;
        const box = document.createElement('span');
        box.className = 'wmu-scan-box';
        box.innerHTML = (S.canScan ? '<button type="button" class="page-title-action wmu-scan"></button>' : '') + '<span class="wmu-status"></span>';
        title.after(box);
        const button = box.querySelector('.wmu-scan'), status = box.querySelector('.wmu-status');
        status.textContent = S.last;
        if (!button) return;
        button.textContent = L.check;
        button.title = L.checkTip;
        const ask = start => $.post(S.ajax, {action:'wmu_scan', nonce:S.nonce, start:start ? 1 : 0});
        const step = start => ask(start).done(res => {
            if (!res || !res.success) { fail(); return; }
            const d = res.data;
            if (d.done) { status.textContent = d.last; window.location.reload(); return; }
            status.textContent = L.checking + ' ' + (L.phases[d.phase] || d.phase) + (d.phase === 'resolve' ? ' (' + d.checked + ')' : '');
            step(false);
        }).fail(fail);
        const fail = () => { button.disabled = false; status.textContent = L.failed; };
        button.addEventListener('click', () => {
            button.disabled = true;
            status.textContent = L.checking;
            step(true);
        });
    }
    $(scanButton);

    /* The grid (wp.media's Manage frame): only where its views are loaded. */
    const media = window.wp && window.wp.media;
    if (!media || !media.view || !media.view.Attachment || !media.view.Attachment.Library) return;

    // The mark on a thumbnail: Unused (not ignored); redrawn when the attachment's wmu changes (Ignore in its details).
    const Attachment = media.view.Attachment, Library = media.view.Attachment.Library;
    function mark(view) {
        const w = view.model.get('wmu');
        view.$('.wmu-badge').remove();
        if (w && w.state === 'unused' && !w.ignored) view.$('.attachment-preview').append('<span class="wmu-badge">' + _.escape(L.unused) + '</span>');
    }
    Library.prototype.initialize = function () {
        Attachment.prototype.initialize.apply(this, arguments);
        this.listenTo(this.model, 'change:wmu', function () { mark(this); });
    };
    Library.prototype.render = function () {
        Attachment.prototype.render.apply(this, arguments);
        mark(this);
        return this;
    };

    // The filters: Unused and Ignored after WordPress's own; every other filter clears ours (wmu: null).
    const All = media.view.AttachmentFilters.All, createFilters = All.prototype.createFilters;
    All.prototype.createFilters = function () {
        createFilters.apply(this, arguments);
        _.each(this.filters, filter => { filter.props.wmu = null; });
        const base = {status:null, type:null, uploadedTo:null, orderby:'date', order:'DESC', author:null};
        this.filters.wmu_unused = {text:L.filterUnused, props:_.extend({}, base, {wmu:'unused'}), priority:60};
        this.filters.wmu_ignored = {text:L.filterIgnored, props:_.extend({}, base, {wmu:'ignored'}), priority:61};
    };

    // Ignore selected (Stop ignoring selected under the Ignored filter): shown in Bulk select, beside its delete button.
    const Button = media.view.Button;
    const IgnoreButton = Button.extend({
        initialize: function () {
            Button.prototype.initialize.apply(this, arguments);
            this.controller.on('selection:toggle select:activate select:deactivate', this.refresh, this);
            this.options.props.on('change:wmu', this.refresh, this);
        },
        undo: function () { return this.options.props.get('wmu') === 'ignored'; },
        refresh: function () {
            const selection = this.controller.state().get('selection');
            this.model.set({disabled:!selection || !selection.length, text:this.undo() ? L.unignore : L.ignore});
            this.render();
        },
        click: function () {
            const controller = this.controller, selection = controller.state().get('selection'), library = controller.state().get('library');
            if (!selection.length) return;
            const ignore = !this.undo(), models = selection.models.slice();
            this.model.set('disabled', true);
            $.post(S.ajax, {action:'wmu_ignore', nonce:S.nonce, ignore:ignore ? 1 : 0, ids:_.pluck(models, 'id')}).done(res => {
                if (!res || !res.success) return;
                models.forEach(m => { if (res.data[m.id]) m.set('wmu', res.data[m.id]); });
                // Under a filter of ours they leave the list.
                if (this.options.props.get('wmu')) library._requery(true);
                selection.reset();
                controller.trigger('selection:action:done');
            }).always(() => this.refresh());
        },
        render: function () {
            this.model.set('size', '', {silent:true});
            Button.prototype.render.apply(this, arguments);
            this.$el.addClass('wmu-ignore-selected').toggleClass('hidden', !this.controller.isModeActive('select'));
            return this;
        }
    });
    const Browser = media.view.AttachmentsBrowser, createToolbar = Browser.prototype.createToolbar;
    Browser.prototype.createToolbar = function () {
        createToolbar.apply(this, arguments);
        if (!this.controller.isModeActive('grid')) return;
        this.toolbar.set('wmuIgnore', new IgnoreButton({
            controller:this.controller, props:this.collection.props, text:L.ignore, disabled:true, priority:-79
        }).render());
    };
})(jQuery);
