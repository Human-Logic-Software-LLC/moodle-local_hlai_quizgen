// Minimal AMD module for AI Quiz Generator wizard to avoid 404 errors.
// Provides a no-op init so the page can load without JS errors.
define(['jquery'], function($) {
    'use strict';

    return {
        init: function(courseid, requestid, step) {
            // Placeholder - wizard functionality handled server-side.
        }
    };
});
