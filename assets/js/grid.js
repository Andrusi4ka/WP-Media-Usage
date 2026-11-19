/**
 * WP Media Usage in the Media Library's grid (wp.media's Manage frame): the switch (scan.js) after Bulk select, and while it is
 * on an "Unused" mark on each thumbnail, the filters Unused and Ignored, Ignore selected / Stop ignoring selected in Bulk select.
 * The data: each attachment's `wmu` (WMU_Library::for_js()). WordPress's views are patched on their prototypes: core keeps
 * references to them in its defaults, so replacing them would change nothing.
 */
(function ($) {
    'use strict';
    const S = window.wmuMedia, media = window.wp && window.wp.media;
    if (!S || !media || !media.view || !media.view.Attachment || !media.view.Attachment.Library) return;
    const L = S.l10n;

    // The mark on a thumbnail: Unused (not ignored); redrawn when the attachment's wmu changes (Ignore in its details).
    function markThumbnails() {
        const Attachment = media.view.Attachment, Library = media.view.Attachment.Library;
        const mark = view => {
            const w = view.model.get('wmu');
            view.$('.wmu-badge').remove();
            if (w && w.state === 'unused' && !w.ignored) view.$('.attachment-preview').append('<span class="wmu-badge">' + _.escape(L.unused) + '</span>');
        };
        Library.prototype.initialize = function () {
            Attachment.prototype.initialize.apply(this, arguments);
            this.listenTo(this.model, 'change:wmu', function () { mark(this); });
        };
        Library.prototype.render = function () {
            Attachment.prototype.render.apply(this, arguments);
            mark(this);
            return this;
        };
    }

    // The filters: Unused and Ignored after WordPress's own; every other filter clears ours (wmu: null).
    function addFilters() {
        const All = media.view.AttachmentFilters.All, createFilters = All.prototype.createFilters;
        All.prototype.createFilters = function () {
            createFilters.apply(this, arguments);
            _.each(this.filters, filter => { filter.props.wmu = null; });
            const base = {status:null, type:null, uploadedTo:null, orderby:'date', order:'DESC', author:null};
            this.filters.wmu_unused = {text:L.filterUnused, props:_.extend({}, base, {wmu:'unused'}), priority:60};
            this.filters.wmu_ignored = {text:L.filterIgnored, props:_.extend({}, base, {wmu:'ignored'}), priority:61};
        };
    }

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
            const selection = this.controller.state().get('selection');
            if (!selection.length) return;
            const models = selection.models.slice();
            this.model.set('disabled', true);
            $.post(S.ajax, {action:'wmu_ignore', nonce:S.nonce, ignore:this.undo() ? 0 : 1, ids:_.pluck(models, 'id')})
                .done(res => { if (res && res.success) this.done(models, res.data); })
                .always(() => this.refresh());
        },
        // The files' new marks; under a filter of ours they leave the list; Bulk select ends.
        done: function (models, marks) {
            const controller = this.controller;
            models.forEach(m => { if (marks[m.id]) m.set('wmu', marks[m.id]); });
            if (this.options.props.get('wmu')) controller.state().get('library')._requery(true);
            controller.state().get('selection').reset();
            controller.trigger('selection:action:done');
        },
        render: function () {
            this.model.set('size', '', {silent:true});
            Button.prototype.render.apply(this, arguments);
            this.$el.addClass('wmu-ignore-selected').toggleClass('hidden', !this.controller.isModeActive('select'));
            return this;
        }
    });

    // The toolbar: Ignore selected beside the delete button (-80, while on); the switch after Bulk select (-70): not a
    // media-button, so Bulk select hides it with the filters.
    function addToolbarItems() {
        const Browser = media.view.AttachmentsBrowser, createToolbar = Browser.prototype.createToolbar;
        Browser.prototype.createToolbar = function () {
            createToolbar.apply(this, arguments);
            if (!this.controller.isModeActive('grid')) return;
            if (S.on) this.toolbar.set('wmuIgnore', new IgnoreButton({controller:this.controller, props:this.collection.props, text:L.ignore, disabled:true, priority:-79}).render());
            const box = new media.View({className:'wmu-scan-box wmu-scan-box--toolbar', priority:-65});
            if (window.wmuSwitchBox) window.wmuSwitchBox(box.el);
            this.toolbar.set('wmuSwitch', box);
        };
    }

    // Off: only the switch.
    if (S.on) { markThumbnails(); addFilters(); }
    addToolbarItems();
})(jQuery);
