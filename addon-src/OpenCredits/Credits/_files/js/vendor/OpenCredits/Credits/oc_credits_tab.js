(function () {
    'use strict';

    // Keep the profile Credits tab's wallet links inside the pane.
    XF.onDelegated(document, 'click', 'li[data-oc-credits-pane] .pageNav a, li[data-oc-credits-pane] .pageNavSimple a, li[data-oc-credits-pane] .oc-credits-per-page, li[data-oc-credits-pane] .oc-credits-switch', function (e) {
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