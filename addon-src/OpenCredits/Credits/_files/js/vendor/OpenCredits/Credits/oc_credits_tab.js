(function () {
    'use strict';

    // Keep the profile Credits tab's pagination in place: clicking a page
    // number re-loads the tab pane via AJAX instead of leaving the profile.
    XF.on(document, 'click', 'li[data-oc-credits-pane] .pagenav a', function (e) {
        e.preventDefault();

        const link = e.currentTarget;
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