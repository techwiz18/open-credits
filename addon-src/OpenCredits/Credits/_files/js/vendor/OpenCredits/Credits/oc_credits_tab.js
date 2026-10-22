(function () {
    'use strict';

    // Keep the profile Credits tab in place: clicking a page number or a
    // currency switch re-loads the tab pane via AJAX instead of leaving the
    // profile.
    XF.onDelegated(document, 'click', 'li[data-oc-credits-pane] .pagenav a, li[data-oc-credits-pane] .oc-credits-switch', function (e) {
        e.preventDefault();

        const link = this;
        const url = link.getAttribute('href');
        const pane = link.closest('li[data-oc-credits-pane]');
        if (!url || !pane) {
            return;
        }

        XF.ajax('get', url, {}, function (data) {
            if (data && data.html) {
                XF.setupHtmlInsert(data.html, pane);
            }
        });
    });
})();